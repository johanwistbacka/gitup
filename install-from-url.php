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
