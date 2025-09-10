<?php
// Skapa en meny för plugininställningar
add_action("admin_menu", function () {
    add_options_page(
      "RG Plugins Info",
      "RG Plugins",
      "manage_options",
      "rgplugins-settings",
      "rgplugins_settings_page"
    );
});

// Funktion för att hämta alla installerade plugins med en GitHub Update URI
if (!function_exists('get_github_plugins')) {
    function get_github_plugins()
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins = get_plugins();
        $github_plugins = [];

        foreach ($all_plugins as $plugin_path => $plugin_info) {
            if (!isset($plugin_info["UpdateURI"])) {
                continue;
            }

            $update_uri = $plugin_info["UpdateURI"];

            if (strpos($update_uri, "github.com") !== false) {
                $github_plugins[] = [
                    "name" => $plugin_info["Name"],
                    "version" => $plugin_info["Version"],
                    "author" => $plugin_info["Author"],
                    "github" => $update_uri,
                    "latest_release" => get_latest_github_release($update_uri)
                ];
            }
        }

        return $github_plugins;
    }
}

// Funktion för att visa plugin-information i adminpanelen
if (!function_exists('rgplugins_settings_page')) {
    function rgplugins_settings_page()
    {
        $github_plugins = get_github_plugins();
        ?>
        <div class="wrap">
            <h1>RG Plugins</h1>
            <table class="widefat fixed">
                <thead>
                    <tr>
                        <th>Plugin</th>
                        <th>Version</th>
                        <th>Författare</th>
                        <th>GitHub Repository</th>
                        <th>Senaste Release</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($github_plugins as $plugin) : ?>
                        <tr>
                            <td><?php echo esc_html($plugin["name"]); ?></td>
                            <td><?php echo esc_html($plugin["version"]); ?></td>
                            <td><?php echo esc_html($plugin["author"]); ?></td>
                            <td><a href="<?php echo esc_url($plugin["github"]); ?>" target="_blank"><?php echo esc_html($plugin["github"]); ?></a></td>
                            <td><?php echo esc_html($plugin["latest_release"]); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}

  
  // Funktion för att hämta senaste releasen från GitHub (stöd för privata repos)
  function get_latest_github_release($repo_url, $is_private = false)
  {
      $repo_path = parse_url($repo_url, PHP_URL_PATH);
      $api_url = "https://api.github.com/repos$repo_path/releases/latest";
      
      $cache_key = 'github_release_' . md5($repo_url);
      $cached_release = get_transient($cache_key);
      if ($cached_release) {
          return $cached_release;
      }
  
      $github_token = get_option('rgplugins_github_token', '');
  
      $headers = [
          'User-Agent' => 'WordPress Plugin'
      ];
  
      if (!empty($github_token) || $is_private) {
          if (empty($github_token)) {
              return "Privat repo kräver en GitHub-token";
          }
          $headers['Authorization'] = 'Bearer ' . $github_token;
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

  /*
  // Visa plugin-information på inställningssidan
  function rgplugins_settings_page()
  {
      global $rg_plugins_list;
      
      $default_plugins = [
          
      ];
      
      $plugins = array_merge($default_plugins, $rg_plugins_list);
      ?>
      <div class="wrap">
          <h1>RG Plugins</h1>
          <form method="post" action="options.php">
              <?php
              settings_fields('rgplugins_settings_group');
              do_settings_sections('rgplugins-settings');
              submit_button();
              ?>
          </form>
          <table class="widefat fixed">
              <thead>
                  <tr>
                      <th>Plugin</th>
                      <th>Version</th>
                      <th>Författare</th>
                      <th>GitHub Repository</th>
                      <th>Senaste Release</th>
                  </tr>
              </thead>
              <tbody>
                  <?php foreach ($plugins as $plugin) : ?>
                      <tr>
                          <td><?php echo esc_html($plugin["name"]); ?></td>
                          <td><?php echo esc_html($plugin["version"]); ?></td>
                          <td><?php echo esc_html($plugin["author"]); ?></td>
                          <td><a href="<?php echo esc_url($plugin["github"]); ?>" target="_blank"><?php echo esc_html($plugin["github"]); ?></a></td>
                          <td><?php echo esc_html($plugin["latest_release"]); ?></td>
                      </tr>
                  <?php endforeach; ?>
              </tbody>
          </table>
      </div>
      <?php
  }
  */
  // Registrera inställningar för GitHub-token
  add_action('admin_init', function () {
      register_setting('rgplugins_settings_group', 'rgplugins_github_token');
      add_settings_section('rgplugins_settings_section', 'GitHub API-inställningar', null, 'rgplugins-settings');
      add_settings_field(
          'rgplugins_github_token',
          'GitHub Token',
          function () {
              $token = get_option('rgplugins_github_token', '');
              echo '<input type="text" name="rgplugins_github_token" value="' . esc_attr($token) . '" class="regular-text">';
          },
          'rgplugins-settings',
          'rgplugins_settings_section'
      );
  });