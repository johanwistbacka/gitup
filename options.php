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
if (!function_exists("get_github_plugins")) {
  function get_github_plugins($force_refresh = false)
  {
    if (!function_exists("get_plugins")) {
      require_once ABSPATH . "wp-admin/includes/plugin.php";
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
          "latest_release" => get_latest_github_release($update_uri, false, $force_refresh),
        ];
      }
    }

    return $github_plugins;
  }
}

// Funktion för att visa plugin-information i adminpanelen
if (!function_exists("rgplugins_settings_page")) {
  function rgplugins_settings_page()
  {
    $force_refresh = isset($_GET['rgplugins_refresh']);
    $github_plugins = get_github_plugins($force_refresh); ?>
        <div class="wrap">
            <h1>RG Plugins</h1>
            <?php
              // Build a refresh URL that toggles rgplugins_refresh=1
              $refresh_url = add_query_arg('rgplugins_refresh', '1');
              if ($force_refresh) {
                echo '<div class="updated notice"><p>' . esc_html__('Listan uppdaterades från GitHub (cache bypassad).', 'kmg-transport-plugin') . '</p></div>';
              }
            ?>
            <p>
              <a href="<?php echo esc_url($refresh_url); ?>" class="button"><?php echo esc_html__('Uppdatera lista', 'kmg-transport-plugin'); ?></a>
            </p>
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
                    <?php foreach ($github_plugins as $plugin): ?>
                        <tr>
                            <td><?php echo esc_html($plugin["name"]); ?></td>
                            <td><?php echo esc_html($plugin["version"]); ?></td>
                            <td><?php echo esc_html($plugin["author"]); ?></td>
                            <td><a href="<?php echo esc_url(
                              $plugin["github"]
                            ); ?>" target="_blank"><?php echo esc_html(
  $plugin["github"]
); ?></a></td>
                            <td><?php echo esc_html(
                              $plugin["latest_release"]
                            ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
        </table>

        <?php $ajax_nonce = wp_create_nonce('rgplugins_test_github_token'); $ajax_url = admin_url('admin-ajax.php'); ?>
        <?php // Visa ev. validerings-/sparmeddelanden
        settings_errors(); ?>

        <form method="post" action="options.php">
            <?php
            // Lägg till nödvändiga fält för options-API (nonce, option group etc.)
            settings_fields('rgplugins_settings_group');

            // Rendera sektioner och fält som registrerats för denna sida
            do_settings_sections('rgplugins-settings');

            // Spara-knapp
            submit_button(__('Spara inställningar', 'kmg-transport-plugin'));

            // Testa GitHub-token
            submit_button(__('Testa GitHub-anslutning', 'kmg-transport-plugin'), 'secondary', 'test_github_token', false);

            // Testa via AJAX (ingen omladdning)
            echo '<button type="button" id="rgplugins-test-ajax" class="button">' . esc_html__('Testa utan omladdning', 'kmg-transport-plugin') . '</button>';
            echo '<span id="rgplugins-test-ajax-status" style="margin-left:8px;"></span>';
            ?>
        </form>
        <script>
        (function(){
          var btn = document.getElementById('rgplugins-test-ajax');
          if(!btn) return;
          var statusEl = document.getElementById('rgplugins-test-ajax-status');
          var ajaxUrl = <?php echo json_encode($ajax_url); ?>;
          var nonce = <?php echo json_encode($ajax_nonce); ?>;
          btn.addEventListener('click', function(){
            btn.disabled = true;
            statusEl.textContent = 'Testar…';
            var formData = new FormData();
            formData.append('action', 'rgplugins_test_github_token');
            formData.append('_ajax_nonce', nonce);
            fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: formData })
              .then(function(res){ return res.json(); })
              .then(function(json){
                if (json.success) {
                  statusEl.textContent = json.data && json.data.message ? json.data.message : 'Lyckad anslutning!';
                } else {
                  var msg = (json.data && json.data.message) ? json.data.message : 'Misslyckad anslutning.';
                  statusEl.textContent = msg;
                }
              })
              .catch(function(err){
                statusEl.textContent = 'Fel: ' + err;
              })
              .finally(function(){
                setTimeout(function(){ btn.disabled = false; }, 800);
              });
          });
        })();
        </script>
    </div>
        <?php
  }
}

// Funktion för att hämta senaste releasen från GitHub (stöd för privata repos)
function get_latest_github_release($repo_url, $is_private = false, $force_refresh = false)
{
  $repo_path = parse_url($repo_url, PHP_URL_PATH);
  $api_url = "https://api.github.com/repos$repo_path/releases/latest";

  $cache_key = "github_release_" . md5($repo_url);
  if ($force_refresh) {
    delete_transient($cache_key);
  }
  $cached_release = get_transient($cache_key);
  // Om vi har ett giltigt cacheat värde (inte N/A), använd det.
  if (!$force_refresh && $cached_release && $cached_release !== 'N/A') {
    return $cached_release;
  }

  $github_token = get_option("rgplugins_github_token", "");

  $headers = [
    "User-Agent" => "WordPress Plugin",
  ];

  if (!empty($github_token) || $is_private) {
    if (empty($github_token)) {
      return "Privat repo kräver en GitHub-token";
    }
    $headers["Authorization"] = "Bearer " . $github_token;
  }

  $args = ["headers" => $headers];
  $response = wp_remote_get($api_url, $args);

  if (is_wp_error($response)) {
    return "N/A";
  }

  $body = wp_remote_retrieve_body($response);
  $data = json_decode($body, true);
  $latest_release = $data["tag_name"] ?? "N/A";

  set_transient($cache_key, $latest_release, HOUR_IN_SECONDS);

  return $latest_release;
}

// Registrera inställningar för GitHub-token
add_action("admin_init", function () {
  register_setting("rgplugins_settings_group", "rgplugins_github_token");
  add_settings_section(
    "rgplugins_settings_section",
    "GitHub API-inställningar",
    function () {
      echo '<p>Skapa en <a href="https://github.com/settings/tokens" target="_blank">personlig åtkomsttoken</a> på GitHub med behörighet <code>repo</code> om du behöver komma åt privata repos.</p>';
    },
    "rgplugins-settings"
  );
  add_settings_field(
    "rgplugins_github_token",
    "GitHub Token",
    function () {
      $token = get_option("rgplugins_github_token", "");
      echo '<input type="password" name="rgplugins_github_token" value="' .
        esc_attr($token) .
        '" class="regular-text" autocomplete="off" placeholder="ghp_...">';
    },
    "rgplugins-settings",
    "rgplugins_settings_section"
  );
});

// Hantera test av GitHub-token när knappen trycks
add_action('admin_post_test_github_token', function () {
  if (!current_user_can('manage_options')) {
    wp_die(__('Du saknar behörighet.', 'kmg-transport-plugin'));
  }

  check_admin_referer('rgplugins_settings_group-options');
  $token = get_option('rgplugins_github_token', '');
  $args = [
    'headers' => [
      'User-Agent' => 'WordPress Plugin',
      'Authorization' => 'Bearer ' . $token,
    ]
  ];
  $response = wp_remote_get('https://api.github.com/user', $args);

  if (is_wp_error($response)) {
    add_settings_error('rgplugins_github_token', 'github_token_test', __('Fel vid anslutning: ', 'kmg-transport-plugin') . $response->get_error_message(), 'error');
  } else {
    $code = wp_remote_retrieve_response_code($response);
    if ($code === 200) {
      $body = json_decode(wp_remote_retrieve_body($response), true);
      add_settings_error('rgplugins_github_token', 'github_token_test', sprintf(__('Lyckad anslutning! Inloggad som %s', 'kmg-transport-plugin'), esc_html($body['login'] ?? 'okänd')), 'updated');
    } else {
      add_settings_error('rgplugins_github_token', 'github_token_test', sprintf(__('Misslyckad anslutning. HTTP-status: %s', 'kmg-transport-plugin'), $code), 'error');
    }
  }

  // Skicka tillbaka användaren till inställningssidan
  wp_redirect(add_query_arg('settings-updated', 'true', wp_get_referer()));
  exit;
});

// AJAX: testa GitHub-token utan omladdning
add_action('wp_ajax_rgplugins_test_github_token', function(){
  if (!current_user_can('manage_options')) {
    wp_send_json_error(['message' => __('Du saknar behörighet.', 'kmg-transport-plugin')], 403);
  }
  check_ajax_referer('rgplugins_test_github_token');
  $token = get_option('rgplugins_github_token', '');
  if (empty($token)) {
    wp_send_json_error(['message' => __('Ingen token sparad.', 'kmg-transport-plugin')], 400);
  }
  $args = [
    'headers' => [
      'User-Agent' => 'WordPress Plugin',
      'Authorization' => 'Bearer ' . $token,
    ]
  ];
  $response = wp_remote_get('https://api.github.com/user', $args);
  if (is_wp_error($response)) {
    wp_send_json_error(['message' => __('Fel vid anslutning: ', 'kmg-transport-plugin') . $response->get_error_message()], 500);
  }
  $code = wp_remote_retrieve_response_code($response);
  if ($code === 200) {
    $body = json_decode(wp_remote_retrieve_body($response), true);
    $login = isset($body['login']) ? $body['login'] : 'okänd';
    wp_send_json_success(['message' => sprintf(__('Lyckad anslutning! Inloggad som %s', 'kmg-transport-plugin'), esc_html($login))]);
  }
  wp_send_json_error(['message' => sprintf(__('Misslyckad anslutning. HTTP-status: %s', 'kmg-transport-plugin'), $code)], $code);
});

// Rensa cache för GitHub-releaser när token uppdateras
add_action('update_option_rgplugins_github_token', function ($old, $new) {
  if ($old === $new) {
    return;
  }
  global $wpdb;
  // Ta bort transients som används för release-cache
  $wpdb->query(
    $wpdb->prepare(
      "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
      '_transient_github_release_%',
      '_transient_timeout_github_release_%'
    )
  );
}, 10, 2);
