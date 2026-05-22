<?php
/**
 * GitUp — Release-prefetch och lazy-load
 * --------------------------------------
 *
 * Modulen håller cache-only läs-helpers, en bakgrunds-prefetch-worker som körs
 * via WP-cron, samt en AJAX-endpoint som row-render fyller varje rad med data
 * från cache utan att blockera pageload med GitHub-anrop.
 *
 * Filen är medvetet fristående (samma mönster som `install-from-url.php`) så
 * att den kan backportas till äldre kodbaser med enbart prefix-byte.
 */

if (!function_exists('gitup_releases_cache_key')) {
    /**
     * Beräknar transient-nyckeln för en repo-releases-cache.
     *
     * Måste matcha formeln i `gitup_get_github_releases_data` (gitup-updater.php)
     * så att värden som skrivs av prefetch-workern blir synliga för befintliga
     * läsare och tvärtom.
     *
     * @param string $repo_url
     * @param bool   $include_prereleases
     * @param int    $limit
     * @return string
     */
    function gitup_releases_cache_key($repo_url, $include_prereleases = false, $limit = 20) {
        return 'github_releases_' . md5(
            (string) $repo_url
            . '|' . ($include_prereleases ? 'pre' : 'stable')
            . '|' . (int) $limit
        );
    }
}

if (!function_exists('gitup_get_cached_releases')) {
    /**
     * Läser releases från cache utan att göra HTTP-anrop.
     *
     * Returnerar `null` vid cache-miss så att callern kan skilja på "har inte
     * varmats än" och "tomt resultat" (en tom array kan vara giltig efter ett
     * tidigare fel-svar som cachats).
     *
     * @param string $repo_url
     * @param bool   $include_prereleases
     * @param int    $limit
     * @return array|null
     */
    function gitup_get_cached_releases($repo_url, $include_prereleases = false, $limit = 20) {
        $key = gitup_releases_cache_key($repo_url, $include_prereleases, $limit);
        $value = get_transient($key);
        return is_array($value) ? $value : null;
    }
}

if (!function_exists('gitup_get_tracked_repos')) {
    /**
     * Samlar plugins och teman som har en `UpdateURI` (eller `ThemeURI`) som
     * pekar på GitHub. Returnerar en flat lista som lazy-load-rendern och
     * prefetch-workern itererar över.
     *
     * Den här funktionen är medvetet HTTP-fri — den läser bara plugin-/tema-
     * headern från WordPress in-memory state.
     *
     * @return array<int,array{type:string,github:string,slug:string,name:string,version:string,author:string}>
     */
    function gitup_get_tracked_repos() {
        $tracked = [];

        $plugins = function_exists('get_plugins') ? get_plugins() : [];
        foreach ((array) $plugins as $plugin_file => $info) {
            $repo = function_exists('gitup_get_plugin_repo_url')
                ? gitup_get_plugin_repo_url($info)
                : '';
            if ($repo === '') {
                continue;
            }
            $tracked[] = [
                'type'    => 'plugin',
                'github'  => $repo,
                'slug'    => (string) $plugin_file,
                'name'    => isset($info['Name']) ? (string) $info['Name'] : '',
                'version' => isset($info['Version']) ? (string) $info['Version'] : '',
                'author'  => isset($info['Author']) ? (string) $info['Author'] : '',
            ];
        }

        $themes = function_exists('wp_get_themes') ? wp_get_themes() : [];
        foreach ((array) $themes as $stylesheet => $theme) {
            $repo = function_exists('gitup_get_theme_repo_url')
                ? gitup_get_theme_repo_url($theme)
                : '';
            if ($repo === '') {
                continue;
            }
            $name    = is_object($theme) && method_exists($theme, 'get') ? (string) $theme->get('Name') : '';
            $version = is_object($theme) && method_exists($theme, 'get') ? (string) $theme->get('Version') : '';
            $author  = is_object($theme) && method_exists($theme, 'get') ? (string) $theme->get('Author') : '';
            $tracked[] = [
                'type'    => 'theme',
                'github'  => $repo,
                'slug'    => (string) $stylesheet,
                'name'    => $name,
                'version' => $version,
                'author'  => $author,
            ];
        }

        return $tracked;
    }
}
