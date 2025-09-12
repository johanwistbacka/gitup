<?php
/**
 * RG Git Updater — GitHub-baserade uppdateringar för plugins & teman
 *
 * Översikt
 * --------
 *  - Hookar in i WordPress uppdateringssystem (plugins & teman) och matar in
 *    senaste version från GitHub Releases.
 *  - Hämtar paket via codeload.github.com (zip) och ser till att installationen
 *    landar i rätt mapp (utan att mappnamnet får med taggen).
 *  - Har defensiv loggning till debug.log via rg_updater_log() när WP_DEBUG är true.
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
if (!function_exists('rg_updater_log')) {
  /**
   * En liten wrapper för att logga till debug.log när WP_DEBUG är på.
   * Används flitigt i uppgraderingsflödena för att förenkla felsökning.
   *
   * @param mixed $msg  Sträng/array/objekt som loggas (array/objekt pretty-printas).
   */
  function rg_updater_log($msg) {
    if (defined('WP_DEBUG') && WP_DEBUG) {
      if (is_array($msg) || is_object($msg)) {
        $msg = print_r($msg, true);
      }
      error_log('[RG Updater] ' . $msg);
    }
  }
}

if (!class_exists('RgGitUpdaterClass')) {
  /**
   * Huvudklass som integrerar mot GitHub och WordPress uppdaterings-API.
   *
   * Viktiga ansvarsområden:
   *  - Annonsera uppdateringar (plugins/teman) via `pre_set_site_transient_update_*`.
   *  - Visa info-popups via `plugins_api` och `themes_api`.
   *  - Hämta senaste release och cacha resultat.
   *  - Respektera inställningen för förhandsreleaser (beta/rc).
   */
  class RgGitUpdaterClass {
    // Singleton-instans för att undvika dubbla hook-registreringar
    private static $instance = null;
    // Token från inställningar – används som Authorization mot GitHub
    private $github_token;

    /**
     * Registrerar samtliga filter/hooks för plugins & teman.
     * Gör inga tunga anrop här – endast hook-registrering.
     */
    private function __construct() {
      $this->github_token = get_option('rgplugins_github_token', '');
      // === Plugins: mata in uppdateringar + info-popup ===
      add_filter('pre_set_site_transient_update_plugins', [$this, 'check_for_update']);
      add_filter('plugins_api', [$this, 'plugin_info'], 10, 3);
      // === Teman: mata in uppdateringar + info-popup ===
      add_filter('pre_set_site_transient_update_themes', [$this, 'check_for_theme_update']);
      add_filter('themes_api', [$this, 'theme_info'], 10, 3);
      rg_updater_log('RgGitUpdaterClass: theme update hooks registered');
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
        if (empty($plugin_info['UpdateURI'])) {
          continue; // Endast plugins med Update URI
        }
        $repo_url = $plugin_info['UpdateURI'];
        $latest_release = $this->get_latest_github_release($repo_url);
        if ($latest_release === 'N/A') {
          continue;
        }
        if (version_compare($plugin_info['Version'], $latest_release, '<')) {
          $plugin_slug = dirname($plugin_path);
          $repo_path   = parse_url($repo_url, PHP_URL_PATH);
          // Använd codeload för binär zip utan Accept-förhandling
          $zip_url     = "https://codeload.github.com{$repo_path}/zip/refs/tags/{$latest_release}";
          rg_updater_log('Update available for ' . $plugin_path . ' -> tag ' . $latest_release . ' package ' . $zip_url);
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
        $repo_url = $plugin_info['UpdateURI'];
        $repo_path = parse_url($repo_url, PHP_URL_PATH);
        $include_prereleases = get_option('rgplugins_include_prereleases', '0') === '1';
        $api_url = $include_prereleases
          ? "https://api.github.com/repos{$repo_path}/releases?per_page=10"
          : "https://api.github.com/repos{$repo_path}/releases/latest";

        $headers = ['User-Agent' => 'WordPress Plugin', 'Accept' => 'application/vnd.github+json'];
        if (!empty($this->github_token)) {
          $headers['Authorization'] = 'Bearer ' . $this->github_token;
        }
        $response = wp_remote_get($api_url, ['headers' => $headers, 'timeout' => 20, 'redirection' => 3]);
        if (is_wp_error($response)) {
          return $res;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
          return $res;
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($include_prereleases) {
          $tag = '';
          if (is_array($data)) {
            foreach ($data as $rel) {
              if (!empty($rel['draft'])) { continue; }
              if (!empty($rel['tag_name'])) { $tag = $rel['tag_name']; break; }
            }
          }
        } else {
          $tag = is_array($data) ? ($data['tag_name'] ?? '') : '';
        }
        if (empty($tag)) {
          return $res;
        }
        $zip_url = "https://codeload.github.com{$repo_path}/zip/refs/tags/{$tag}";
        rg_updater_log('Plugin info for ' . $args->slug . ' -> tag ' . $tag . ' package ' . $zip_url);

        $info                = new stdClass();
        $info->name          = $plugin_info['Name'];
        $info->slug          = $args->slug;
        $info->version       = $tag;
        $info->author        = $plugin_info['Author'];
        $info->homepage      = $repo_url;
        $info->download_link = $zip_url;
        $info->sections      = [
          'description' => !empty($plugin_info['Description']) ? $plugin_info['Description'] : 'Uppdateringar hämtas från GitHub.',
          'changelog'   => isset($data['body']) ? wp_kses_post($data['body']) : '',
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
      $repo_path = parse_url($repo_url, PHP_URL_PATH);
      $include_prereleases = get_option('rgplugins_include_prereleases', '0') === '1';

      // Endpoint: stabil endast eller lista (inkl. prereleases)
      $api_url = $include_prereleases
        ? "https://api.github.com/repos{$repo_path}/releases?per_page=10"
        : "https://api.github.com/repos{$repo_path}/releases/latest";

      // Separat cache-nyckel för stable vs pre
      $cache_key = 'github_release_' . md5($repo_url . '|' . ($include_prereleases ? 'pre' : 'stable'));
      $cached    = get_transient($cache_key);
      if ($cached && $cached !== 'N/A') {
        return $cached;
      }

      $headers = ['User-Agent' => 'WordPress Plugin', 'Accept' => 'application/vnd.github+json'];
      if (!empty($this->github_token)) {
        $headers['Authorization'] = 'Bearer ' . $this->github_token;
      }
      $response = wp_remote_get($api_url, ['headers' => $headers, 'timeout' => 20, 'redirection' => 3]);
      if (is_wp_error($response)) {
        set_transient($cache_key, 'N/A', 5 * MINUTE_IN_SECONDS);
        return 'N/A';
      }
      $code = (int) wp_remote_retrieve_response_code($response);
      if ($code !== 200) {
        set_transient($cache_key, 'N/A', 5 * MINUTE_IN_SECONDS);
        return 'N/A';
      }
      $data = json_decode(wp_remote_retrieve_body($response), true);

      if ($include_prereleases) {
        // Välj första icke-draft release (kan vara prerelease)
        $tag = 'N/A';
        if (is_array($data)) {
          foreach ($data as $rel) {
            if (!empty($rel['draft'])) { continue; }
            if (!empty($rel['tag_name'])) { $tag = $rel['tag_name']; break; }
          }
        }
      } else {
        $tag = is_array($data) ? ($data['tag_name'] ?? 'N/A') : 'N/A';
      }

      if ($tag !== 'N/A') {
        set_transient($cache_key, $tag, HOUR_IN_SECONDS);
      } else {
        set_transient($cache_key, 'N/A', 5 * MINUTE_IN_SECONDS);
      }
      return $tag;
    }
    /**
     * Annonserar tema-uppdateringar till WordPress core.
     * Fungerar analogt med plugin-flödet.
     *
     * @param object $transient  WordPress transient för tema-uppdateringar.
     * @return object            Modifierad transient.
     */
    public function check_for_theme_update($transient) {
      rg_updater_log('check_for_theme_update: start');
      if (!is_object($transient)) {
        $transient = new stdClass();
      }
      if (!isset($transient->checked)) {
        rg_updater_log('check_for_theme_update: no checked property on transient');
        return $transient;
      }
      $themes = wp_get_themes();
      foreach ($themes as $stylesheet => $theme) {
        $repo_url = $theme->get('ThemeURI');
        if (empty($repo_url) || strpos($repo_url, 'github.com') === false) {
          continue;
        }
        $latest_release = $this->get_latest_github_release($repo_url);
        rg_updater_log('check_for_theme_update: theme=' . $stylesheet . ' current=' . $theme->get('Version') . ' latest=' . $latest_release);
        if ($latest_release === 'N/A') {
          continue;
        }
        if (version_compare($theme->get('Version'), $latest_release, '<')) {
          $repo_path = parse_url($repo_url, PHP_URL_PATH);
          $zip_url = "https://codeload.github.com{$repo_path}/zip/refs/tags/{$latest_release}";
          rg_updater_log('Theme update available for ' . $stylesheet . ' -> tag ' . $latest_release . ' package ' . $zip_url);
          $transient->response[$stylesheet] = [
            'theme'       => $stylesheet,
            'new_version' => $latest_release,
            'package'     => $zip_url,
            'url'         => $repo_url,
          ];
        }
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
      rg_updater_log('theme_info: request for slug=' . $args->slug);
      $themes = wp_get_themes();
      foreach ($themes as $stylesheet => $theme) {
        if ($stylesheet !== $args->slug) {
          continue;
        }
        $repo_url = $theme->get('ThemeURI');
        if (empty($repo_url) || strpos($repo_url, 'github.com') === false) {
          return $res;
        }
        $repo_path = parse_url($repo_url, PHP_URL_PATH);
        $include_prereleases = get_option('rgplugins_include_prereleases', '0') === '1';
        $api_url = $include_prereleases
          ? "https://api.github.com/repos{$repo_path}/releases?per_page=10"
          : "https://api.github.com/repos{$repo_path}/releases/latest";
        $headers = ['User-Agent' => 'WordPress Theme', 'Accept' => 'application/vnd.github+json'];
        if (!empty($this->github_token)) {
          $headers['Authorization'] = 'Bearer ' . $this->github_token;
        }
        $response = wp_remote_get($api_url, ['headers' => $headers, 'timeout' => 20, 'redirection' => 3]);
        if (is_wp_error($response)) {
          rg_updater_log('theme_info: wp_remote_get error: ' . $response->get_error_message());
          return $res;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
          rg_updater_log('theme_info: http code=' . $code);
          return $res;
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($include_prereleases) {
          $tag = '';
          if (is_array($data)) {
            foreach ($data as $rel) {
              if (!empty($rel['draft'])) { continue; }
              if (!empty($rel['tag_name'])) { $tag = $rel['tag_name']; break; }
            }
          }
        } else {
          $tag = is_array($data) ? ($data['tag_name'] ?? '') : '';
        }
        if (empty($tag)) {
          rg_updater_log('theme_info: no tag resolved');
          return $res;
        }
        $zip_url = "https://codeload.github.com{$repo_path}/zip/refs/tags/{$tag}";
        rg_updater_log('Theme info for ' . $args->slug . ' -> tag ' . $tag . ' package ' . $zip_url);
        $info = new stdClass();
        $info->name = $theme->get('Name');
        $info->slug = $args->slug;
        $info->version = $tag;
        $info->author = $theme->get('Author');
        $info->preview_url = $theme->get('ThemeURI');
        $info->download_link = $zip_url;
        $info->sections = [
          'description' => $theme->get('Description'),
          'changelog'   => isset($data['body']) ? wp_kses_post($data['body']) : '',
        ];
        return $info;
      }
      return $res;
    }
  }

  RgGitUpdaterClass::get_instance();
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
  $token = get_option('rgplugins_github_token', '');
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
  rg_updater_log('HTTP args prepared for ' . $url . ' headers=' . json_encode($headers_for_log));
  return $args;
}, 10, 2);

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
  rg_updater_log('Pre-download package URL: ' . $package);
  $token = get_option('rgplugins_github_token', '');
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
    return new WP_Error('download_failed', __('Kunde inte skapa temporär fil.', 'kmg-transport-plugin'));
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
  rg_updater_log('Downloaded package response code=' . $code . ' content-type=' . $ctype . ' saved=' . $tmp);
  // Enkel signaturkontroll av zip (PK\x03\x04) om headern inte är tydligt binär
  // Validera att vi faktiskt fick en zip
  $first2 = @file_get_contents($tmp, false, null, 0, 2);
  if ($ctype && stripos($ctype, 'zip') === false && stripos($ctype, 'octet-stream') === false) {
    if ($first2 !== 'PK') {
      return new WP_Error(
        'download_failed',
        sprintf(__('GitHub svarade inte med en zip (Content-Type: %s). Kontrollera token/åtkomst och release-taggen.', 'kmg-transport-plugin'), $ctype)
      );
    }
  }

  if ($code !== 200) {
    return new WP_Error('download_failed', sprintf(__('GitHub-nedladdning misslyckades (HTTP %s).', 'kmg-transport-plugin'), $code));
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
  rg_updater_log('source_selection: source=' . $source . ' remote_source=' . $remote_source . ' hook_extra=' . json_encode($hook_extra));

  // === THEME handling: pick directory that contains a valid style.css (Theme Name header) ===
  if (!empty($hook_extra['theme'])) {
    $is_theme_dir = function ($dir) {
      $style = trailingslashit($dir) . 'style.css';
      if (!file_exists($style)) return false;
      $contents = @file_get_contents($style, false, null, 0, 8192);
      if ($contents === false) return false;
      return (bool)preg_match('/^\s*Theme\s*Name\s*:\s*(.+)$/mi', $contents);
    };

    // 1) Already a theme root?
    if ($is_theme_dir($source)) {
      rg_updater_log('theme: style.css found at top-level; returning source');
      return $source;
    }

    // 2) Search one level deep
    $dirs_lvl1 = glob(trailingslashit($source) . '*', GLOB_ONLYDIR) ?: [];
    foreach ($dirs_lvl1 as $d1) {
      if ($is_theme_dir($d1)) {
        rg_updater_log('theme: style.css found one level deep in ' . $d1);
        return $d1;
      }
    }

    // 3) Search two levels deep (monorepo patterns like /themes/<slug>/)
    foreach ($dirs_lvl1 as $d1) {
      $dirs_lvl2 = glob(trailingslashit($d1) . '*', GLOB_ONLYDIR) ?: [];
      foreach ($dirs_lvl2 as $d2) {
        if ($is_theme_dir($d2)) {
          rg_updater_log('theme: style.css found two levels deep in ' . $d2);
          return $d2;
        }
      }
    }
    // If not found, fall through to plugin logic/fallbacks below (WP will error if no valid theme)
  }

  $expected_dir = null;
  $main_file = null;
  if (!empty($hook_extra['plugin'])) {
    // ex: kmg-transport-plugin/index.php
    $plugin_basename = $hook_extra['plugin'];
    $expected_dir = dirname($plugin_basename); // kmg-transport-plugin
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
      rg_updater_log('main file header check: file=' . $mf . ' has_header=' . ($has_header ? 'yes' : 'no') . ' plugin_name=' . $plugin_name);
      rg_updater_log('main file found at top-level. returning ' . $source);
      rg_updater_log('ls(source)=' . json_encode(glob(trailingslashit($source) . '*')));
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
      rg_updater_log('main file found deeper. plugin_dir_found=' . $plugin_dir_found);
      rg_updater_log('ls(plugin_dir_found)=' . json_encode(glob(trailingslashit($plugin_dir_found) . '*')));
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
          rg_updater_log('fallback subdir plugin header in ' . $dir);
          rg_updater_log('ls(dir)=' . json_encode(glob(trailingslashit($dir) . '*')));
          return $dir;
        }
      }
    }
  }

  // Steg 3b: fallback — eller i rotkatalogen
  foreach (glob(trailingslashit($source) . '*.php') as $phpfile) {
    $contents = @file_get_contents($phpfile, false, null, 0, 8192);
    if ($contents !== false && preg_match('/^\s*\*?\s*Plugin Name:\s*(.+)$/mi', $contents)) {
      rg_updater_log('fallback root-level plugin header in ' . $phpfile . ' returning ' . $source);
      rg_updater_log('ls(source)=' . json_encode(glob(trailingslashit($source) . '*')));
      return $source;
    }
  }

  rg_updater_log('no valid plugin dir detected; returning original source ' . $source);
  rg_updater_log('ls(source-final)=' . json_encode(glob(trailingslashit($source) . '*')));
  return $source;
}, 10, 4);

/**
 * upgrader_install_package_result — ren loggning av resultatet
 */
add_filter('upgrader_install_package_result', function ($result, $hook_extra) {
  if (is_wp_error($result)) {
    rg_updater_log('install_package_result: ERROR code=' . $result->get_error_code() . ' message=' . $result->get_error_message() . ' data=' . json_encode($result->get_error_data()));
  } else {
    rg_updater_log('install_package_result: OK ' . json_encode($result));
  }
  rg_updater_log('install_package_result hook_extra=' . json_encode($hook_extra));
  return $result;
}, 10, 2);

/**
 * upgrader_post_install — logga vad som faktiskt kopierades vart
 */
add_action('upgrader_post_install', function ($true, $hook_extra, $result) {
  rg_updater_log('post_install: destination=' . ($result['destination'] ?? '') . ' source=' . ($result['source'] ?? ''));
  if (!empty($result['destination'])) {
    rg_updater_log('ls(destination)=' . json_encode(glob(trailingslashit($result['destination']) . '*')));
  }
  if (!empty($result['source'])) {
    rg_updater_log('ls(source)=' . json_encode(glob(trailingslashit($result['source']) . '*')));
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
  rg_updater_log('package_options(before)=' . json_encode($options));

  // Gäller bara pluginuppdateringar där vi vet vilken plugin som uppdateras
  $hook_extra = isset($options['hook_extra']) && is_array($options['hook_extra']) ? $options['hook_extra'] : [];
  if (!empty($hook_extra['plugin'])) {
    $plugin_basename = $hook_extra['plugin']; // ex: kmg-transport-plugin/index.php
    $expected_dir = dirname($plugin_basename); // kmg-transport-plugin

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
      // Point to the exact theme dir
      $themes_dir = trailingslashit(get_theme_root());
      $options['destination'] = trailingslashit($themes_dir . $theme_stylesheet);
      $options['destination_name'] = $theme_stylesheet;
      $options['clear_destination'] = true; // overwrite existing files during update
      $options['abort_if_destination_exists'] = false; // allow existing theme dir
    }
  }

  rg_updater_log('package_options(after)=' . json_encode($options));
  return $options;
});