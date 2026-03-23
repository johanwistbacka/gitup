<?php
/**
 * GitUp — GitHub-baserade uppdateringar för plugins & teman
 *
 * Översikt
 * --------
 *  - Hookar in i WordPress uppdateringssystem (plugins & teman) och matar in
 *    senaste version från GitHub Releases.
 *  - Hämtar paket via codeload.github.com (zip) och ser till att installationen
 *    landar i rätt mapp (utan att mappnamnet får med taggen).
 *  - Har defensiv loggning till debug.log via `gitup_log()` när WP_DEBUG är true.
 *
 * Prestanda & cache
 * -----------------
 *  - `get_latest_github_release()` cache:ar lyckade taggar i 1h och fel/"N/A" i 5min.
 *  - Core triggar uppdateringscheck via WP-Cron / admin (inte på frontend-sidvisningar).
 *  - Options-sidan kan vara långsammare eftersom den listar releaser; det påverkar inte frontend.
 *
 * Förhandsreleaser
 * ----------------
 *  - Respekteras av både UI och uppdateringsmotorn. Om inställningen är AV används
 *    endast `/releases/latest`. Om PÅ används `/releases?per_page=10` och första icke-draft
 *    plockas (kan vara prerelease).
 */
if (!function_exists('gitup_log')) {
  /**
   * En liten wrapper för att logga till debug.log när WP_DEBUG är på.
   * Används flitigt i uppgraderingsflödena för att förenkla felsökning.
   *
   * @param mixed $msg  Sträng/array/objekt som loggas (array/objekt pretty-printas).
   */
function gitup_log($msg) {
    // Only log if both WP_DEBUG and gitup_debug_mode are enabled
    if (get_option('gitup_debug_mode', '0') !== '1') {
      return;
    }
    if (defined('WP_DEBUG') && WP_DEBUG) {
      if (is_array($msg) || is_object($msg)) {
        $msg = print_r($msg, true);
      }
      error_log('[GitUp] ' . $msg);
    }
  }
}

if (!function_exists('gitup_normalize_version_tag')) {
  function gitup_normalize_version_tag($version) {
    if (!is_string($version)) {
      return $version;
    }
    return ltrim(trim($version), 'vV');
  }
}

if (!function_exists('gitup_github_headers')) {
  function gitup_github_headers() {
    $headers = [
      'User-Agent' => 'WordPress Plugin',
      'Accept'     => 'application/vnd.github+json',
    ];
    $token = get_option('gitup_github_token', '');
    if (!empty($token)) {
      $headers['Authorization'] = 'Bearer ' . $token;
    }
    return $headers;
  }
}

if (!function_exists('gitup_normalize_github_repo_url')) {
  function gitup_normalize_github_repo_url($repo_url) {
    if (!is_string($repo_url)) {
      return '';
    }

    $repo_url = trim($repo_url);
    if ($repo_url === '') {
      return '';
    }

    $host = parse_url($repo_url, PHP_URL_HOST);
    if ($host === null) {
      $repo_url = 'https://github.com/' . ltrim($repo_url, '/');
      $host = parse_url($repo_url, PHP_URL_HOST);
    }

    $host = strtolower((string) $host);
    if (!in_array($host, ['github.com', 'www.github.com'], true)) {
      return '';
    }

    $path = (string) parse_url($repo_url, PHP_URL_PATH);
    $path = '/' . trim($path, '/');
    if ($path === '/' || substr_count(trim($path, '/'), '/') < 1) {
      return '';
    }

    return 'https://github.com' . $path;
  }
}

if (!function_exists('gitup_build_github_package_url')) {
  function gitup_build_github_package_url($repo_url, $tag) {
    $repo_url = gitup_normalize_github_repo_url($repo_url);
    if ($repo_url === '' || !is_string($tag) || $tag === '') {
      return '';
    }

    $repo_path = (string) parse_url($repo_url, PHP_URL_PATH);
    if ($repo_path === '') {
      return '';
    }

    return 'https://codeload.github.com' . $repo_path . '/zip/refs/tags/' . rawurlencode($tag);
  }
}

if (!function_exists('gitup_include_prereleases_enabled')) {
  function gitup_include_prereleases_enabled() {
    return get_option('gitup_include_prereleases', '0') === '1';
  }
}

if (!function_exists('gitup_get_theme_repo_url')) {
  function gitup_get_theme_repo_url($theme) {
    if (!is_object($theme) || !method_exists($theme, 'get')) {
      return '';
    }

    $repo_url = gitup_normalize_github_repo_url($theme->get('UpdateURI'));
    if ($repo_url !== '') {
      return $repo_url;
    }

    return gitup_normalize_github_repo_url($theme->get('ThemeURI'));
  }
}

if (!function_exists('gitup_get_plugin_repo_url')) {
  function gitup_get_plugin_repo_url($plugin_info) {
    if (!is_array($plugin_info) || empty($plugin_info['UpdateURI'])) {
      return '';
    }

    return gitup_normalize_github_repo_url($plugin_info['UpdateURI']);
  }
}

if (!function_exists('gitup_compare_version_tags')) {
  function gitup_compare_version_tags($left, $right) {
    return version_compare(
      gitup_normalize_version_tag((string) $left),
      gitup_normalize_version_tag((string) $right)
    );
  }
}

if (!function_exists('gitup_find_theme_root_directory')) {
  function gitup_find_theme_root_directory($source, $expected_stylesheet = '') {
    $expected_stylesheet = trim((string) $expected_stylesheet, '/');
    $candidates = [];
    $seen = [];

    $add_candidate = function ($dir) use (&$candidates, &$seen) {
      $dir = untrailingslashit((string) $dir);
      if ($dir === '' || isset($seen[$dir])) {
        return;
      }

      $style = trailingslashit($dir) . 'style.css';
      if (!file_exists($style)) {
        return;
      }

      $contents = @file_get_contents($style, false, null, 0, 8192);
      if ($contents === false || !preg_match('/^\s*Theme\s*Name\s*:\s*(.+)$/mi', $contents)) {
        return;
      }

      $seen[$dir] = true;
      $candidates[] = $dir;
    };

    $add_candidate($source);

    $dirs_lvl1 = glob(trailingslashit($source) . '*', GLOB_ONLYDIR) ?: [];
    foreach ($dirs_lvl1 as $d1) {
      $add_candidate($d1);
    }

    foreach ($dirs_lvl1 as $d1) {
      $dirs_lvl2 = glob(trailingslashit($d1) . '*', GLOB_ONLYDIR) ?: [];
      foreach ($dirs_lvl2 as $d2) {
        $add_candidate($d2);
      }
    }

    if ($expected_stylesheet !== '') {
      foreach ($candidates as $candidate) {
        if (basename($candidate) === $expected_stylesheet) {
          gitup_log('theme root candidate matched expected stylesheet: ' . $candidate);
          return trailingslashit($candidate);
        }
      }
    }

    if (count($candidates) === 1) {
      gitup_log('theme root single candidate selected: ' . $candidates[0]);
      return trailingslashit($candidates[0]);
    }

    if (count($candidates) > 1) {
      gitup_log('theme root multiple candidates found: ' . json_encode($candidates));
    }

    return !empty($candidates) ? trailingslashit($candidates[0]) : '';
  }
}

if (!function_exists('gitup_get_latest_github_release_tag')) {
  function gitup_get_latest_github_release_tag($repo_url, $force_refresh = false) {
    $include_prereleases = gitup_include_prereleases_enabled();
    $cache_key = 'github_release_' . md5($repo_url . '|' . ($include_prereleases ? 'pre' : 'stable'));

    if ($force_refresh) {
      delete_transient($cache_key);
    }

    $cached_release = get_transient($cache_key);
    if (!$force_refresh && $cached_release && $cached_release !== 'N/A') {
      return $cached_release;
    }

    $releases = gitup_get_github_releases_data($repo_url, $include_prereleases, 1);
    $latest = !empty($releases[0]['tag_name']) ? $releases[0]['tag_name'] : 'N/A';

    if ($latest !== 'N/A') {
      set_transient($cache_key, $latest, HOUR_IN_SECONDS);
    } else {
      set_transient($cache_key, 'N/A', 5 * MINUTE_IN_SECONDS);
    }

    return $latest;
  }
}

if (!function_exists('gitup_get_github_releases_data')) {
  function gitup_get_github_releases_data($repo_url, $include_prereleases = false, $limit = 10) {
    $repo_path = parse_url($repo_url, PHP_URL_PATH);
    if (!$repo_path) {
      return [];
    }

    $cache_key = 'github_releases_' . md5($repo_url . '|' . ($include_prereleases ? 'pre' : 'stable') . '|' . (int) $limit);
    $cached = get_transient($cache_key);
    if (is_array($cached)) {
      return $cached;
    }

    $headers = gitup_github_headers();
    $api_url = $include_prereleases
      ? 'https://api.github.com/repos' . $repo_path . '/releases?per_page=' . max(10, (int) $limit)
      : 'https://api.github.com/repos' . $repo_path . '/releases/latest';

    $response = wp_remote_get($api_url, [
      'headers'     => $headers,
      'timeout'     => 20,
      'redirection' => 3,
    ]);

    if (is_wp_error($response)) {
      set_transient($cache_key, [], 5 * MINUTE_IN_SECONDS);
      return [];
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    if ($code === 403) {
      $error_payload = [['error' => 'rate_limit']];
      set_transient($cache_key, $error_payload, 5 * MINUTE_IN_SECONDS);
      return $error_payload;
    }

    $releases = [];
    if ($code === 200) {
      $data = json_decode(wp_remote_retrieve_body($response), true);
      if ($include_prereleases && is_array($data)) {
        foreach ($data as $rel) {
          if (!empty($rel['draft']) || empty($rel['tag_name'])) {
            continue;
          }
          if (!$include_prereleases && !empty($rel['prerelease'])) {
            continue;
          }
          $releases[] = [
            'tag_name'     => $rel['tag_name'],
            'name'         => !empty($rel['name']) ? $rel['name'] : $rel['tag_name'],
            'body'         => $rel['body'] ?? '',
            'published_at' => $rel['published_at'] ?? '',
            'html_url'     => $rel['html_url'] ?? '',
            'prerelease'   => !empty($rel['prerelease']),
          ];
          if (count($releases) >= $limit) {
            break;
          }
        }
      } elseif (is_array($data) && !empty($data['tag_name'])) {
        $releases[] = [
          'tag_name'     => $data['tag_name'],
          'name'         => !empty($data['name']) ? $data['name'] : $data['tag_name'],
          'body'         => $data['body'] ?? '',
          'published_at' => $data['published_at'] ?? '',
          'html_url'     => $data['html_url'] ?? '',
          'prerelease'   => !empty($data['prerelease']),
        ];
      }
    }

    if (empty($releases)) {
      $tags_url = 'https://api.github.com/repos' . $repo_path . '/tags?per_page=' . max(10, (int) $limit);
      $tags_response = wp_remote_get($tags_url, [
        'headers'     => $headers,
        'timeout'     => 20,
        'redirection' => 3,
      ]);

      if (!is_wp_error($tags_response) && (int) wp_remote_retrieve_response_code($tags_response) === 200) {
        $tags_data = json_decode(wp_remote_retrieve_body($tags_response), true);
        if (is_array($tags_data)) {
          foreach ($tags_data as $tag) {
            $tag_name = $tag['name'] ?? '';
            if ($tag_name === '') {
              continue;
            }

            if (
              !$include_prereleases &&
              preg_match('/(alpha|beta|rc|pre)/i', $tag_name)
            ) {
              continue;
            }

            $releases[] = [
              'tag_name'     => gitup_normalize_version_tag($tag_name),
              'name'         => gitup_normalize_version_tag($tag_name),
              'body'         => '',
              'published_at' => '',
              'html_url'     => !empty($tag['commit']['sha'])
                ? 'https://github.com' . $repo_path . '/commit/' . $tag['commit']['sha']
                : '',
              'prerelease'   => false,
            ];

            if (count($releases) >= $limit) {
              break;
            }
          }

          usort($releases, function ($a, $b) {
            return version_compare(
              gitup_normalize_version_tag($b['tag_name']),
              gitup_normalize_version_tag($a['tag_name'])
            );
          });
        }
      }
    }

    set_transient(
      $cache_key,
      $releases,
      empty($releases) ? 5 * MINUTE_IN_SECONDS : HOUR_IN_SECONDS
    );

    return $releases;
  }
}

if (!class_exists('GitUpUpdater')) {
  /**
   * Huvudklass som integrerar mot GitHub och WordPress uppdaterings-API.
   *
   * Viktiga ansvarsområden:
   *  - Annonsera uppdateringar (plugins/teman) via `pre_set_site_transient_update_*`.
   *  - Visa info-popups via `plugins_api` och `themes_api`.
   *  - Hämta senaste release och cacha resultat.
   *  - Respektera inställningen för förhandsreleaser (beta/rc).
   */
  class GitUpUpdater {
    // Singleton-instans för att undvika dubbla hook-registreringar
    private static $instance = null;
    // Token från inställningar – används som Authorization mot GitHub
    private $github_token;

    /**
     * Registrerar samtliga filter/hooks för plugins & teman.
     * Gör inga tunga anrop här – endast hook-registrering.
     */
    private function __construct() {
      $this->github_token = get_option('gitup_github_token', '');
      // === Plugins: mata in uppdateringar + info-popup ===
      add_filter('pre_set_site_transient_update_plugins', [$this, 'check_for_update']);
      add_filter('plugins_api', [$this, 'plugin_info'], 10, 3);
      // === Teman: mata in uppdateringar + info-popup ===
      add_filter('pre_set_site_transient_update_themes', [$this, 'check_for_theme_update']);
      add_filter('themes_api', [$this, 'theme_info'], 10, 3);
      gitup_log('GitUpUpdater: theme update hooks registered');
    }

    public static function get_instance() {
      if (self::$instance === null) {
        self::$instance = new self();
      }
      return self::$instance;
    }

    /**
     * Annonserar plugin-uppdateringar till WordPress core.
     *
     * Core kallar denna vid schemalagda/administrativa uppdateringskontroller.
     * Vi loopar igenom installerade plugins som har en GitHub-`UpdateURI`,
     * jämför version mot senaste release-tag och fyller `$transient->response`.
     *
     * @param object $transient  WordPress transient för uppdateringar.
     * @return object            Modifierad transient.
     */
    public function check_for_update($transient) {
      if (!is_object($transient)) {
        $transient = new stdClass();
      }
      if (!isset($transient->checked)) {
        return $transient;
      }

      if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
      }

      $plugins = get_plugins();
      foreach ($plugins as $plugin_path => $plugin_info) {
        $repo_url = gitup_get_plugin_repo_url($plugin_info);
        if ($repo_url === '') {
          gitup_log('plugin check: skipped (no UpdateURI) ' . $plugin_path);
          continue; // Only plugins with UpdateURI
        }
        $latest_release = $this->get_latest_github_release($repo_url);
        if ($latest_release === 'N/A') {
          gitup_log('plugin check: no release tag (N/A) for ' . $plugin_path . ' repo=' . $repo_url);
          continue;
        }
        // Normalize versions to handle tags like v1.2.3 vs 1.2.3
        $current_ver  = (string) $plugin_info['Version'];
        $latest_norm  = $this->normalize_version_tag($latest_release);
        $current_norm = $this->normalize_version_tag($current_ver);
        gitup_log('plugin check: ' . $plugin_path . ' current=' . $current_ver . ' latest=' . $latest_release . ' (cmp ' . $current_norm . ' vs ' . $latest_norm . ')');
        if (version_compare($current_norm, $latest_norm, '<')) {
          $plugin_slug = dirname($plugin_path);
          $zip_url     = gitup_build_github_package_url($repo_url, $latest_release);
          if ($zip_url === '') {
            gitup_log('plugin check: could not build package url for ' . $plugin_path);
            continue;
          }
          gitup_log('Update available for ' . $plugin_path . ' -> tag ' . $latest_release . ' package ' . $zip_url);
          $transient->response[$plugin_path] = (object) [
            'slug'        => $plugin_slug,
            'new_version' => $latest_release,
            'package'     => $zip_url,
          ];
        }
      }
      return $transient;
    }

    /**
     * Förser "Visa detaljer"-popupen för plugins med GitHub-data.
     * Hämtar tag via samma regelverk (stable vs pre) och pekar på codeload-zip.
     *
     * @param mixed  $res     Befintligt svar
     * @param string $action  Förväntas vara 'plugin_information'
     * @param object $args    Innehåller bl.a. `slug`
     * @return mixed
     */
    public function plugin_info($res, $action, $args) {
      if ($action !== 'plugin_information' || empty($args->slug)) {
        return $res;
      }

      if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
      }

      $plugins = get_plugins();
      foreach ($plugins as $plugin_path => $plugin_info) {
        if (dirname($plugin_path) !== $args->slug) {
          continue;
        }
        if (empty($plugin_info['UpdateURI'])) {
          continue;
        }
        $repo_url = gitup_get_plugin_repo_url($plugin_info);
        if ($repo_url === '') {
          return $res;
        }
        $releases = $this->get_github_releases($repo_url, 10);
        if (empty($releases) || !empty($releases[0]['error']) || empty($releases[0]['tag_name'])) {
          return $res;
        }
        $latest = $releases[0];
        $tag = $latest['tag_name'];
        $zip_url = gitup_build_github_package_url($repo_url, $tag);
        if ($zip_url === '') {
          return $res;
        }
        gitup_log('Plugin info for ' . $args->slug . ' -> tag ' . $tag . ' package ' . $zip_url);

        $info                = new stdClass();
        $info->name          = $plugin_info['Name'];
        $info->slug          = $args->slug;
        $info->version       = $tag;
        $info->author        = $plugin_info['Author'];
        $info->homepage      = $repo_url;
        $info->download_link = $zip_url;
        $info->sections      = [
          'description' => !empty($plugin_info['Description']) ? $plugin_info['Description'] : 'Updates are fetched from GitHub.',
          'changelog'   => !empty($latest['body']) ? wp_kses_post($latest['body']) : '',
        ];
        return $info;
      }
      return $res;
    }

    /**
     * Hämtar (och cache:ar) senaste release-tag från GitHub för ett repo.
     *
     * - Stable-läge:  `/releases/latest` → tag_name
     * - Pre-läge:     `/releases?per_page=10` → första icke-draft
     * - Cache: Lyckad tag 1h, fel/N/A 5min (återhämtar sig snabbt vid transienta fel)
     *
     * @param string $repo_url  Full GitHub-URL (UpdateURI/ThemeURI)
     * @return string           Tagg eller 'N/A'
     */
    private function get_latest_github_release($repo_url) {
      $releases = $this->get_github_releases($repo_url, 1);
      if (!empty($releases) && empty($releases[0]['error']) && !empty($releases[0]['tag_name'])) {
        return $releases[0]['tag_name'];
      }
      return 'N/A';
    }

    /**
     * Hämtar (och cache:ar) de senaste $limit releaserna från GitHub för ett repo.
     * Returnerar en array av assoc-arrayer: [ [tag_name, body, published_at], ... ]
     *
     * @param string $repo_url  Full GitHub-URL (UpdateURI/ThemeURI)
     * @param int $limit        Antal releaser att hämta (default 10)
     * @return array
     */
    private function get_github_releases($repo_url, $limit = 10) {
      $include_prereleases = gitup_include_prereleases_enabled();
      return gitup_get_github_releases_data($repo_url, $include_prereleases, $limit);
    }

    /**
     * Normalisera versionssträng från tag (t.ex. ta bort inledande "v").
     */
    private function normalize_version_tag($tag) {
      return gitup_normalize_version_tag($tag);
    }
    /**
     * Annonserar tema-uppdateringar till WordPress core.
     * Fungerar analogt med plugin-flödet.
     *
     * @param object $transient  WordPress transient för tema-uppdateringar.
     * @return object            Modifierad transient.
     */
    public function check_for_theme_update($transient) {
      gitup_log('check_for_theme_update: start');
      if (!is_object($transient)) {
        $transient = new stdClass();
      }
      if (!isset($transient->checked)) {
        gitup_log('check_for_theme_update: no checked property on transient');
        return $transient;
      }
      $themes = wp_get_themes();
      foreach ($themes as $stylesheet => $theme) {
        $repo_url = gitup_get_theme_repo_url($theme);
        if ($repo_url === '') {
          continue;
        }
        $releases = $this->get_github_releases($repo_url, 10);
        if (!$releases || empty($releases[0]['tag_name'])) {
          continue;
        }
        $latest_release = $releases[0]['tag_name'];
        $current_ver  = (string) $theme->get('Version');
        $latest_norm  = $this->normalize_version_tag($latest_release);
        $current_norm = $this->normalize_version_tag($current_ver);
        gitup_log('check_for_theme_update: theme=' . $stylesheet . ' current=' . $current_ver . ' latest=' . $latest_release . ' (cmp ' . $current_norm . ' vs ' . $latest_norm . ')');
        if (version_compare($current_norm, $latest_norm, '<')) {
          $zip_url = gitup_build_github_package_url($repo_url, $latest_release);
          if ($zip_url === '') {
            continue;
          }
          gitup_log('Theme update available for ' . $stylesheet . ' -> tag ' . $latest_release . ' package ' . $zip_url);
          $transient->response[$stylesheet] = [
            'theme'       => $stylesheet,
            'new_version' => $latest_release, // behåll originaltaggen i UI
            'package'     => $zip_url,
            'url'         => $repo_url,
          ];
        }
        // Store all releases for UI (e.g., changelog)
        if (!isset($transient->rg_releases)) {
          $transient->rg_releases = [];
        }
        $transient->rg_releases[$stylesheet] = $releases;
      }
      return $transient;
    }

    /**
     * Förser "Visa detaljer"-popupen för teman med GitHub-data.
     *
     * @param mixed  $res
     * @param string $action  Förväntas vara 'theme_information'
     * @param object $args    Innehåller bl.a. `slug`
     * @return mixed
     */
    public function theme_info($res, $action, $args) {
      if ($action !== 'theme_information' || empty($args->slug)) {
        return $res;
      }
      gitup_log('theme_info: request for slug=' . $args->slug);
      $themes = wp_get_themes();
      foreach ($themes as $stylesheet => $theme) {
        if ($stylesheet !== $args->slug) {
          continue;
        }
        $repo_url = gitup_get_theme_repo_url($theme);
        if ($repo_url === '') {
          gitup_log('theme_info: no GitHub URI for slug=' . $args->slug);
          return $res;
        }
        $releases = $this->get_github_releases($repo_url, 10);
        if (!$releases || !empty($releases[0]['error']) || empty($releases[0]['tag_name'])) {
          gitup_log('theme_info: no releases found');
          return $res;
        }
        $latest = $releases[0];
        $tag = $latest['tag_name'];
        $zip_url = gitup_build_github_package_url($repo_url, $tag);
        if ($zip_url === '') {
          gitup_log('theme_info: could not build package url');
          return $res;
        }
        gitup_log('Theme info for ' . $args->slug . ' -> tag ' . $tag . ' package ' . $zip_url);
        $changelog = '';
        foreach ($releases as $rel) {
          if (empty($rel['tag_name'])) continue;
          $changelog .= '<h4>' . esc_html($rel['tag_name']) . '</h4>';
          $changelog .= '<div>' . wp_kses_post($rel['body']) . '</div><hr>';
        }
        $info = new stdClass();
        $info->name = $theme->get('Name');
        $info->slug = $args->slug;
        $info->version = $tag;
        $info->author = $theme->get('Author');
        $info->preview_url = $theme->get('ThemeURI');
        $info->download_link = $zip_url;
        $info->sections = [
          'description' => $theme->get('Description'),
          'changelog'   => $changelog,
        ];
        return $info;
      }
      return $res;
    }
  }

  GitUpUpdater::get_instance();

}

/**
 * http_request_args — injicerar korrekta headers för GitHub-hosts
 *
 *  - Sätter User-Agent (GitHub kräver en UA)
 *  - Lägger Authorization: Bearer <token> när token finns
 *  - Sätter Accept till JSON eller binär (octet-stream) beroende på endpoint
 *  - Loggar vilka headers som gavs (endast när WP_DEBUG=true)
 */
add_filter('http_request_args', function ($args, $url) {
  $token = get_option('gitup_github_token', '');
  $host  = parse_url($url, PHP_URL_HOST);
  $path  = parse_url($url, PHP_URL_PATH);
  if (!$host) {
    return $args;
  }
  $github_hosts = ['api.github.com', 'github.com', 'codeload.github.com', 'objects.githubusercontent.com'];
  if (in_array($host, $github_hosts, true)) {
    if (!isset($args['headers'])) {
      $args['headers'] = [];
    }
    $args['headers']['User-Agent'] = 'WordPress Plugin';
    if (!empty($token)) {
      $args['headers']['Authorization'] = 'Bearer ' . $token;
    }
    if ($host === 'api.github.com') {
      if (strpos($path, '/zipball') !== false || strpos($path, '/tarball') !== false) {
        $args['headers']['Accept'] = 'application/octet-stream';
      } else {
        $args['headers']['Accept'] = 'application/vnd.github+json';
      }
    }
  }
  // Mask Authorization header before logging
  $headers_for_log = $args['headers'] ?? [];
  if (isset($headers_for_log['Authorization'])) {
    $headers_for_log['Authorization'] = 'Bearer ***redacted***';
  }
  gitup_log('HTTP args prepared for ' . $url . ' headers=' . json_encode($headers_for_log));
  return $args;
}, 10, 2);

/**
 * Hjälpare: markera token-status och (vid 401) skicka mail max 1 gång/dygn.
 * Skickar ENDAST mail om en token faktiskt är satt.
 *
 * @param bool   $ok   True vid 200-svar från api.github.com, annars false.
 * @param string $url  URL som gav svaret (för logg och felsökning).
 */
function gitup_mark_token_status($ok, $url = '') {
  $token = get_option('gitup_github_token', '');
  if ($ok) {
    update_option('gitup_token_status', [
      'status'        => 'valid',
      'last_checked'  => time(),
      'url'           => $url,
    ]);
    update_option('gitup_token_last_verified', time());
    return;
  }

  // Endast varna om token finns – publika repos utan token ska inte maila
  if (empty($token)) {
    return;
  }

  update_option('gitup_token_status', [
    'status'        => 'invalid',
    'last_checked'  => time(),
    'url'           => $url,
  ]);

  // Skicka mail max 1 gång per dygn
  if (false === get_transient('gitup_token_mail_sent')) {
    $admin_email = get_option('admin_email');
    if ($admin_email) {
      $subject = __('GitUp – GitHub token is no longer working', 'gitup');
      $body    = sprintf(
        "%s\n\n%s\n%s\n\n%s\n%s",
        sprintf(
          __('Your GitHub token on "%s" appears to be invalid or has expired.', 'gitup'),
          get_bloginfo('name')
        ),
        sprintf(
          __('Action: <a href="%s">Go to Tools → GitHub Updates and update the token.</a>', 'gitup'),
          esc_url(admin_url('tools.php?page=gitup-settings'))
        ),
        __('Tip: Also verify the scopes (e.g. repo) if you need access to private repositories.', 'gitup'),
        __('Last checked:', 'gitup') . ' ' . date_i18n(get_option('date_format') . ' ' . get_option('time_format')),
        $url ? __('Error at URL:', 'gitup') . ' ' . esc_url_raw($url) : ''
      );
      wp_mail($admin_email, $subject, $body);
      set_transient('gitup_token_mail_sent', true, DAY_IN_SECONDS);
      gitup_log('Email sent to admin about invalid token');
    }
  }
}

/**
 * http_response — centralt ställe att se om token är giltig/ogiltig.
 * Markerar valid vid 200 och invalid vid 401 för api.github.com.
 */
add_filter('http_response', function ($response, $args, $url) {
  $host = parse_url($url, PHP_URL_HOST);
  if ($host !== 'api.github.com') {
    return $response;
  }
  $code = (int) wp_remote_retrieve_response_code($response);
  if ($code === 200) {
    gitup_mark_token_status(true, $url);
  } elseif ($code === 401) { // Unauthorized → trolig utgången/ogiltig token
    gitup_mark_token_status(false, $url);
  }
  return $response;
}, 10, 3);

if (!function_exists('gitup_mark_token_status')) {
  function gitup_mark_token_status($ok, $url = '') {
    gitup_mark_token_status($ok, $url);
  }
}

/**
 * upgrader_pre_download — hämtar zip-filen själv så vi kan kontrollera/validera
 *
 *  - Fångar GitHub-URL:er och laddar ner med våra headers + timeout
 *  - Verifierar Content-Type och de två första byte ('PK') för att undvika att
 *    råka spara en JSON/HTML-sida som zip.
 *  - Returnerar en lokal temp-filväg som WP Upgrader sedan använder.
 */
add_filter('upgrader_pre_download', function ($reply, $package, $upgrader) {
  if (strpos($package, 'api.github.com/repos') === false && strpos($package, 'codeload.github.com') === false) {
    return $reply; // inte vår URL
  }
  gitup_log('Pre-download package URL: ' . $package);
  $token = get_option('gitup_github_token', '');
  $host = parse_url($package, PHP_URL_HOST);
  $path = parse_url($package, PHP_URL_PATH);
  $headers = ['User-Agent' => 'WordPress Plugin'];
  if (!empty($token)) {
    $headers['Authorization'] = 'Bearer ' . $token;
  }
  if ($host === 'api.github.com') {
    if (strpos($path, '/zipball') !== false || strpos($path, '/tarball') !== false) {
      $headers['Accept'] = 'application/octet-stream';
    } else {
      $headers['Accept'] = 'application/vnd.github+json';
    }
  }
  $tmp = wp_tempnam($package);
  if (!$tmp) {
    return new WP_Error('download_failed', __('Could not create temporary file.', 'gitup'));
  }
  $response = wp_remote_get($package, [
    'headers'     => $headers,
    'timeout'     => 300,
    'stream'      => true,
    'filename'    => $tmp,
    'redirection' => 5,
  ]);
  if (is_wp_error($response)) {
    return $response;
  }
  $code = wp_remote_retrieve_response_code($response);
  $ctype = wp_remote_retrieve_header($response, 'content-type');
  gitup_log('Downloaded package response code=' . $code . ' content-type=' . $ctype . ' saved=' . $tmp);
  // Enkel signaturkontroll av zip (PK\x03\x04) om headern inte är tydligt binär
  // Validera att vi faktiskt fick en zip
  $first2 = @file_get_contents($tmp, false, null, 0, 2);
  if ($ctype && stripos($ctype, 'zip') === false && stripos($ctype, 'octet-stream') === false) {
    if ($first2 !== 'PK') {
      return new WP_Error(
        'download_failed',
        sprintf(__('GitHub did not return a ZIP (Content-Type: %s). Check token/access and the release tag.', 'gitup'), $ctype)
      );
    }
  }

  if ($code !== 200) {
    return new WP_Error('download_failed', sprintf(__('GitHub download failed (HTTP %s).', 'gitup'), $code));
  }
  return $tmp; // Låt upgradern använda vår nedladdade fil
}, 10, 3);

/**
 * upgrader_source_selection — välj rätt mapp som pluginrot
 *
 *  Varför? GitHub-zip:ar kan innehålla ytterligare katalognivåer
 *  (t.ex. repo-\nversion\n/...). Vi vill returnera själva pluginroten
 *  så att WordPress inte installerar under fel mappnamn.
 *
 *  Strategi:
 *   1) Om huvudfilen (basename från hook_extra['plugin']) finns i toppnivån → returnera $source
 *   2) Leta efter huvudfilen 1–2 nivåer djupare → returnera dess katalog
 *   3) Fallback: leta efter valfri .php med giltig plugin-header i subdir eller rot
 */
add_filter('upgrader_source_selection', function ($source, $remote_source, $upgrader, $hook_extra) {
  gitup_log('source_selection: source=' . $source . ' remote_source=' . $remote_source . ' hook_extra=' . json_encode($hook_extra));

  // === THEME handling: pick directory that contains a valid style.css (Theme Name header) ===
  if (!empty($hook_extra['theme'])) {
    gitup_log('theme source_selection: expected stylesheet=' . $hook_extra['theme']);
    $theme_root = gitup_find_theme_root_directory($source, $hook_extra['theme']);
    if ($theme_root !== '') {
      $expected_stylesheet = trim((string) $hook_extra['theme'], '/');
      $selected_theme_root = untrailingslashit($theme_root);

      if ($expected_stylesheet !== '' && basename($selected_theme_root) !== $expected_stylesheet) {
        $renamed_theme_root = trailingslashit(dirname($selected_theme_root)) . $expected_stylesheet;
        if (@rename($selected_theme_root, $renamed_theme_root)) {
          $theme_root = trailingslashit($renamed_theme_root);
          gitup_log('theme: renamed selected root to expected stylesheet: ' . $theme_root);
        } else {
          gitup_log('theme: failed to rename selected root ' . $selected_theme_root . ' to ' . $renamed_theme_root);
        }
      }

      gitup_log('theme: selected theme root ' . $theme_root . ' for stylesheet ' . $hook_extra['theme']);
      gitup_log('theme: selected root contents=' . json_encode(glob(trailingslashit($theme_root) . '*')));
      return $theme_root;
    }
    gitup_log('theme: no valid theme root found under source ' . $source);
    gitup_log('theme: source contents=' . json_encode(glob(trailingslashit($source) . '*')));
    return $source;
  }

  $expected_dir = null;
  $main_file = null;
  if (!empty($hook_extra['plugin'])) {
    // ex: gitup/index.php
    $plugin_basename = $hook_extra['plugin'];
    $expected_dir = dirname($plugin_basename); // gitup
    $main_file = basename($plugin_basename);   // index.php
  }

  $join = function ($a, $b) {
    return trailingslashit($a) . ltrim($b, '/');
  };

  if (!empty($main_file)) {
    // Steg 1: kontrollera om pluginets huvudfil ligger i toppnivån
    // 1) Finns pluginets huvudfil i toppnivån av källmappen?
    if (file_exists($join($source, $main_file))) {
      // Inspektera huvudfilens header
      $mf = $join($source, $main_file);
      $snippet = @file_get_contents($mf, false, null, 0, 1024);
      $has_header = false;
      $plugin_name = '';
      if ($snippet !== false) {
        if (preg_match('/^\s*\*?\s*Plugin Name:\s*(.+)$/mi', $snippet, $m)) {
          $has_header = true;
          $plugin_name = trim($m[1]);
        }
      }
      gitup_log('main file header check: file=' . $mf . ' has_header=' . ($has_header ? 'yes' : 'no') . ' plugin_name=' . $plugin_name);
      gitup_log('main file found at top-level. returning ' . $source);
      gitup_log('ls(source)=' . json_encode(glob(trailingslashit($source) . '*')));
      return $source;
    }

    // Steg 2: sök 1–2 nivåer ner efter huvudfilen
    $matches = [];
    $level1 = glob($join($source, '*/' . $main_file));
    if (is_array($level1)) { $matches = array_merge($matches, $level1); }
    $level2 = glob($join($source, '*/*/' . $main_file));
    if (is_array($level2)) { $matches = array_merge($matches, $level2); }

    if (!empty($matches)) {
      $plugin_main_path = $matches[0];
      $plugin_dir_found = dirname($plugin_main_path);
      gitup_log('main file found deeper. plugin_dir_found=' . $plugin_dir_found);
      gitup_log('ls(plugin_dir_found)=' . json_encode(glob(trailingslashit($plugin_dir_found) . '*')));
      return $plugin_dir_found;
    }
  }

  // Steg 3: fallback — leta efter plugin-header i underkataloger
  $dirs = glob(trailingslashit($source) . '*', GLOB_ONLYDIR);
  if (is_array($dirs)) {
    foreach ($dirs as $dir) {
      foreach (glob(trailingslashit($dir) . '*.php') as $phpfile) {
        $contents = @file_get_contents($phpfile, false, null, 0, 8192);
        if ($contents !== false && preg_match('/^\s*\*?\s*Plugin Name:\s*(.+)$/mi', $contents)) {
          gitup_log('fallback subdir plugin header in ' . $dir);
          gitup_log('ls(dir)=' . json_encode(glob(trailingslashit($dir) . '*')));
          return $dir;
        }
      }
    }
  }

  // Steg 3b: fallback — eller i rotkatalogen
  foreach (glob(trailingslashit($source) . '*.php') as $phpfile) {
    $contents = @file_get_contents($phpfile, false, null, 0, 8192);
    if ($contents !== false && preg_match('/^\s*\*?\s*Plugin Name:\s*(.+)$/mi', $contents)) {
      gitup_log('fallback root-level plugin header in ' . $phpfile . ' returning ' . $source);
      gitup_log('ls(source)=' . json_encode(glob(trailingslashit($source) . '*')));
      return $source;
    }
  }

  gitup_log('no valid plugin dir detected; returning original source ' . $source);
  gitup_log('ls(source-final)=' . json_encode(glob(trailingslashit($source) . '*')));
  return $source;
}, 10, 4);

/**
 * upgrader_install_package_result — ren loggning av resultatet
 */
add_filter('upgrader_install_package_result', function ($result, $hook_extra) {
  if (!is_wp_error($result) && is_array($result) && !empty($hook_extra['theme'])) {
    $expected_stylesheet = (string) $hook_extra['theme'];
    $current_destination_name = isset($result['destination_name']) ? (string) $result['destination_name'] : '';

    if ($current_destination_name === '') {
      $result['destination_name'] = $expected_stylesheet;
      gitup_log('install_package_result(theme): filled empty destination_name with stylesheet=' . $expected_stylesheet);
    } elseif ($current_destination_name !== $expected_stylesheet) {
      gitup_log(
        'install_package_result(theme): overriding destination_name from ' .
        $current_destination_name .
        ' to stylesheet=' .
        $expected_stylesheet
      );
      $result['destination_name'] = $expected_stylesheet;
    }
  }

  if (is_wp_error($result)) {
    gitup_log('install_package_result: ERROR code=' . $result->get_error_code() . ' message=' . $result->get_error_message() . ' data=' . json_encode($result->get_error_data()));
  } else {
    gitup_log('install_package_result: OK ' . json_encode($result));
  }
  gitup_log('install_package_result hook_extra=' . json_encode($hook_extra));
  return $result;
}, 10, 2);

/**
 * upgrader_post_install — logga vad som faktiskt kopierades vart
 */
add_action('upgrader_post_install', function ($true, $hook_extra, $result) {
  gitup_log('post_install: destination=' . ($result['destination'] ?? '') . ' source=' . ($result['source'] ?? ''));
  if (!empty($result['destination'])) {
    gitup_log('ls(destination)=' . json_encode(glob(trailingslashit($result['destination']) . '*')));
  }
  if (!empty($result['source'])) {
    gitup_log('ls(source)=' . json_encode(glob(trailingslashit($result['source']) . '*')));
  }
  return $true;
}, 10, 3);

/**
 * upgrader_package_options — tvinga rätt destinationsmapp för plugins
 *
 *  Vi vill försäkra oss om att uppdateringen landar i samma pluginmapp som
 *  tidigare (utan att tagg hamnar i katalognamnet). Detta minskar risken att
 *  WordPress avaktiverar pluginet p.g.a. ändrat mappnamn.
 */
add_filter('upgrader_package_options', function ($options) {
  gitup_log('package_options(before)=' . json_encode($options));

  // Gäller bara pluginuppdateringar där vi vet vilken plugin som uppdateras
  $hook_extra = isset($options['hook_extra']) && is_array($options['hook_extra']) ? $options['hook_extra'] : [];
  if (!empty($hook_extra['plugin'])) {
    $plugin_basename = $hook_extra['plugin']; // ex: gitup/index.php
    $expected_dir = dirname($plugin_basename); // gitup

    if (!empty($options['destination']) && defined('WP_PLUGIN_DIR')) {
      $plugins_dir = trailingslashit(WP_PLUGIN_DIR);
      $options['destination'] = trailingslashit($plugins_dir . $expected_dir);
      $options['destination_name'] = $expected_dir;
      $options['clear_destination'] = true; // rensa/skriv över
      $options['abort_if_destination_exists'] = false;
    }
  }

  // Handle theme updates: ensure destination is the existing theme directory and do not abort if it exists
  if (empty($hook_extra['plugin']) && !empty($hook_extra['theme'])) {
    $theme_stylesheet = $hook_extra['theme']; // e.g. vc-theme-2023
    if (!empty($options['destination'])) {
      // Keep destination at themes root; source_selection renames the extracted
      // theme directory to the active stylesheet so core computes destination_name correctly.
      $options['destination'] = get_theme_root();
      unset($options['destination_name']);
      $options['clear_destination'] = true; // overwrite existing files during update
      $options['abort_if_destination_exists'] = false; // allow existing theme dir
      gitup_log('package_options(theme): stylesheet=' . $theme_stylesheet . ' destination=' . $options['destination']);
    }
  }

  gitup_log('package_options(after)=' . json_encode($options));
  return $options;
});

// Visa en tydlig notice i admin om token markerats som ogiltig, men undertryck vid nyligen ändrad token (ej verifierad än)
add_action('admin_notices', function () {
  if (!current_user_can('manage_options')) return;
  $token = get_option('gitup_github_token', '');
  if (empty($token)) return; // ingen token satt → ingen notice

  $status = get_option('gitup_token_status');
  if (!is_array($status)) return;

  // Visa inte notice om token nyligen uppdaterats men ännu inte verifierats
  $last_checked = isset($status['last_checked']) ? (int) $status['last_checked'] : 0;
  $last_updated = (int) get_option('gitup_token_last_updated');
  if ($last_updated && (!$last_checked || $last_checked < $last_updated)) {
    return; // token ändrades efter senaste check → vänta tills en ny kontroll skett
  }

  if (($status['status'] ?? '') !== 'invalid') return;

  $settings_url = esc_url(admin_url('tools.php?page=gitup-settings'));
echo '<div class="notice notice-error"><p>'
   . esc_html__('GitUp: Your GitHub token appears invalid or has expired.', 'gitup')
   . ' <a href="' . $settings_url . '">' . esc_html__('Update the token here.', 'gitup') . '</a>'
   . '</p></div>';
});
