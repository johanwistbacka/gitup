<?php
if (!class_exists("RgGitUpdater")) {
  class RgGitUpdater
  {
    private static $instance = null;
    private $github_api_url = "https://api.github.com/repos/";
    private $github_token;

    private function __construct()
    {
      $this->github_token = get_option('rgplugins_github_token', '');
      
      add_filter("pre_set_site_transient_update_plugins", [
        $this,
        "check_for_update",
      ]);
      add_filter("plugins_api", [$this, "plugin_info"], 10, 3);
    }

    public static function get_instance()
    {
      if (self::$instance === null) {
        self::$instance = new self();
      }
      return self::$instance;
    }

    public function check_for_update($transient)
    {
      if (!is_object($transient)) {
        $transient = new stdClass();
      }
      if (!isset($transient->checked)) {
        return $transient;
      }

      // Hämta alla installerade plugins
      $plugins = get_plugins();

      foreach ($plugins as $plugin_path => $plugin_info) {
        if (!isset($plugin_info["UpdateURI"])) {
          continue; // Hoppa över plugins utan en UpdateURI
        }
        
        $repo_url = $plugin_info["UpdateURI"];
        $latest_release = $this->get_latest_github_release($repo_url);

        if ($latest_release !== "N/A" && version_compare($plugin_info["Version"], $latest_release, '<')) {
          $plugin_slug = dirname($plugin_path);
          $transient->response[$plugin_path] = (object) [
            'slug' => $plugin_slug,
            'new_version' => $latest_release,
            'package' => "$repo_url/releases/latest",
          ];
        }
      }

      return $transient;
    }

    private function get_latest_github_release($repo_url)
    {
      $repo_path = parse_url($repo_url, PHP_URL_PATH);
      $api_url = "https://api.github.com/repos$repo_path/releases/latest";

      $cache_key = 'github_release_' . md5($repo_url);
      $cached_release = get_transient($cache_key);
      if ($cached_release) {
        return $cached_release;
      }

      $headers = [
        'User-Agent' => 'WordPress Plugin'
      ];
      
      if (!empty($this->github_token)) {
        $headers['Authorization'] = 'Bearer ' . $this->github_token;
      }
      
      $args = ['headers' => $headers];
      $response = wp_remote_get($api_url, $args);

      if (is_wp_error($response)) {
        return "N/A";
      }

      $body = wp_remote_retrieve_body($response);
      $data = json_decode($body, true);
      $latest_release = $data['tag_name'] ?? "N/A";

      set_transient($cache_key, $latest_release, HOUR_IN_SECONDS);

      return $latest_release;
    }
  }

  RgGitUpdater::get_instance();
}

// Skapa en global lista över registrerade plugins
global $rg_plugins_list;
if (!isset($rg_plugins_list)) {
    $rg_plugins_list = [];
}

// Funktion för att registrera plugins
if (!function_exists('rgplugins_register_plugin')) {
  function rgplugins_register_plugin($plugin_data)
  {
      global $rg_plugins_list;
      if (!isset($rg_plugins_list)) {
          $rg_plugins_list = [];
      }
      $rg_plugins_list[] = $plugin_data;
  }
}

// Se till att alla tillägg registreras innan adminmenyn skapas
add_action('admin_init', function () {
    global $rg_plugins_list;
    if (!isset($rg_plugins_list)) {
        $rg_plugins_list = [];
    }
});