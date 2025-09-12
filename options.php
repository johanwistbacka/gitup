<?php
/**
 * RG Git Updater – Admin/Options UI
 *
 * Visar installerade plugins/teman som pekar på GitHub (via UpdateURI),
 * listar senaste releaser, och låter admin installera vald tag direkt.
 *
 * Prestanda
 * ---------
 *  - Denna sida kan göra nätverksanrop (GitHub API). Det sker bara här.
 *  - `get_latest_github_release()` cache:ar taggar (1h) och fel/N/A (5min).
 *  - "Uppdatera lista" sätter `rgplugins_refresh=1` och bypassar cache.
 */

/**
 * Hämta en lista med releaser från GitHub för ett repo.
 * Filtrerar bort draft och – om ej valt – prereleases.
 *
 * @param string  $repo_url            Full GitHub-URL (owner/repo)
 * @param bool    $include_prereleases Om true inkluderas prereleases
 * @param int     $limit               Max antal releaser att hämta
 * @return array[] { tag, name, prerelease }
 */

// --- GitHub release-hämtare (UI-hjälpare, ej samma som klassens motor) ---
if (!function_exists('rgplugins_fetch_releases')) {
  function rgplugins_fetch_releases($repo_url, $include_prereleases = false, $limit = 20) {
    // Extrahera /owner/repo ur en full GitHub-URL (t.ex. https://github.com/owner/repo)
    $repo_path = parse_url($repo_url, PHP_URL_PATH);
    if (!$repo_path) return [];
    $api_url = 'https://api.github.com/repos' . $repo_path . '/releases?per_page=' . intval($limit);
    // GitHub kräver User-Agent; Accept för nyare vnd.github+json
    $headers = [
      'User-Agent' => 'WordPress Plugin',
      'Accept' => 'application/vnd.github+json',
    ];
    $token = get_option('rgplugins_github_token', '');
    if (!empty($token)) {
      $headers['Authorization'] = 'Bearer ' . $token;
    }

    // Skyddade timeouts/redirection för att undvika hängningar i admin
    $response = wp_remote_get($api_url, [
      'headers' => $headers,
      'timeout' => 20,
      'redirection' => 3,
    ]);
    if (is_wp_error($response)) return [];
    if ((int) wp_remote_retrieve_response_code($response) !== 200) return [];

    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (!is_array($data)) return [];

    // Normalisera svaret till en kompakt lista vi kan rendera i dropdownen
    $releases = [];
    foreach ($data as $rel) {
      if (!empty($rel['draft'])) continue; // hoppa över draft
      if (!$include_prereleases && !empty($rel['prerelease'])) continue; // hoppa över prerelease om ej valt
      if (empty($rel['tag_name'])) continue;
      $releases[] = [
        'tag' => $rel['tag_name'],
        'name' => !empty($rel['name']) ? $rel['name'] : $rel['tag_name'],
        'prerelease' => !empty($rel['prerelease']),
      ];
    }
    return $releases;
  }
}
// Skapa en meny för plugininställningar
// OBS: sidans slug används i redirects/POST-handlers (page=rgplugins-settings)
add_action("admin_menu", function () {
  add_options_page(
    "RG Plugins Info & Beta",
    "RG Plugins",
    "manage_options",
    "rgplugins-settings",
    "rgplugins_settings_page"
  );
});

/**
 * Lista alla installerade plugins som har en GitHub-UpdateURI.
 * Returnerar metadata + senaste release (via cachead helper).
 *
 * @param bool $force_refresh  Om true, bypassa cache i release-hämtningen.
 * @return array<int,array<string,string>>
 */

if (!function_exists("get_github_plugins")) {
  function get_github_plugins($force_refresh = false)
  {
    // Säkerställ att WordPress plugin-API är laddat (på vissa requests är det inte det ännu)
    if (!function_exists("get_plugins")) {
      require_once ABSPATH . "wp-admin/includes/plugin.php";
    }

    $all_plugins = get_plugins();
    $github_plugins = [];

    foreach ($all_plugins as $plugin_path => $plugin_info) {
      // Hoppa över plugins som inte uttryckligen anger UpdateURI
      if (!isset($plugin_info["UpdateURI"])) {
        continue;
      }

      $update_uri = $plugin_info["UpdateURI"];

      if (strpos($update_uri, "github.com") !== false) {
        $github_plugins[] = [
          'name' => $plugin_info['Name'],
          'version' => $plugin_info['Version'],
          'author' => $plugin_info['Author'],
          'github' => $update_uri,
          'latest_release' => get_latest_github_release($update_uri, false, $force_refresh),
          'file' => $plugin_path,
        ];
      }
    }

    return $github_plugins;
  }
}
/**
 * Lista alla installerade teman som har en GitHub-UpdateURI.
 * Returnerar metadata + senaste release (via cachead helper).
 *
 * @param bool $force_refresh
 * @return array<int,array<string,string>>
 */
// Funktion för att hämta alla installerade teman med en GitHub Update URI
if (!function_exists('get_github_themes')) {
  function get_github_themes($force_refresh = false)
  {
    // Hämta alla installerade teman (inkl. inaktiva)
    $themes = wp_get_themes();
    $github_themes = [];
    foreach ($themes as $stylesheet => $theme) {
      // Endast teman som har UpdateURI mot GitHub behandlas här
      $update_uri = $theme->get('UpdateURI');
      if (!$update_uri || strpos($update_uri, 'github.com') === false) {
        continue;
      }
      $github_themes[] = [
        'name' => $theme->get('Name'),
        'version' => $theme->get('Version'),
        'author' => $theme->get('Author'),
        'github' => $update_uri,
        'latest_release' => get_latest_github_release($update_uri, false, $force_refresh),
        'stylesheet' => $stylesheet, // temats mappnamn
      ];
    }
    return $github_themes;
  }
}
/**
 * Renderar adminsidan: tabeller för plugins och teman.
 * Innehåller en responsiv tabell med select över releasetaggar per rad.
 */
// Funktion för att visa plugin-information i adminpanelen
if (!function_exists("rgplugins_settings_page")) {
  function rgplugins_settings_page()
  {
    // När denna query-param finns bypassar vi release-cachen temporärt
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
            <!-- UI/CSS: responsiv tabell, bryter långa länkar och radbryter formulär på små skärmar -->
            <style>
              .rgplugins-table { table-layout: auto; }
              .rgplugins-table .repo a { display:inline-block; max-width: 360px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
              .rgplugins-table td, .rgplugins-table th { vertical-align: middle; padding: 10px 12px; }
              .rgplugins-table td.actions form { display:flex; gap:10px; align-items:center; flex-wrap: wrap; }
              .rgplugins-table td.actions select { min-width: 160px; flex: 1 1 auto; }
              .rgplugins-table td.actions .button { white-space: nowrap; }
              .rgplugins-table td.actions .button { padding: 2px 8px; font-size: 13px; line-height: 1.6; }

              /* Medium screens: tighten up */
              @media (max-width: 1020px) {
                .rgplugins-table .repo a { max-width: 220px; }
              }

              /* Small screens: card layout */
              @media (max-width: 782px) {
                .rgplugins-table thead { display:none; }
                .rgplugins-table tr { display:block; margin: 0 0 14px; border:1px solid #ccd0d4; border-radius:4px; background:#fff; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
                .rgplugins-table td { display:flex; gap:10px; justify-content:space-between; border-top:1px solid #f0f0f1; padding:8px 10px; }
                .rgplugins-table td:first-child { border-top:0; }
                .rgplugins-table td::before { content: attr(data-label); font-weight:600; color:#1d2327; }
                .rgplugins-table td.actions { display:block; }
                .rgplugins-table td.actions::before { content: attr(data-label); display:block; margin-bottom:6px; font-weight:600; }
                .rgplugins-table td.actions form { display:grid; grid-template-columns: 100%; gap:8px; }
                .rgplugins-table td.actions select { width:100%; }
                .rgplugins-table td.actions .button { width: 100%; text-align: center; }
                .rgplugins-table .repo a { white-space: normal; word-break: break-word; }
              }
            </style>
            <table class="widefat fixed striped rgplugins-table">
                <thead>
                    <tr>
                        <th>Plugin</th>
                        <th>Version</th>
                        <th>Författare</th>
                        <th>GitHub Repository</th>
                        <th>Senaste Release</th>
                        <th>Välj release</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($github_plugins as $plugin): ?>
                        <tr>
                            <?php
                              $repo_label = $plugin["github"];
                              $repo_path  = parse_url($plugin["github"], PHP_URL_PATH);
                              if ($repo_path) { $repo_label = ltrim($repo_path, '/'); }
                            ?>
                            <td class="plugin" data-label="Plugin"><?php echo esc_html($plugin["name"]); ?></td>
                            <td class="version" data-label="Version"><?php echo esc_html($plugin["version"]); ?></td>
                            <td class="author" data-label="Författare"><?php echo esc_html($plugin["author"]); ?></td>
                            <td class="repo" data-label="GitHub Repository"><a href="<?php echo esc_url($plugin["github"]); ?>" target="_blank" title="<?php echo esc_attr($plugin["github"]); ?>"><?php echo esc_html($repo_label); ?></a></td>
                            <td class="latest" data-label="Senaste Release"><?php echo esc_html($plugin["latest_release"]); ?></td>
                            <?php // Kompakt visning: visa "owner/repo" i cellen, full URL i title ?>
                            <td class="actions" data-label="Välj release">
                              <?php
                                // Respektera inställningen "Tillåt förhandsreleaser" även i UI-listan
                                $include_pre = get_option('rgplugins_include_prereleases', '0') === '1';
                                $releases = rgplugins_fetch_releases($plugin['github'], $include_pre, 20);
                                if (empty($releases)) {
                                  echo '<span style="opacity:.7">' . esc_html__('Inga releaser hittades', 'kmg-transport-plugin') . '</span>';
                                } else {
                                  $action = admin_url('admin-post.php');
                                  $plugin_file = $plugin['file'];
                                  // CSRF-skydd: unik nonce per pluginrad
                                  $nonce = wp_create_nonce('rgplugins_install_release_' . $plugin_file);
                                  echo '<form method="post" action="' . esc_url($action) . '">';
                                  echo '<input type="hidden" name="action" value="rgplugins_install_release">';
                                  echo '<input type="hidden" name="plugin" value="' . esc_attr($plugin_file) . '">';
                                  echo '<input type="hidden" name="repo" value="' . esc_attr($plugin['github']) . '">';
                                  echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($nonce) . '">';
                                  echo '<select name="tag">';
                                  foreach ($releases as $rel) {
                                    $label = $rel['tag'] . ($rel['prerelease'] ? ' (pre)' : '');
                                    echo '<option value="' . esc_attr($rel['tag']) . '">' . esc_html($label) . '</option>';
                                  }
                                  echo '</select>';
                                  // POST: går till admin-post handler som kör WP Upgrader
                                  submit_button(__('Installera', 'kmg-transport-plugin'), 'secondary', 'submit', false);
                                  echo '</form>';
                                }
                              ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
        </table>

            <h2 style="margin-top:28px;">Teman</h2>
            <?php $github_themes = get_github_themes($force_refresh); ?>
            <table class="widefat fixed striped rgplugins-table">
                <thead>
                    <tr>
                        <th>Tema</th>
                        <th>Version</th>
                        <th>Författare</th>
                        <th>GitHub Repository</th>
                        <th>Senaste Release</th>
                        <th>Välj release</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($github_themes)): ?>
                        <tr><td colspan="6" style="text-align:center; opacity:.7; padding:16px;"><?php echo esc_html__('Inga teman med GitHub Update URI hittades.', 'kmg-transport-plugin'); ?></td></tr>
                    <?php else: ?>
                        <?php foreach ($github_themes as $theme): ?>
                            <tr>
                                <?php
                                  $repo_label = $theme["github"];
                                  $repo_path  = parse_url($theme["github"], PHP_URL_PATH);
                                  if ($repo_path) { $repo_label = ltrim($repo_path, '/'); }
                                ?>
                                <?php // Samma kompakta URL-visning för teman ?>
                                <td class="plugin" data-label="Tema"><?php echo esc_html($theme["name"]); ?></td>
                                <td class="version" data-label="Version"><?php echo esc_html($theme["version"]); ?></td>
                                <td class="author" data-label="Författare"><?php echo esc_html($theme["author"]); ?></td>
                                <td class="repo" data-label="GitHub Repository"><a href="<?php echo esc_url($theme["github"]); ?>" target="_blank" title="<?php echo esc_attr($theme["github"]); ?>"><?php echo esc_html($repo_label); ?></a></td>
                                <td class="latest" data-label="Senaste Release"><?php echo esc_html($theme["latest_release"]); ?></td>
                                <td class="actions" data-label="Välj release">
                                  <?php
                                    // Respektera global prerelease-inställning
                                    $include_pre = get_option('rgplugins_include_prereleases', '0') === '1';
                                    $releases = rgplugins_fetch_releases($theme['github'], $include_pre, 20);
                                    if (empty($releases)) {
                                      echo '<span style="opacity:.7">' . esc_html__('Inga releaser hittades', 'kmg-transport-plugin') . '</span>';
                                    } else {
                                      $action = admin_url('admin-post.php');
                                      $theme_stylesheet = $theme['stylesheet'];
                                      // CSRF-skydd: unik nonce per tema
                                      $nonce = wp_create_nonce('rgthemes_install_release_' . $theme_stylesheet);
                                      echo '<form method="post" action="' . esc_url($action) . '">';
                                      echo '<input type="hidden" name="action" value="rgthemes_install_release">';
                                      echo '<input type="hidden" name="theme" value="' . esc_attr($theme_stylesheet) . '">';
                                      echo '<input type="hidden" name="repo" value="' . esc_attr($theme['github']) . '">';
                                      echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($nonce) . '">';
                                      echo '<select name="tag">';
                                      foreach ($releases as $rel) {
                                        $label = $rel['tag'] . ($rel['prerelease'] ? ' (pre)' : '');
                                        echo '<option value="' . esc_attr($rel['tag']) . '">' . esc_html($label) . '</option>';
                                      }
                                      echo '</select>';
                                      // POST: admin-post handler för teman (Theme_Upgrader)
                                      submit_button(__('Installera', 'kmg-transport-plugin'), 'secondary', 'submit', false);
                                      echo '</form>';
                                    }
                                  ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

        <?php // Admin-notice: visar resultat av manuella installationer ?>
        <?php
        add_action('admin_notices', function () {
          if (!isset($_GET['rgplugins_msg'])) return;
          $msg = sanitize_text_field(wp_unslash($_GET['rgplugins_msg']));
          $class = (isset($_GET['ok']) && $_GET['ok'] === '1') ? 'updated' : 'error';
          echo '<div class="' . esc_attr($class) . ' notice"><p>' . esc_html($msg) . '</p></div>';
        });
        ?>

        <?php $ajax_nonce = wp_create_nonce('rgplugins_test_github_token'); $ajax_url = admin_url('admin-ajax.php'); ?>
        <?php // Visa validerings-/sparmeddelanden från Settings API (t.ex. token-test)
        settings_errors(); ?>

        <form method="post" action="options.php">
            <?php
            // Nonces + option group för denna sida
            // Lägg till nödvändiga fält för options-API (nonce, option group etc.)
            settings_fields('rgplugins_settings_group');

            // Rendera sektioner och fält som registrerats för denna sida
            do_settings_sections('rgplugins-settings');

            // Spara-knapp
            submit_button(__('Spara inställningar', 'kmg-transport-plugin'));

            // Testa GitHub-token
            // submit_button(__('Testa GitHub-anslutning', 'kmg-transport-plugin'), 'secondary', 'test_github_token', false);

            // Testa via AJAX (ingen omladdning)
            echo '<button type="button" id="rgplugins-test-ajax" class="button">' . esc_html__('Testa GitHub-anslutning', 'kmg-transport-plugin') . '</button>';
            echo '<span id="rgplugins-test-ajax-status" style="margin-left:8px;"></span>';
            ?>
        </form>
        <script>
        (function(){
          // Liten AJAX-testknapp för att verifiera token utan att lämna sidan
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
            // Skicka samma request som sync-testet men hämta svaret som JSON
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

/**
 * Global helper (UI): hämta senaste tag från GitHub med cache + prerelease-stöd.
 * Denna variant används av options-sidan (uppdateringsmotorn har sin egen i klassen).
 *
 * Cache:
 *  - Nyckel skiljer på stable vs pre (så de inte krockar)
 *  - Lyckad tag: 1h. N/A/fel: 5min.
 *
 * @param string $repo_url
 * @param bool   $is_private   Visa feltext om privat repo saknar token
 * @param bool   $force_refresh Bypassa cache (används av "Uppdatera lista")
 * @return string 'N/A' eller taggnamn
 */

function get_latest_github_release($repo_url, $is_private = false, $force_refresh = false)
{
  $repo_path = parse_url($repo_url, PHP_URL_PATH);
  $include_prereleases = get_option('rgplugins_include_prereleases', '0') === '1';

  // Välj API-endpoint beroende på om beta tillåts
  $api_url = $include_prereleases
    ? "https://api.github.com/repos$repo_path/releases?per_page=10"
    : "https://api.github.com/repos$repo_path/releases/latest";

  // Cache-nyckel särskiljer på stable vs pre så att visningen alltid är konsekvent
  $cache_key = 'github_release_' . md5($repo_url . '|' . ($include_prereleases ? 'pre' : 'stable'));
  if ($force_refresh) {
    delete_transient($cache_key);
  }
  $cached_release = get_transient($cache_key);
  if (!$force_refresh && $cached_release && $cached_release !== 'N/A') {
    return $cached_release;
  }

  $github_token = get_option('rgplugins_github_token', '');
  $headers = [
    'User-Agent' => 'WordPress Plugin',
    'Accept' => 'application/vnd.github+json',
  ];
  // Privat repo kräver token; om flaggad som privat men token saknas → tydlig text i UI
  if (!empty($github_token) || $is_private) {
    if (empty($github_token)) {
      return 'Privat repo kräver en GitHub-token';
    }
    $headers['Authorization'] = 'Bearer ' . $github_token;
  }

  $args = [
    'headers' => $headers,
    'timeout' => 20,
    'redirection' => 3,
  ];

  $response = wp_remote_get($api_url, $args);
  if (is_wp_error($response)) {
    set_transient($cache_key, 'N/A', 5 * MINUTE_IN_SECONDS);
    return 'N/A';
  }
  $code = (int) wp_remote_retrieve_response_code($response);
  if ($code !== 200) {
    set_transient($cache_key, 'N/A', 5 * MINUTE_IN_SECONDS);
    return 'N/A';
  }

  $body = wp_remote_retrieve_body($response);
  $data = json_decode($body, true);

  if ($include_prereleases) {
    // Hitta första icke-draft release (kan vara prerelease)
    $latest = 'N/A';
    if (is_array($data)) {
      foreach ($data as $rel) {
        if (!empty($rel['draft'])) { continue; }
        if (!empty($rel['tag_name'])) { $latest = $rel['tag_name']; break; }
      }
    }
  } else {
    $latest = is_array($data) ? ($data['tag_name'] ?? 'N/A') : 'N/A';
  }

  if ($latest !== 'N/A') {
    set_transient($cache_key, $latest, HOUR_IN_SECONDS);
  } else {
    set_transient($cache_key, 'N/A', 5 * MINUTE_IN_SECONDS);
  }
  return $latest;
}

/**
 * Registrera inställningar/fields för denna sida.
 * - Token (password)
 * - Checkbox: tillåt förhandsreleaser (beta/rc)
 * (Beta-branch-fältet är kvarkommenterat men lämnat för framtida bruk.)
 */

add_action("admin_init", function () {
  register_setting("rgplugins_settings_group", "rgplugins_github_token");
  add_settings_section(
    "rgplugins_settings_section",
    "GitHub API-inställningar",
    function () {
      // Kort instruktion: länka till GitHub-sidan för tokens och ange scope (repo vid privata repos)
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

      // Statusrad: senast verifierad (via http_response 200) och senast uppdaterad (när option ändrades)
      $last_verified_ts = (int) get_option('rgplugins_token_last_verified');
      $last_updated_ts  = (int) get_option('rgplugins_token_last_updated');
      $now              = current_time('timestamp');

      if ($last_verified_ts) {
        $verified_when = date_i18n( get_option('date_format') . ' ' . get_option('time_format'), $last_verified_ts );
        echo '<p style="margin-top:6px;"><em>' . esc_html__('Senast verifierad:', 'kmg-transport-plugin') . ' ' . esc_html($verified_when) . '</em></p>';
      }

      if ($last_updated_ts) {
        $updated_when = date_i18n( get_option('date_format') . ' ' . get_option('time_format'), $last_updated_ts );
        $days_ago     = max(0, floor( ($now - $last_updated_ts) / DAY_IN_SECONDS ));
        $human        = human_time_diff($last_updated_ts, $now);
        echo '<p style="margin-top:2px;"><em>'
          . esc_html__('Token uppdaterades:', 'kmg-transport-plugin') . ' '
          . esc_html( sprintf( _n('%s dag sedan', '%s dagar sedan', $days_ago, 'kmg-transport-plugin'), number_format_i18n($days_ago) ) )
          . ' (' . esc_html($human) . ') — ' . esc_html($updated_when)
          . '</em></p>';
      }
    },
    "rgplugins-settings",
    "rgplugins_settings_section"
  );

  // Tillåt förhandsreleaser (beta/rc)
  register_setting('rgplugins_settings_group', 'rgplugins_include_prereleases');
  add_settings_field(
    'rgplugins_include_prereleases',
    __('Tillåt förhandsreleaser', 'kmg-transport-plugin'),
    function () {
      $val = get_option('rgplugins_include_prereleases', '0');
      echo '<label><input type="checkbox" name="rgplugins_include_prereleases" value="1" ' . checked('1', $val, false) . '> ' . esc_html__('Visa och uppdatera till beta/rc-releaser', 'kmg-transport-plugin') . '</label>';
    },
    'rgplugins-settings',
    'rgplugins_settings_section'
  );


});

// Hantera test av GitHub-token när knappen trycks
add_action('admin_post_test_github_token', function () {
  if (!current_user_can('manage_options')) {
    wp_die(__('Du saknar behörighet.', 'kmg-transport-plugin'));
  }
  // Skydda POST: kräver korrekt options-API nonce
  check_admin_referer('rgplugins_settings_group-options');
  $token = get_option('rgplugins_github_token', '');
  // Minsta möjliga anrop mot /user för att verifiera token
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

  // Återvänd till sidan och visa resultat via settings_errors
  wp_redirect(add_query_arg('settings-updated', 'true', wp_get_referer()));
  exit;
});

// AJAX: testa GitHub-token utan omladdning
add_action('wp_ajax_rgplugins_test_github_token', function(){
  if (!current_user_can('manage_options')) {
    wp_send_json_error(['message' => __('Du saknar behörighet.', 'kmg-transport-plugin')], 403);
  }
  // Skydda AJAX: kräver giltig nonce
  check_ajax_referer('rgplugins_test_github_token');
  $token = get_option('rgplugins_github_token', '');
  if (empty($token)) {
    wp_send_json_error(['message' => __('Ingen token sparad.', 'kmg-transport-plugin')], 400);
  }
  // Samma /user-anrop som i sync-testet
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
    update_option('rgplugins_token_last_updated', time());
  global $wpdb;
  // Ta bort transients för att UI-listan ska uppdateras direkt vid ny token
  $wpdb->query(
    $wpdb->prepare(
      "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
      '_transient_github_release_%',
      '_transient_timeout_github_release_%'
    )
  );
}, 10, 2);

// Admin-post handler: installera vald release-tag för ett plugin
add_action('admin_post_rgplugins_install_release', function () {
  if (!current_user_can('update_plugins')) {
    wp_die(__('Du har inte behörighet att uppdatera tillägg.', 'kmg-transport-plugin'));
  }
  $plugin = isset($_POST['plugin']) ? sanitize_text_field(wp_unslash($_POST['plugin'])) : '';
  $repo   = isset($_POST['repo']) ? esc_url_raw(wp_unslash($_POST['repo'])) : '';
  $tag    = isset($_POST['tag']) ? sanitize_text_field(wp_unslash($_POST['tag'])) : '';
  $nonce  = isset($_POST['_wpnonce']) ? sanitize_text_field(wp_unslash($_POST['_wpnonce'])) : '';
  if (!$plugin || !$repo || !$tag || !wp_verify_nonce($nonce, 'rgplugins_install_release_' . $plugin)) {
    wp_safe_redirect(add_query_arg(['page' => 'rgplugins-settings', 'rgplugins_msg' => urlencode(__('Ogiltig begäran.', 'kmg-transport-plugin'))], admin_url('options-general.php')));
    exit;
  }
  // Standardiserad codeload-URL för tagg; fungerar även för privata repos med auth via http_request_args
  $repo_path = parse_url($repo, PHP_URL_PATH);
  $package = 'https://codeload.github.com' . $repo_path . '/zip/refs/tags/' . rawurlencode($tag);

  require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
  require_once ABSPATH . 'wp-admin/includes/plugin.php';

  // Använd WordPress inbyggda upgrader-API (visar status i UI)
  $skin = new Automatic_Upgrader_Skin();
  $upgrader = new Plugin_Upgrader($skin);

  // Peka WordPress mot rätt destinationsmapp (utan tag i namnet)
  add_filter('upgrader_package_options', function ($options) use ($plugin) {
      // Skriv över destinationen så namnet blir identiskt med nuvarande mapp
      $options['hook_extra']['plugin'] = $plugin; // används av våra hooks
      if (defined('WP_PLUGIN_DIR')) {
        $expected_dir = dirname($plugin);
        $plugins_dir = trailingslashit(WP_PLUGIN_DIR);
        $options['destination'] = trailingslashit($plugins_dir . $expected_dir);
        $options['destination_name'] = $expected_dir;
        $options['clear_destination'] = true;
        $options['abort_if_destination_exists'] = false;
      }
      return $options;
  });

  $result = $upgrader->install($package);

  // Försök reaktivera om det var aktivt innan
  $was_active = is_plugin_active($plugin);
  if ($result && !is_wp_error($result) && $was_active && !is_plugin_active($plugin)) {
    activate_plugin($plugin, '', false, true);
  }

  if ($result && !is_wp_error($result)) {
    $msg = __('Installationen av vald release lyckades.', 'kmg-transport-plugin');
    $ok  = '1';
  } else {
    $msg = is_wp_error($result) ? $result->get_error_message() : __('Installationen misslyckades.', 'kmg-transport-plugin');
    $ok  = '0';
  }

  wp_safe_redirect(add_query_arg(['page' => 'rgplugins-settings', 'rgplugins_msg' => urlencode($msg), 'ok' => $ok], admin_url('options-general.php')));
  exit;
});

// Admin-post handler: installera vald release-tag för ett tema
add_action('admin_post_rgthemes_install_release', function () {
  if (!current_user_can('update_themes')) {
    wp_die(__('Du har inte behörighet att uppdatera teman.', 'kmg-transport-plugin'));
  }
  $theme = isset($_POST['theme']) ? sanitize_text_field(wp_unslash($_POST['theme'])) : '';
  $repo  = isset($_POST['repo']) ? esc_url_raw(wp_unslash($_POST['repo'])) : '';
  $tag   = isset($_POST['tag']) ? sanitize_text_field(wp_unslash($_POST['tag'])) : '';
  $nonce = isset($_POST['_wpnonce']) ? sanitize_text_field(wp_unslash($_POST['_wpnonce'])) : '';
  if (!$theme || !$repo || !$tag || !wp_verify_nonce($nonce, 'rgthemes_install_release_' . $theme)) {
    wp_safe_redirect(add_query_arg(['page' => 'rgplugins-settings', 'rgplugins_msg' => urlencode(__('Ogiltig begäran (tema).', 'kmg-transport-plugin'))], admin_url('options-general.php')));
    exit;
  }
  // Codeload-URL för tema-tag (samma mönster som för plugin)
  $repo_path = parse_url($repo, PHP_URL_PATH);
  $package = 'https://codeload.github.com' . $repo_path . '/zip/refs/tags/' . rawurlencode($tag);

  require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
  require_once ABSPATH . 'wp-admin/includes/theme.php';

  // WordPress Theme_Upgrader – hanterar unzip och filkopiering
  $skin = new Automatic_Upgrader_Skin();
  $upgrader = new Theme_Upgrader($skin);

  // Sätt destination till temats mapp (utan tag i katalognamnet)
  add_filter('upgrader_package_options', function ($options) use ($theme) {
    // Tvinga installation till temats stylesheet-katalog (utan tag i katalognamnet)
    $options['hook_extra']['theme'] = $theme; // informativt
    $themes_root = trailingslashit(get_theme_root());
    $options['destination'] = trailingslashit($themes_root . $theme);
    $options['destination_name'] = $theme;
    $options['clear_destination'] = true;
    $options['abort_if_destination_exists'] = false;
    return $options;
  });

  $result = $upgrader->install($package);

  // Om aktiva temat uppdaterades krävs ingen reaktivering; WP använder mappen.
  if ($result && !is_wp_error($result)) {
    $msg = __('Temat installerades/uppdaterades.', 'kmg-transport-plugin');
    $ok  = '1';
  } else {
    $msg = is_wp_error($result) ? $result->get_error_message() : __('Installationen misslyckades.', 'kmg-transport-plugin');
    $ok  = '0';
  }

  wp_safe_redirect(add_query_arg(['page' => 'rgplugins-settings', 'rgplugins_msg' => urlencode($msg), 'ok' => $ok], admin_url('options-general.php')));
  exit;
});
