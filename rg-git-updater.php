<?php
if (!function_exists('rg_updater_log')) {
  function rg_updater_log($msg) {
    if (defined('WP_DEBUG') && WP_DEBUG) {
      if (is_array($msg) || is_object($msg)) {
        $msg = print_r($msg, true);
      }
      error_log('[RG Updater] ' . $msg);
    }
  }
}

if (!class_exists('RgGitUpdater')) {
  class RgGitUpdater {
    private static $instance = null;
    private $github_token;

    private function __construct() {
      $this->github_token = get_option('rgplugins_github_token', '');
      add_filter('pre_set_site_transient_update_plugins', [$this, 'check_for_update']);
      add_filter('plugins_api', [$this, 'plugin_info'], 10, 3);
    }

    public static function get_instance() {
      if (self::$instance === null) {
        self::$instance = new self();
      }
      return self::$instance;
    }

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
        $api_url   = "https://api.github.com/repos{$repo_path}/releases/latest";

        $headers = ['User-Agent' => 'WordPress Plugin'];
        if (!empty($this->github_token)) {
          $headers['Authorization'] = 'Bearer ' . $this->github_token;
        }
        $response = wp_remote_get($api_url, ['headers' => $headers]);
        if (is_wp_error($response)) {
          return $res;
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        $tag  = $data['tag_name'] ?? '';
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

    private function get_latest_github_release($repo_url) {
      $repo_path = parse_url($repo_url, PHP_URL_PATH);
      $api_url   = "https://api.github.com/repos{$repo_path}/releases/latest";

      $cache_key = 'github_release_' . md5($repo_url);
      $cached    = get_transient($cache_key);
      if ($cached) {
        return $cached;
      }

      $headers = ['User-Agent' => 'WordPress Plugin'];
      if (!empty($this->github_token)) {
        $headers['Authorization'] = 'Bearer ' . $this->github_token;
      }
      $response = wp_remote_get($api_url, ['headers' => $headers]);
      if (is_wp_error($response)) {
        return 'N/A';
      }
      $data = json_decode(wp_remote_retrieve_body($response), true);
      $tag  = $data['tag_name'] ?? 'N/A';

      set_transient($cache_key, $tag, HOUR_IN_SECONDS);
      return $tag;
    }
  }

  RgGitUpdater::get_instance();
}

// Lägg till Authorization-header på GitHub-anrop (inkl. zip-nedladdning) + korrekt Accept
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
  rg_updater_log('HTTP args prepared for ' . $url . ' headers=' . json_encode($args['headers'] ?? []));
  return $args;
}, 10, 2);

// Intercepta nedladdning av GitHub-zip så att auth följer med och verifiera zip
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

// Se till att extraherad mapp pekar på själva pluginroten även om zip:en har extra nivåer (utan att rename:a)
add_filter('upgrader_source_selection', function ($source, $remote_source, $upgrader, $hook_extra) {
  rg_updater_log('source_selection: source=' . $source . ' remote_source=' . $remote_source . ' hook_extra=' . json_encode($hook_extra));

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

    // 2) Leta efter huvudfilen 1–2 nivåer ner
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

  // 3) Fallback: försök hitta en katalog som innehåller en giltig plugin-header om huvudfilens namn avviker
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

  // 3b) Kolla om pluginet ligger direkt i rot av $source (utan underkatalog)
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

// Logga resultatet av install_package
add_filter('upgrader_install_package_result', function ($result, $hook_extra) {
  if (is_wp_error($result)) {
    rg_updater_log('install_package_result: ERROR code=' . $result->get_error_code() . ' message=' . $result->get_error_message() . ' data=' . json_encode($result->get_error_data()));
  } else {
    rg_updater_log('install_package_result: OK ' . json_encode($result));
  }
  rg_updater_log('install_package_result hook_extra=' . json_encode($hook_extra));
  return $result;
}, 10, 2);

// Post-install loggning
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

// Tvinga destinationens katalognamn till befintlig plugin-katalog (utan tag i namnet)
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

  rg_updater_log('package_options(after)=' . json_encode($options));
  return $options;
});