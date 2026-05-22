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
        if ($code === 403) {
            $body = (string) wp_remote_retrieve_body($response);
            if ($body !== '' && stripos($body, 'rate limit') !== false) {
                return new WP_Error(
                    'gitup_detect_rate_limited',
                    __('GitHub API rate limit reached. Configure a GitHub token in Settings or wait until the limit resets.', 'gitup')
                );
            }
            return new WP_Error(
                'gitup_detect_auth',
                __('GitHub returned an authentication error.', 'gitup')
            );
        }
        if ($code === 401) {
            return new WP_Error(
                'gitup_detect_auth',
                __('GitHub returned an authentication error.', 'gitup')
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
     * via GitHub Contents-API:t.
     *
     * Anropet använder standard-`Accept: application/vnd.github+json` och
     * dekoderar `content`-fältet (base64) från svaret. Det är robustare än
     * att försöka be om `application/vnd.github.raw` direkt — wp_remote_get
     * skickar inte alltid Accept-headern vidare exakt, vilket annars kan ge
     * en JSON-envelope som regexet inte matchar.
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

        $response = wp_remote_get($url, [
            'headers'     => gitup_github_headers(),
            'timeout'     => 15,
            'redirection' => 3,
        ]);

        if (is_wp_error($response)) {
            if (function_exists('gitup_log')) {
                gitup_log('install-from-url: file fetch wp_error for ' . $url);
            }
            return null;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            if (function_exists('gitup_log')) {
                gitup_log('install-from-url: file fetch http ' . $code . ' for ' . $url);
            }
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        if (!is_string($body) || $body === '') {
            return null;
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            // Vissa proxies eller helt råa svar (om GitHub honorerar Accept: raw)
            // kommer in som plain text. Returnera body som det är då.
            return $body;
        }

        $encoding = isset($data['encoding']) ? (string) $data['encoding'] : '';
        $content  = isset($data['content']) ? (string) $data['content'] : '';

        if ($encoding === 'base64' && $content !== '') {
            $decoded = base64_decode(preg_replace('/\s+/', '', $content), true);
            return is_string($decoded) ? $decoded : null;
        }

        // Okänd encoding men content finns — returnera som det är, regex får testa.
        return $content !== '' ? $content : null;
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

if (!function_exists('gitup_install_from_url_preview_transient_key')) {
    /**
     * Transient-nyckel för det aktuella preview-resultatet, scope:at per användare
     * så att två admins inte trampar varandra på samma flik.
     */
    function gitup_install_from_url_preview_transient_key() {
        $user_id = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        return 'gitup_install_preview_' . $user_id;
    }
}

if (!function_exists('gitup_run_install_from_url_preview')) {
    /**
     * Ren preview-logik: tar en GitHub-URL-input, parsar den, hämtar releaser
     * och kör typdetektering. Returnerar samma struktur som lagras i preview-
     * transientet (eller `WP_Error`).
     *
     * Den här funktionen har inget med $_POST/redirect att göra — admin-post-
     * handlern är en tunn wrapper runt den.
     *
     * @param string $url_input Rå inmatning från användaren.
     * @return array|WP_Error
     */
    function gitup_run_install_from_url_preview($url_input) {
        $parsed = gitup_parse_install_source($url_input);
        if (is_wp_error($parsed)) {
            return $parsed;
        }

        $repo_url = (string) $parsed['repo_url'];
        $url_tag  = $parsed['tag'];

        $include_pre = function_exists('gitup_should_include_prereleases')
            ? gitup_should_include_prereleases()
            : false;
        $releases = function_exists('gitup_fetch_releases')
            ? gitup_fetch_releases($repo_url, $include_pre, 50)
            : [];

        if (!empty($releases) && isset($releases[0]['error'])) {
            $code = $releases[0]['error'] === 'rate_limit'
                ? 'gitup_install_preview_rate_limited'
                : 'gitup_install_preview_releases_failed';
            return new WP_Error(
                $code,
                __('Could not load releases from GitHub for this repository.', 'gitup')
            );
        }

        $tag_used = $url_tag;
        if ($tag_used === null) {
            if (empty($releases)) {
                return new WP_Error(
                    'gitup_install_preview_no_releases',
                    __('No releases found for this repository.', 'gitup')
                );
            }
            $tag_used = (string) $releases[0]['tag'];
        }

        $detect = gitup_detect_repo_component_type($repo_url, $tag_used);
        if (is_wp_error($detect)) {
            return $detect;
        }

        return [
            'repo_url'    => $repo_url,
            'tag_used'    => (string) $tag_used,
            'releases'    => $releases,
            'type'        => $detect['type'],
            'plugin_name' => $detect['plugin_name'],
            'theme_name'  => $detect['theme_name'],
        ];
    }
}

if (!function_exists('gitup_resolve_install_from_url_confirm_request')) {
    /**
     * Validerar och förbereder en bekräftad install-from-URL-förfrågan.
     *
     * Skiljer pure logic från $_POST/upgrader-glue — admin-post-handlern är
     * en tunn wrapper runt denna funktion, vilket gör den enhetstestbar.
     *
     * @param string $url   Repo-URL från det dolda formulärfältet.
     * @param string $tag   Tagg från det dolda formulärfältet.
     * @param string $type  `'plugin'` eller `'theme'`.
     * @return array{type:string,prepared:array}|WP_Error
     */
    function gitup_resolve_install_from_url_confirm_request($url, $tag, $type) {
        if (!is_string($type) || !in_array($type, ['plugin', 'theme'], true)) {
            return new WP_Error('gitup_invalid_install_type', __('Invalid install type.', 'gitup'));
        }

        $parsed = gitup_parse_install_source($url);
        if (is_wp_error($parsed)) {
            return $parsed;
        }

        if (!is_string($tag) || $tag === '') {
            return new WP_Error('gitup_missing_tag', __('A release tag is required.', 'gitup'));
        }

        if ($type === 'plugin') {
            $prepared = gitup_prepare_plugin_install_from_url($parsed['repo_url'], $tag);
        } else {
            $prepared = gitup_prepare_theme_install_from_url($parsed['repo_url'], $tag);
        }

        if (is_wp_error($prepared)) {
            return $prepared;
        }

        return [
            'type'     => $type,
            'prepared' => $prepared,
        ];
    }
}

if (!function_exists('gitup_install_from_url_can_install')) {
    /**
     * Centraliserad capability- och DISALLOW_FILE_MODS-koll för install-from-URL.
     *
     * `$type` accepterar `'plugin'`, `'theme'` eller `'any'`. Det senare
     * används av preview-steget innan vi vet vilken typ användaren kommer
     * att välja — det räcker då med att minst en av install_plugins eller
     * install_themes finns.
     *
     * Returnerar `true` om åtgärden får utföras, annars `WP_Error` med en
     * felkod som handlern kan logga och skicka vidare till användaren.
     *
     * @param string $type
     * @return true|WP_Error
     */
    function gitup_install_from_url_can_install($type) {
        if (defined('DISALLOW_FILE_MODS') && DISALLOW_FILE_MODS) {
            return new WP_Error(
                'gitup_disallow_file_mods',
                __('File modifications are disabled on this site (DISALLOW_FILE_MODS).', 'gitup')
            );
        }

        if ($type === 'plugin') {
            $allowed = current_user_can('install_plugins');
        } elseif ($type === 'theme') {
            $allowed = current_user_can('install_themes');
        } else {
            $allowed = current_user_can('install_plugins') || current_user_can('install_themes');
        }

        if (!$allowed) {
            return new WP_Error(
                'gitup_install_forbidden',
                __('You do not have permission to install this component.', 'gitup')
            );
        }

        return true;
    }
}

if (!function_exists('gitup_install_from_url_check_update_uri_header')) {
    /**
     * Kollar om en nyinstallerad komponent (plugin eller tema) har en
     * `Update URI`-header som matchar det repo vi installerade från.
     *
     * För plugins krävs `UpdateURI` exakt — det är vad WordPress matchar
     * sin updates-routing på. För teman accepteras även `ThemeURI` som
     * fallback, eftersom GitUps egna update-detection (`gitup_get_theme_repo_url`)
     * gör samma fallback i den vanliga update-rundan.
     *
     * Resultatet används för att förlänga success-noticen med en varning
     * när framtida uppdateringar inte kommer kunna skötas av GitUp utan
     * en manuell header-fix.
     *
     * @param string $type            `'plugin'` eller `'theme'`.
     * @param string $desired_name    Plugin-slug (mappnamn) eller tema-stylesheet.
     * @param string $expected_repo   Normaliserad repo-URL vi installerade från.
     * @return array{matches:bool,detected_repo:string}
     */
    function gitup_install_from_url_check_update_uri_header($type, $desired_name, $expected_repo) {
        if ($type === 'plugin') {
            $plugins = function_exists('get_plugins') ? get_plugins() : [];
            foreach ((array) $plugins as $plugin_file => $info) {
                if (dirname((string) $plugin_file) === (string) $desired_name) {
                    $detected = function_exists('gitup_get_plugin_repo_url')
                        ? gitup_get_plugin_repo_url($info)
                        : '';
                    return [
                        'matches'       => $detected !== '' && $detected === $expected_repo,
                        'detected_repo' => (string) $detected,
                    ];
                }
            }
            return ['matches' => false, 'detected_repo' => ''];
        }

        if ($type === 'theme') {
            $themes = function_exists('wp_get_themes') ? wp_get_themes() : [];
            $theme = is_array($themes) && isset($themes[$desired_name]) ? $themes[$desired_name] : null;
            if (!$theme) {
                return ['matches' => false, 'detected_repo' => ''];
            }
            $detected = function_exists('gitup_get_theme_repo_url')
                ? gitup_get_theme_repo_url($theme)
                : '';
            return [
                'matches'       => $detected !== '' && $detected === $expected_repo,
                'detected_repo' => (string) $detected,
            ];
        }

        return ['matches' => false, 'detected_repo' => ''];
    }
}

if (!function_exists('gitup_install_from_url_confirm_nonce_action')) {
    /**
     * Bygger ett nonce-action som binder repo_url + tag + type, så att en
     * användare inte kan ändra de dolda formulärfälten och återanvända
     * sin nonce.
     */
    function gitup_install_from_url_confirm_nonce_action($repo_url, $tag, $type) {
        return 'gitup_install_from_url_confirm_' . md5(
            (string) $repo_url . '|' . (string) $tag . '|' . (string) $type
        );
    }
}

add_action('admin_post_gitup_install_from_url_confirm', function () {
    $type = isset($_POST['gitup_install_type'])
        ? sanitize_key(wp_unslash($_POST['gitup_install_type']))
        : '';

    $allowed = gitup_install_from_url_can_install($type === 'theme' ? 'theme' : 'plugin');
    if (is_wp_error($allowed)) {
        if ($allowed->get_error_code() === 'gitup_install_forbidden') {
            wp_die($allowed->get_error_message());
        }
        gitup_redirect_with_notice($allowed->get_error_message(), '0', ['tab' => 'install']);
    }

    $url   = isset($_POST['gitup_install_repo_url'])
        ? sanitize_text_field(wp_unslash($_POST['gitup_install_repo_url']))
        : '';
    $tag   = isset($_POST['gitup_install_tag'])
        ? sanitize_text_field(wp_unslash($_POST['gitup_install_tag']))
        : '';
    $nonce = isset($_POST['_wpnonce'])
        ? sanitize_text_field(wp_unslash($_POST['_wpnonce']))
        : '';

    if (!wp_verify_nonce($nonce, gitup_install_from_url_confirm_nonce_action($url, $tag, $type))) {
        gitup_redirect_with_notice(__('Invalid request.', 'gitup'), '0', ['tab' => 'install']);
    }

    $resolved = gitup_resolve_install_from_url_confirm_request($url, $tag, $type);
    if (is_wp_error($resolved)) {
        gitup_redirect_with_notice($resolved->get_error_message(), '0', ['tab' => 'install']);
    }

    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    require_once ABSPATH . 'wp-admin/includes/plugin.php';

    $skin = new Automatic_Upgrader_Skin();

    if ($resolved['type'] === 'plugin') {
        $upgrader = new Plugin_Upgrader($skin);
        $filter   = gitup_build_plugin_install_package_options_filter($resolved['prepared']['desired_slug']);
    } else {
        $upgrader = new Theme_Upgrader($skin);
        $filter   = gitup_build_theme_install_package_options_filter($resolved['prepared']['desired_stylesheet']);
    }

    add_filter('upgrader_package_options', $filter);
    $result = $upgrader->install($resolved['prepared']['package']);
    remove_filter('upgrader_package_options', $filter);

    if ($result && !is_wp_error($result)) {
        // Lyckad install — rensa preview-transientet och alla relevanta caches
        // så att efterföljande update-check direkt plockar upp den nya
        // komponenten via dess `Update URI`-header.
        delete_transient(gitup_install_from_url_preview_transient_key());
        if (function_exists('wp_clean_plugins_cache')) {
            wp_clean_plugins_cache();
        }
        if (function_exists('wp_clean_themes_cache')) {
            wp_clean_themes_cache();
        }
        delete_site_transient('update_plugins');
        delete_site_transient('update_themes');

        $msg = $resolved['type'] === 'plugin'
            ? __('Plugin installed successfully from GitHub URL.', 'gitup')
            : __('Theme installed successfully from GitHub URL.', 'gitup');

        // Verifiera att den installerade komponenten faktiskt har en Update URI
        // som matchar repo:t. Saknas headern går WordPress/GitUps update-detection
        // bet, och vi måste informera användaren om att framtida uppdateringar
        // inte kommer skötas automatiskt förrän den läggs till.
        $desired_name = $resolved['type'] === 'plugin'
            ? $resolved['prepared']['desired_slug']
            : $resolved['prepared']['desired_stylesheet'];
        $expected_repo = (string) $resolved['prepared']['repo_url'];

        $header_check = gitup_install_from_url_check_update_uri_header(
            $resolved['type'],
            $desired_name,
            $expected_repo
        );
        if (is_array($header_check) && empty($header_check['matches'])) {
            $msg .= ' ' . sprintf(
                /* translators: %s: full Update URI value the installed component should declare */
                __('Note: GitUp will not be able to auto-update it until the main file declares `Update URI: %s`.', 'gitup'),
                $expected_repo
            );
        }

        gitup_redirect_with_notice($msg, '1', ['tab' => 'install']);
    } else {
        $msg = is_wp_error($result)
            ? $result->get_error_message()
            : __('Installation failed.', 'gitup');
        gitup_redirect_with_notice($msg, '0', ['tab' => 'install']);
    }
});

add_action('admin_post_gitup_install_from_url_preview', function () {
    $allowed = gitup_install_from_url_can_install('any');
    if (is_wp_error($allowed)) {
        if ($allowed->get_error_code() === 'gitup_install_forbidden') {
            wp_die($allowed->get_error_message());
        }
        gitup_redirect_with_notice($allowed->get_error_message(), '0', ['tab' => 'install']);
    }

    $nonce = isset($_POST['_wpnonce']) ? sanitize_text_field(wp_unslash($_POST['_wpnonce'])) : '';
    if (!wp_verify_nonce($nonce, 'gitup_install_from_url_preview')) {
        gitup_redirect_with_notice(__('Invalid request.', 'gitup'), '0', ['tab' => 'install']);
    }

    $url = isset($_POST['gitup_install_url'])
        ? sanitize_text_field(wp_unslash($_POST['gitup_install_url']))
        : '';

    $result = gitup_run_install_from_url_preview($url);
    if (is_wp_error($result)) {
        if (function_exists('gitup_log')) {
            gitup_log('install-from-url preview failed: ' . $result->get_error_code() . ' for input "' . $url . '"');
        }
        // Bevara användarens råa input så att fältet inte töms vid felmeddelandet.
        set_transient(
            gitup_install_from_url_preview_transient_key(),
            ['url_input' => $url, 'error' => $result->get_error_message()],
            15 * MINUTE_IN_SECONDS
        );
        gitup_redirect_with_notice($result->get_error_message(), '0', ['tab' => 'install']);
    }

    // Bevara den ursprungliga inmatningen så att fältet kan re-populeras.
    $result['url_input'] = $url;

    set_transient(
        gitup_install_from_url_preview_transient_key(),
        $result,
        15 * MINUTE_IN_SECONDS
    );

    wp_safe_redirect(gitup_get_settings_page_url(['tab' => 'install', 'previewed' => '1']));
    exit;
});

if (!function_exists('gitup_render_install_from_url_tab')) {
    /**
     * Renderar fliken "Install from URL" på GitUps options-sida.
     *
     * Två lägen:
     *   - utan transient: visa input-formuläret
     *   - med transient:  visa inspect-resultatet (repo, tagg, typ, releaser)
     *
     * Funktionen anropas från `gitup_settings_page()` i [options.php].
     * Den lever här för att hålla all install-from-URL-logik samlad i en fil.
     */
    function gitup_render_install_from_url_tab() {
        $allowed = gitup_install_from_url_can_install('any');
        if (is_wp_error($allowed)) {
            echo '<p>' . esc_html($allowed->get_error_message()) . '</p>';
            return;
        }

        // Möjlighet att börja om: ?tab=install&reset=1
        if (!empty($_GET['reset'])) {
            delete_transient(gitup_install_from_url_preview_transient_key());
        }

        $preview = get_transient(gitup_install_from_url_preview_transient_key());
        $action_url    = admin_url('admin-post.php');
        $preview_nonce = wp_create_nonce('gitup_install_from_url_preview');
        $reset_url     = gitup_get_settings_page_url(['tab' => 'install', 'reset' => '1']);
        $url_value     = is_array($preview) && !empty($preview['url_input']) ? (string) $preview['url_input'] : '';
        $has_full_preview = is_array($preview) && !empty($preview['repo_url']) && !empty($preview['tag_used']);
        ?>
        <div class="gitup-install-from-url" style="margin-top:20px;">
          <h2><?php esc_html_e('Install plugin or theme from GitHub URL', 'gitup'); ?></h2>
          <p><?php esc_html_e('Paste a GitHub repository URL (or owner/repo). GitUp will inspect the repository and let you confirm before installing.', 'gitup'); ?></p>

          <form method="post" action="<?php echo esc_url($action_url); ?>" style="max-width:640px;">
            <input type="hidden" name="action" value="gitup_install_from_url_preview">
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($preview_nonce); ?>">
            <p>
              <label for="gitup_install_url"><strong><?php esc_html_e('GitHub URL', 'gitup'); ?></strong></label><br>
              <input type="text" id="gitup_install_url" name="gitup_install_url" value="<?php echo esc_attr($url_value); ?>" placeholder="https://github.com/owner/repo" class="regular-text" required>
            </p>
            <p>
              <button type="submit" class="button button-primary"><?php esc_html_e('Inspect repository', 'gitup'); ?></button>
              <?php if ($url_value !== '') : ?>
                <a href="<?php echo esc_url($reset_url); ?>" class="button" style="margin-left:8px;"><?php esc_html_e('Clear', 'gitup'); ?></a>
              <?php endif; ?>
            </p>
          </form>

          <?php if ($has_full_preview) : ?>
            <hr style="margin:24px 0;">
            <h3><?php esc_html_e('Inspection result', 'gitup'); ?></h3>
            <table class="form-table">
              <tr>
                <th scope="row"><?php esc_html_e('Repository', 'gitup'); ?></th>
                <td>
                  <a href="<?php echo esc_url((string) $preview['repo_url']); ?>" target="_blank" rel="noopener">
                    <?php echo esc_html((string) $preview['repo_url']); ?>
                  </a>
                </td>
              </tr>
              <tr>
                <th scope="row"><?php esc_html_e('Tag inspected', 'gitup'); ?></th>
                <td><code><?php echo esc_html((string) $preview['tag_used']); ?></code></td>
              </tr>
              <tr>
                <th scope="row"><?php esc_html_e('Detected type', 'gitup'); ?></th>
                <td>
                  <?php
                  switch ((string) $preview['type']) {
                      case 'plugin':
                          printf(
                              /* translators: %s: plugin name from header */
                              esc_html__('Plugin (%s)', 'gitup'),
                              esc_html((string) $preview['plugin_name'])
                          );
                          break;
                      case 'theme':
                          printf(
                              /* translators: %s: theme name from style.css */
                              esc_html__('Theme (%s)', 'gitup'),
                              esc_html((string) $preview['theme_name'])
                          );
                          break;
                      case 'both':
                          esc_html_e('Both plugin and theme headers were detected — you will pick which one to install in the next step.', 'gitup');
                          break;
                      case 'none':
                      default:
                          esc_html_e('Neither plugin nor theme headers were detected. Are you sure this is a WordPress plugin or theme repository?', 'gitup');
                          break;
                  }
                  ?>
                </td>
              </tr>
              <tr>
                <th scope="row"><?php esc_html_e('Available releases', 'gitup'); ?></th>
                <td>
                  <?php if (!empty($preview['releases'])) : ?>
                    <ul style="margin:0;">
                      <?php foreach (array_slice((array) $preview['releases'], 0, 10) as $release) : ?>
                        <li>
                          <code><?php echo esc_html((string) ($release['tag'] ?? '')); ?></code>
                          <?php if (!empty($release['prerelease'])) : ?>
                            <em>(<?php esc_html_e('prerelease', 'gitup'); ?>)</em>
                          <?php endif; ?>
                        </li>
                      <?php endforeach; ?>
                    </ul>
                  <?php else : ?>
                    <em><?php esc_html_e('No releases found.', 'gitup'); ?></em>
                  <?php endif; ?>
                </td>
              </tr>
            </table>
            <?php
            $preview_type = (string) $preview['type'];
            $preview_repo = (string) $preview['repo_url'];
            $preview_tag  = (string) $preview['tag_used'];
            $show_plugin_button = in_array($preview_type, ['plugin', 'both'], true);
            $show_theme_button  = in_array($preview_type, ['theme', 'both'], true);
            ?>

            <?php if ($preview_type === 'none') : ?>
              <p><em><?php esc_html_e('No install button is shown because this repository does not look like a WordPress plugin or theme. Double-check the URL or pick a different release.', 'gitup'); ?></em></p>
            <?php endif; ?>

            <p>
              <?php if ($show_plugin_button) : ?>
                <form method="post" action="<?php echo esc_url($action_url); ?>" style="display:inline-block; margin-right:8px;">
                  <input type="hidden" name="action" value="gitup_install_from_url_confirm">
                  <input type="hidden" name="gitup_install_repo_url" value="<?php echo esc_attr($preview_repo); ?>">
                  <input type="hidden" name="gitup_install_tag" value="<?php echo esc_attr($preview_tag); ?>">
                  <input type="hidden" name="gitup_install_type" value="plugin">
                  <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce(gitup_install_from_url_confirm_nonce_action($preview_repo, $preview_tag, 'plugin'))); ?>">
                  <button type="submit" class="button button-primary"><?php esc_html_e('Install as plugin', 'gitup'); ?></button>
                </form>
              <?php endif; ?>

              <?php if ($show_theme_button) : ?>
                <form method="post" action="<?php echo esc_url($action_url); ?>" style="display:inline-block; margin-right:8px;">
                  <input type="hidden" name="action" value="gitup_install_from_url_confirm">
                  <input type="hidden" name="gitup_install_repo_url" value="<?php echo esc_attr($preview_repo); ?>">
                  <input type="hidden" name="gitup_install_tag" value="<?php echo esc_attr($preview_tag); ?>">
                  <input type="hidden" name="gitup_install_type" value="theme">
                  <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce(gitup_install_from_url_confirm_nonce_action($preview_repo, $preview_tag, 'theme'))); ?>">
                  <button type="submit" class="button button-primary"><?php esc_html_e('Install as theme', 'gitup'); ?></button>
                </form>
              <?php endif; ?>

              <a href="<?php echo esc_url($reset_url); ?>" class="button"><?php esc_html_e('Inspect another repository', 'gitup'); ?></a>
            </p>
          <?php endif; ?>
        </div>
        <?php
    }
}
