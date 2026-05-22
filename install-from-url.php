<?php
/**
 * GitUp — Installation från GitHub-URL
 * ------------------------------------
 *
 * Modulen är medvetet fristående från `gitup-updater.php` och `options.php`
 * så att den enkelt kan backportas till äldre kodbaser (t.ex. `rg-git-updater`)
 * med en mekanisk find-and-replace av prefixet.
 *
 * Publika funktioner i den här filen ska bara förlita sig på andra `gitup_*`-
 * hjälpare som finns i båda kodbaserna (normalize, build_package_url, get_releases,
 * validate_release_package_selection, repo_visibility, redirect_with_notice m.fl.).
 */

if (!function_exists('gitup_parse_install_source')) {
    /**
     * Tolkar och normaliserar en GitHub-källa som användaren matar in i
     * "Installera från URL"-formuläret.
     *
     * Accepterade former:
     *   - `https://github.com/owner/repo`            → repo, ingen tagg
     *   - `http://github.com/owner/repo`             → uppgraderas till https
     *   - `https://github.com/owner/repo/`           → trailing slash tillåten
     *   - `https://github.com/owner/repo.git`        → `.git` strippas
     *   - `https://github.com/owner/repo/tree/<tag>` → tagg extraheras
     *   - `https://github.com/owner/repo/releases/tag/<tag>` → tagg extraheras
     *   - `https://github.com/owner/repo/archive/refs/tags/<tag>.zip` → tagg extraheras
     *   - `owner/repo`                               → tolkas som github.com
     *
     * Avvisas:
     *   - tom/ogiltig sträng
     *   - andra hosts (gist, raw, codeload, github enterprise)
     *   - SSH-URL:er (`git@github.com:...`)
     *   - paths som inte matchar mönstren ovan (wiki, blob, issues osv.)
     *
     * @param mixed $input
     * @return array{repo_url:string,tag:?string}|WP_Error
     */
    function gitup_parse_install_source($input) {
        if (!is_string($input)) {
            return new WP_Error('gitup_invalid_url', __('Repository URL is required.', 'gitup'));
        }

        $input = trim($input);
        if ($input === '') {
            return new WP_Error('gitup_invalid_url', __('Repository URL is required.', 'gitup'));
        }

        // SSH-form: git@github.com:owner/repo(.git)
        if (preg_match('#^(?:git@|ssh://)#i', $input)) {
            return new WP_Error(
                'gitup_invalid_url',
                __('SSH URLs are not supported. Use https://github.com/owner/repo.', 'gitup')
            );
        }

        // Bare `owner/repo`: ingen scheme, ingen host, en enda slash, bara safe-tecken.
        if (preg_match('#^[A-Za-z0-9_.\-]+/[A-Za-z0-9_.\-]+$#', $input)) {
            $input = 'https://github.com/' . $input;
        }

        // Lägg på https:// om scheme saknas men host ser ut att vara github.com.
        if (!preg_match('#^[a-z][a-z0-9+\-.]*://#i', $input)) {
            $input = 'https://' . ltrim($input, '/');
        }

        $parts = parse_url($input);
        if (!is_array($parts) || empty($parts['host']) || empty($parts['path'])) {
            return new WP_Error('gitup_invalid_url', __('Could not parse the URL.', 'gitup'));
        }

        $host = strtolower((string) $parts['host']);
        if (!in_array($host, ['github.com', 'www.github.com'], true)) {
            return new WP_Error(
                'gitup_invalid_url',
                __('Only github.com URLs are supported.', 'gitup')
            );
        }

        $segments = array_values(array_filter(
            explode('/', (string) $parts['path']),
            static function ($segment) {
                return $segment !== '';
            }
        ));

        if (count($segments) < 2) {
            return new WP_Error(
                'gitup_invalid_url',
                __('URL must include both owner and repository.', 'gitup')
            );
        }

        $owner = $segments[0];
        $repo = preg_replace('/\.git$/i', '', $segments[1]);
        if ($owner === '' || $repo === '' || $repo === null) {
            return new WP_Error('gitup_invalid_url', __('Invalid repository path.', 'gitup'));
        }

        $repo_url = 'https://github.com/' . $owner . '/' . $repo;
        $tag = null;
        $rest = array_slice($segments, 2);

        if ($rest !== []) {
            if (count($rest) >= 2 && $rest[0] === 'tree') {
                $tag = implode('/', array_slice($rest, 1));
            } elseif (count($rest) >= 3 && $rest[0] === 'releases' && $rest[1] === 'tag') {
                $tag = implode('/', array_slice($rest, 2));
            } elseif (count($rest) >= 4 && $rest[0] === 'archive' && $rest[1] === 'refs' && $rest[2] === 'tags') {
                $tag = implode('/', array_slice($rest, 3));
                $tag = preg_replace('/\.zip$/i', '', (string) $tag);
            } else {
                return new WP_Error(
                    'gitup_invalid_url',
                    __('Unsupported GitHub URL form.', 'gitup')
                );
            }

            if ($tag === '' || $tag === null) {
                return new WP_Error(
                    'gitup_invalid_url',
                    __('Could not extract a tag from the URL.', 'gitup')
                );
            }
        }

        return [
            'repo_url' => $repo_url,
            'tag'      => $tag,
        ];
    }
}

if (!function_exists('gitup_detect_repo_component_type')) {
    /**
     * Probe-anrop mot GitHub Contents API för att gissa om ett repo innehåller
     * ett plugin, ett tema, båda eller inget — vid en given tagg/ref.
     *
     * Strategin är att först lista filer i root, sen plocka ut style.css
     * och upp till tre PHP-kandidater (prefererar `<repo>.php` och `index.php`)
     * och kolla efter `Theme Name:` respektive `Plugin Name:`-header.
     *
     * Returnerar `WP_Error` vid 404/401/403/HTTP-fel eller parse-fel,
     * annars en array:
     *   - `type` → `'plugin'|'theme'|'both'|'none'`
     *   - `plugin_name` → string|null
     *   - `theme_name`  → string|null
     *
     * @param string      $repo_url
     * @param string|null $tag
     * @return array{type:string,plugin_name:?string,theme_name:?string}|WP_Error
     */
    function gitup_detect_repo_component_type($repo_url, $tag = null) {
        $normalized = gitup_normalize_github_repo_url((string) $repo_url);
        if ($normalized === '') {
            return new WP_Error('gitup_invalid_url', __('Invalid repository URL.', 'gitup'));
        }

        $repo_path = (string) parse_url($normalized, PHP_URL_PATH);
        if ($repo_path === '') {
            return new WP_Error('gitup_invalid_url', __('Invalid repository URL.', 'gitup'));
        }

        $contents_url = 'https://api.github.com/repos' . $repo_path . '/contents';
        if (is_string($tag) && $tag !== '') {
            $contents_url .= '?ref=' . rawurlencode($tag);
        }

        $response = wp_remote_get($contents_url, [
            'headers'     => gitup_github_headers(),
            'timeout'     => 15,
            'redirection' => 3,
        ]);

        if (is_wp_error($response)) {
            return new WP_Error(
                'gitup_detect_http_error',
                __('Could not reach the GitHub Contents API.', 'gitup')
            );
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code === 404) {
            return new WP_Error(
                'gitup_detect_not_found',
                __('Repository or tag could not be found on GitHub.', 'gitup')
            );
        }
        if ($code === 401 || $code === 403) {
            return new WP_Error(
                'gitup_detect_auth',
                __('GitHub returned an authentication or rate-limit error.', 'gitup')
            );
        }
        if ($code < 200 || $code >= 300) {
            return new WP_Error(
                'gitup_detect_http_error',
                __('Unexpected response from the GitHub Contents API.', 'gitup')
            );
        }

        $entries = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($entries)) {
            return new WP_Error(
                'gitup_detect_parse',
                __('Could not parse the GitHub Contents response.', 'gitup')
            );
        }

        $files_at_root = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (($entry['type'] ?? '') !== 'file') {
                continue;
            }
            $name = (string) ($entry['name'] ?? '');
            if ($name !== '') {
                $files_at_root[$name] = true;
            }
        }

        $theme_name = null;
        if (isset($files_at_root['style.css'])) {
            $content = gitup_install_from_url_fetch_file($repo_path, 'style.css', $tag);
            if (is_string($content) && preg_match('/^\s*Theme\s*Name\s*:\s*(.+)$/mi', $content, $m)) {
                $theme_name = trim($m[1]);
            }
        }

        $repo_basename = basename($repo_path);
        $php_candidates = array_filter(array_keys($files_at_root), static function ($name) {
            return (bool) preg_match('/\.php$/i', (string) $name);
        });

        usort($php_candidates, static function ($a, $b) use ($repo_basename) {
            $score = static function ($name) use ($repo_basename) {
                if (strcasecmp($name, $repo_basename . '.php') === 0) {
                    return 0;
                }
                if (strcasecmp($name, 'index.php') === 0) {
                    return 1;
                }
                return 2;
            };
            $sa = $score($a);
            $sb = $score($b);
            if ($sa !== $sb) {
                return $sa - $sb;
            }
            return strcasecmp($a, $b);
        });

        $plugin_name = null;
        foreach (array_slice($php_candidates, 0, 3) as $php_name) {
            $content = gitup_install_from_url_fetch_file($repo_path, $php_name, $tag);
            if (is_string($content) && preg_match('/^[\s\/\*]*Plugin\s*Name\s*:\s*(.+)$/mi', $content, $m)) {
                $plugin_name = trim($m[1]);
                break;
            }
        }

        $type = 'none';
        if ($plugin_name !== null && $theme_name !== null) {
            $type = 'both';
        } elseif ($plugin_name !== null) {
            $type = 'plugin';
        } elseif ($theme_name !== null) {
            $type = 'theme';
        }

        return [
            'type'        => $type,
            'plugin_name' => $plugin_name,
            'theme_name'  => $theme_name,
        ];
    }
}

if (!function_exists('gitup_install_from_url_fetch_file')) {
    /**
     * Intern hjälpare: hämtar råinnehåll för en enskild fil i ett repo
     * via Contents-API:t med `Accept: application/vnd.github.raw`.
     *
     * Returnerar fil-innehållet vid 200, annars `null` (callern tolkar
     * "kunde inte avgöra" som "header saknas").
     *
     * @param string      $repo_path  Med ledande slash, t.ex. `/owner/repo`.
     * @param string      $file_path  Filsökväg relativt repo-roten.
     * @param string|null $tag        Frivillig ref/tag.
     * @return string|null
     */
    function gitup_install_from_url_fetch_file($repo_path, $file_path, $tag = null) {
        $url = 'https://api.github.com/repos' . $repo_path . '/contents/' . ltrim((string) $file_path, '/');
        if (is_string($tag) && $tag !== '') {
            $url .= '?ref=' . rawurlencode($tag);
        }

        $headers = gitup_github_headers();
        $headers['Accept'] = 'application/vnd.github.raw';

        $response = wp_remote_get($url, [
            'headers'     => $headers,
            'timeout'     => 15,
            'redirection' => 3,
        ]);

        if (is_wp_error($response)) {
            return null;
        }
        if ((int) wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        return is_string($body) ? $body : null;
    }
}

if (!function_exists('gitup_prepare_plugin_install_from_url')) {
    /**
     * Förbereder en plugin-installation från en GitHub-URL.
     *
     * Normaliserar repo-URL:n, verifierar att taggen faktiskt finns i repo:ts
     * releases (via befintliga `gitup_validate_release_package_selection`) och
     * härleder ett destination-slug (default = repo-namnet, sanerat med
     * `sanitize_key`).
     *
     * Returnerar `WP_Error` vid ogiltig input eller overifierad tagg, annars
     * en array med `repo_url`, `tag`, `package`, `releases`, `valid_tags` och
     * `desired_slug`.
     *
     * @param string      $repo_url
     * @param string      $tag
     * @param string|null $desired_slug
     * @return array|WP_Error
     */
    function gitup_prepare_plugin_install_from_url($repo_url, $tag, $desired_slug = null) {
        $normalized = gitup_normalize_github_repo_url((string) $repo_url);
        if ($normalized === '') {
            return new WP_Error('gitup_invalid_url', __('Invalid repository URL.', 'gitup'));
        }
        if (!is_string($tag) || $tag === '') {
            return new WP_Error('gitup_missing_tag', __('A release tag is required.', 'gitup'));
        }

        $validation = gitup_validate_release_package_selection($normalized, $tag);
        if (is_wp_error($validation)) {
            return new WP_Error(
                'gitup_install_release_not_verified',
                __('Selected release could not be verified against the repository tags.', 'gitup')
            );
        }

        $slug_input = is_string($desired_slug) && $desired_slug !== ''
            ? $desired_slug
            : basename((string) parse_url($normalized, PHP_URL_PATH));
        $slug = sanitize_key($slug_input);
        if ($slug === '') {
            return new WP_Error(
                'gitup_invalid_slug',
                __('Could not determine a destination directory name for the plugin.', 'gitup')
            );
        }

        return [
            'repo_url'     => $normalized,
            'tag'          => $tag,
            'package'      => $validation['package'],
            'releases'     => $validation['releases'],
            'valid_tags'   => $validation['valid_tags'],
            'desired_slug' => $slug,
        ];
    }
}

if (!function_exists('gitup_prepare_theme_install_from_url')) {
    /**
     * Förbereder en tema-installation från en GitHub-URL.
     *
     * Samma kontrakt som plugin-varianten, men returnerar `desired_stylesheet`
     * i stället för `desired_slug`. Den existerande `upgrader_source_selection`-
     * hooken letar upp och döper om mappen så att den matchar stylesheet:en.
     *
     * @param string      $repo_url
     * @param string      $tag
     * @param string|null $desired_stylesheet
     * @return array|WP_Error
     */
    function gitup_prepare_theme_install_from_url($repo_url, $tag, $desired_stylesheet = null) {
        $normalized = gitup_normalize_github_repo_url((string) $repo_url);
        if ($normalized === '') {
            return new WP_Error('gitup_invalid_url', __('Invalid repository URL.', 'gitup'));
        }
        if (!is_string($tag) || $tag === '') {
            return new WP_Error('gitup_missing_tag', __('A release tag is required.', 'gitup'));
        }

        $validation = gitup_validate_release_package_selection($normalized, $tag);
        if (is_wp_error($validation)) {
            return new WP_Error(
                'gitup_install_release_not_verified',
                __('Selected release could not be verified against the repository tags.', 'gitup')
            );
        }

        $stylesheet_input = is_string($desired_stylesheet) && $desired_stylesheet !== ''
            ? $desired_stylesheet
            : basename((string) parse_url($normalized, PHP_URL_PATH));
        $stylesheet = sanitize_key($stylesheet_input);
        if ($stylesheet === '') {
            return new WP_Error(
                'gitup_invalid_stylesheet',
                __('Could not determine a destination directory name for the theme.', 'gitup')
            );
        }

        return [
            'repo_url'           => $normalized,
            'tag'                => $tag,
            'package'            => $validation['package'],
            'releases'           => $validation['releases'],
            'valid_tags'         => $validation['valid_tags'],
            'desired_stylesheet' => $stylesheet,
        ];
    }
}

if (!function_exists('gitup_build_plugin_install_package_options_filter')) {
    /**
     * Bygger ett `upgrader_package_options`-filter för förstainstallation av
     * ett plugin via URL. Skiljer sig från den befintliga update-varianten
     * genom att inte rensa destinationen och avbryta om mappen redan finns
     * (vi vill inte råka skriva över ett befintligt plugin).
     *
     * Sätter också `hook_extra['gitup_install_from_url']` så efterföljande
     * hooks kan se att det är en URL-installation.
     *
     * @param string $desired_slug Sanerat mappnamn under WP_PLUGIN_DIR.
     * @return callable
     */
    function gitup_build_plugin_install_package_options_filter($desired_slug) {
        $slug = (string) $desired_slug;

        return function ($options) use ($slug) {
            if (!is_array($options)) {
                $options = [];
            }
            if (!isset($options['hook_extra']) || !is_array($options['hook_extra'])) {
                $options['hook_extra'] = [];
            }

            $options['hook_extra']['plugin'] = $slug . '/' . $slug . '.php';
            $options['hook_extra']['gitup_install_from_url'] = true;

            if (defined('WP_PLUGIN_DIR')) {
                $plugins_dir = trailingslashit(WP_PLUGIN_DIR);
                $options['destination'] = trailingslashit($plugins_dir . $slug);
                $options['destination_name'] = $slug;
            }
            $options['clear_destination'] = false;
            $options['abort_if_destination_exists'] = true;

            return $options;
        };
    }
}

if (!function_exists('gitup_build_theme_install_package_options_filter')) {
    /**
     * Bygger ett `upgrader_package_options`-filter för förstainstallation av
     * ett tema via URL. Destination sätts till `get_theme_root()` och
     * `destination_name` lämnas bort så att den befintliga
     * `upgrader_source_selection`-hooken kan döpa om källkatalogen till
     * önskad stylesheet.
     *
     * @param string $desired_stylesheet
     * @return callable
     */
    function gitup_build_theme_install_package_options_filter($desired_stylesheet) {
        $stylesheet = (string) $desired_stylesheet;

        return function ($options) use ($stylesheet) {
            if (!is_array($options)) {
                $options = [];
            }
            if (!isset($options['hook_extra']) || !is_array($options['hook_extra'])) {
                $options['hook_extra'] = [];
            }

            $options['hook_extra']['theme'] = $stylesheet;
            $options['hook_extra']['gitup_install_from_url'] = true;
            $options['destination'] = get_theme_root();
            unset($options['destination_name']);
            $options['clear_destination'] = false;
            $options['abort_if_destination_exists'] = true;

            return $options;
        };
    }
}
