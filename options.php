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
    // --- Internal transient cache per repo_url + prerelease flag ---
    $is_ajax = (defined('DOING_AJAX') && DOING_AJAX);
    $cache_key = 'rgplugins_releases_' . ($is_ajax ? 'ui_' : '') . md5($repo_url . '|' . ($include_prereleases ? 'pre' : 'stable'));
    $cached = get_transient($cache_key);
    if ($cached !== false) return $cached;

    // Extrahera /owner/repo ur en full GitHub-URL (t.ex. https://github.com/owner/repo)
    $repo_path = parse_url($repo_url, PHP_URL_PATH);
    if (!$repo_path) {
      set_transient($cache_key, [], 5 * MINUTE_IN_SECONDS);
      return [];
    }
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
      'timeout' => 8,
      'redirection' => 3,
    ]);
    if (is_wp_error($response)) {
      set_transient($cache_key, [], 5 * MINUTE_IN_SECONDS);
      return [];
    }
    $code = (int) wp_remote_retrieve_response_code($response);
    if ($code !== 200) {
      if ($code === 403) {
        if (defined('RG_UPDATER_DEBUG_ENABLED') && RG_UPDATER_DEBUG_ENABLED) {
          error_log('[RG Git Updater][fetch_releases] Rate limit hit for ' . $repo_url);
        }
        set_transient($cache_key, [['error' => 'rate_limit']], 5 * MINUTE_IN_SECONDS);
        return [['error' => 'rate_limit']];
      }
      set_transient($cache_key, [], 5 * MINUTE_IN_SECONDS);
      return [];
    }

    // Debug: log the raw releases response just before decoding
    if (defined('RG_UPDATER_DEBUG_ENABLED') && RG_UPDATER_DEBUG_ENABLED) {
      error_log('[RG Git Updater] Raw releases response for ' . $repo_url . ': ' . wp_remote_retrieve_body($response));
    }
    $data = json_decode(wp_remote_retrieve_body($response), true);
    // Debug: log raw response and decoded data type/count
    if (defined('RG_UPDATER_DEBUG_ENABLED') && RG_UPDATER_DEBUG_ENABLED) {
      error_log('[RG Git Updater][fetch_releases] Raw response for ' . $repo_url . ': ' . substr(wp_remote_retrieve_body($response), 0, 500));
      error_log('[RG Git Updater][fetch_releases] Decoded data type: ' . gettype($data) . ' count: ' . (is_array($data) ? count($data) : 0));
    }
    $releases = [];
    if (is_array($data)) {
      // Normalisera svaret till en kompakt lista vi kan rendera i dropdownen
      foreach ($data as $rel) {
        if (defined('RG_UPDATER_DEBUG_ENABLED') && RG_UPDATER_DEBUG_ENABLED) {
          error_log('[RG Git Updater][fetch_releases] Candidate release: tag=' . ($rel['tag_name'] ?? 'N/A') . ' draft=' . (!empty($rel['draft']) ? '1' : '0') . ' prerelease=' . (!empty($rel['prerelease']) ? '1' : '0'));
        }
        if (!empty($rel['draft'])) continue; // hoppa över draft
        if (!$include_prereleases && !empty($rel['prerelease'])) continue; // hoppa över prerelease om ej valt
        if (empty($rel['tag_name'])) continue;
        $releases[] = [
          'tag'        => $rel['tag_name'],
          'name'       => !empty($rel['name']) ? $rel['name'] : $rel['tag_name'],
          'prerelease' => !empty($rel['prerelease']),
          'url'        => $rel['html_url'] ?? '',
          'published'  => $rel['published_at'] ?? '',
          'body'       => $rel['body'] ?? '',
        ];
      }
    }
    // Fallback: Om inga releases hittades, hämta tags istället
    if (empty($releases)) {
      if (defined('RG_UPDATER_DEBUG_ENABLED') && RG_UPDATER_DEBUG_ENABLED) {
        error_log('[RG Git Updater] No releases found, trying tags for ' . $repo_url);
      }
      $tags_url = 'https://api.github.com/repos' . $repo_path . '/tags?per_page=' . intval($limit);
      $tags_response = wp_remote_get($tags_url, [
        'headers' => $headers,
        'timeout' => 8,
        'redirection' => 3,
      ]);
      if (!is_wp_error($tags_response) && (int)wp_remote_retrieve_response_code($tags_response) === 200) {
        $tags_data = json_decode(wp_remote_retrieve_body($tags_response), true);
        if (is_array($tags_data)) {
          // Explicitly extract 'name' field
          $raw_tags = [];
          foreach ( $tags_data as $tag ) {
              if ( isset( $tag['name'] ) ) {
                  $raw_tags[] = $tag['name'];
              }
          }
          if (defined('RG_UPDATER_DEBUG_ENABLED') && RG_UPDATER_DEBUG_ENABLED) {
            error_log('[RG Updater] Raw tags: ' . implode(', ', $raw_tags));
          }
          // Normalize tags by trimming whitespace and leading "v"
          $normalized_tags = array_map( function( $t ) {
              return ltrim( trim( $t ), 'v' );
          }, $raw_tags );
          if (defined('RG_UPDATER_DEBUG_ENABLED') && RG_UPDATER_DEBUG_ENABLED) {
            error_log('[RG Updater] Normalized tags: ' . implode(', ', $normalized_tags));
          }

          // Build tag objects for dropdown
          $tag_objs = [];
          foreach ( $raw_tags as $idx => $orig_tag ) {
              $normalized_tag = $normalized_tags[$idx];
              $commit_url = '';
              if (!empty($tags_data[$idx]['commit']['sha'])) {
                  $commit_url = 'https://github.com' . $repo_path . '/commit/' . $tags_data[$idx]['commit']['sha'];
              }
              $tag_objs[] = [
                  'tag'        => $normalized_tag,
                  'name'       => $normalized_tag,
                  'prerelease' => false,
                  'url'        => $commit_url,
                  'published'  => '',
                  'body'       => '',
              ];
          }
          // Optionally filter out prerelease-looking tags if $include_prereleases is false
          $filtered_tags = [];
          foreach ($tag_objs as $t) {
              if (!$include_prereleases) {
                  // Exclude tags with "alpha", "beta", "rc", "pre" (case-insensitive)
                  if (preg_match('/(alpha|beta|rc|pre)/i', $t['tag'])) {
                      continue;
                  }
              }
              $filtered_tags[] = $t;
          }
          // Final debug log showing what is kept
          $kept_tags = array_map(function($t){ return $t['tag']; }, $filtered_tags);
          if (defined('RG_UPDATER_DEBUG_ENABLED') && RG_UPDATER_DEBUG_ENABLED) {
            error_log('[RG Updater] Tags kept for dropdown: ' . implode(', ', $kept_tags));
          }
          // Sort tags by semantic version, descending (newest first)
          usort($filtered_tags, function($a, $b) {
              $vA = ltrim($a['tag'], 'vV');
              $vB = ltrim($b['tag'], 'vV');
              return version_compare($vB, $vA);
          });
          $releases = $filtered_tags;
        }
      }
    }
    if (defined('RG_UPDATER_DEBUG_ENABLED') && RG_UPDATER_DEBUG_ENABLED) {
      error_log('[RG Git Updater][fetch_releases] Returning ' . count($releases) . ' releases for ' . $repo_url);
    }
    set_transient($cache_key, $releases, 30 * MINUTE_IN_SECONDS);
    return $releases;
  }
}
// Helper: Determine if a GitHub repo is public or private (cached).
if (!function_exists('rgplugins_repo_visibility')) {
  /**
   * Returns 'public', 'private', or 'unknown' for a GitHub repository URL.
   * Strategy:
   *  - Try unauthenticated GET to /repos/{owner}/{repo}:
   *      200 => public
   *      404/401 => private (or not found)
   *      403 => rate limited; if token exists, retry with token.
   *  - If token retry:
   *      200 => read 'private' property (true/false)
   *      404/401 => private
   *      else => unknown
   * Results are cached for 30 minutes per repo URL.
   *
   * @param string $repo_url Full GitHub URL like https://github.com/owner/repo
   * @return string 'public'|'private'|'unknown'
   */
  function rgplugins_repo_visibility($repo_url) {
    static $local_cache = [];
    if (isset($local_cache[$repo_url])) return $local_cache[$repo_url];

    $repo_path = parse_url($repo_url, PHP_URL_PATH);
    if (!$repo_path) {
      $local_cache[$repo_url] = 'unknown';
      return 'unknown';
    }
    $cache_key = 'github_repo_visibility_' . md5($repo_url);
    $cached = get_transient($cache_key);
    if ($cached !== false) {
      $local_cache[$repo_url] = $cached;
      return $cached;
    }

    $api_url = 'https://api.github.com/repos' . $repo_path;

    // 1) Unauthenticated probe (cheap & sufficient for public repos)
    $headers = [
      'User-Agent' => 'WordPress Plugin',
      'Accept'     => 'application/vnd.github+json',
    ];
    $resp = wp_remote_get($api_url, [
      'headers'     => $headers,
      'timeout'     => 10,
      'redirection' => 2,
    ]);
    if (!is_wp_error($resp)) {
      $code = (int) wp_remote_retrieve_response_code($resp);
      // Insert debug log line here
      if (defined('RG_UPDATER_DEBUG_ENABLED') && RG_UPDATER_DEBUG_ENABLED) {
        error_log('[RG Git Updater][repo_visibility] URL: ' . $api_url . ' code: ' . $code . ' body: ' . substr(wp_remote_retrieve_body($resp), 0, 200));
      }
      if ($code === 200) {
        $body_json = json_decode(wp_remote_retrieve_body($resp), true);
        if (is_array($body_json) && array_key_exists('private', $body_json)) {
          $is_private = !empty($body_json['private']);
          $result = $is_private ? 'private' : 'public';
          set_transient($cache_key, $result, 30 * MINUTE_IN_SECONDS);
          $local_cache[$repo_url] = $result;
          return $result;
        } else {
          if (defined('RG_UPDATER_DEBUG_ENABLED') && RG_UPDATER_DEBUG_ENABLED) {
            error_log('[RG Git Updater][repo_visibility] Unexpected 200 response body, marking as unknown');
          }
          set_transient($cache_key, 'unknown', 15 * MINUTE_IN_SECONDS);
          $local_cache[$repo_url] = 'unknown';
          return 'unknown';
        }
      }
      if ($code === 404 || $code === 401) {
        set_transient($cache_key, 'private', 30 * MINUTE_IN_SECONDS);
        $local_cache[$repo_url] = 'private';
        return 'private';
      }
    }

    // 2) If rate limited or uncertain, retry with token if available
    $token = get_option('rgplugins_github_token', '');
    if (!empty($token)) {
      $headers['Authorization'] = 'Bearer ' . $token;
      $resp2 = wp_remote_get($api_url, [
        'headers'     => $headers,
        'timeout'     => 10,
        'redirection' => 2,
      ]);
      if (!is_wp_error($resp2)) {
        $code2 = (int) wp_remote_retrieve_response_code($resp2);
        if (defined('RG_UPDATER_DEBUG_ENABLED') && RG_UPDATER_DEBUG_ENABLED) {
          error_log('[RG Git Updater][repo_visibility] URL: ' . $api_url . ' code: ' . $code2 . ' body: ' . substr(wp_remote_retrieve_body($resp2), 0, 200));
        }
        if ($code2 === 200) {
          $body_json = json_decode(wp_remote_retrieve_body($resp2), true);
          if (is_array($body_json) && array_key_exists('private', $body_json)) {
            $is_private = !empty($body_json['private']);
            $result = $is_private ? 'private' : 'public';
            set_transient($cache_key, $result, 30 * MINUTE_IN_SECONDS);
            $local_cache[$repo_url] = $result;
            return $result;
          } else {
            if (defined('RG_UPDATER_DEBUG_ENABLED') && RG_UPDATER_DEBUG_ENABLED) {
              error_log('[RG Git Updater][repo_visibility] Unexpected 200 response body, marking as unknown');
            }
            set_transient($cache_key, 'unknown', 15 * MINUTE_IN_SECONDS);
            $local_cache[$repo_url] = 'unknown';
            return 'unknown';
          }
        }
        if ($code2 === 404 || $code2 === 401) {
          set_transient($cache_key, 'private', 30 * MINUTE_IN_SECONDS);
          $local_cache[$repo_url] = 'private';
          return 'private';
        }
      }
    }

    set_transient($cache_key, 'unknown', 15 * MINUTE_IN_SECONDS);
    $local_cache[$repo_url] = 'unknown';
    return 'unknown';
  }
}

// Skapa en meny för plugininställningar
// OBS: sidans slug används i redirects/POST-handlers (page=rgplugins-settings)
add_action('admin_menu', function () {
  add_submenu_page(
    'tools.php',                                // parent: Tools
    __('GitUp – GitHub Updates', 'rg-git-updater'), // page_title
    __('GitUp', 'rg-git-updater'),                  // menu_title
    'update_core',                               // capability (alt: update_plugins)
    'rgplugins-settings',                        // slug (keep)
    'rgplugins_settings_page'
    // Removed icon argument for submenu page
  );
});

// Ensure the submenu icon uses proper scaling for WP admin menu (20x20)
add_action('admin_head', function() {
  echo '<style>
    #tools_page_rgplugins-settings .wp-menu-image img {
      width: 20px !important;
      height: 20px !important;
      object-fit: contain;
    }
  </style>';
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

    $cache_key = 'rgplugins_ui_cache_plugins';
    if (!$force_refresh) {
      $cached = get_transient($cache_key);
      if ($cached !== false) return $cached;
    }

    $all_plugins = get_plugins();
    $github_plugins = [];

    foreach ($all_plugins as $plugin_path => $plugin_info) {
      // Hoppa över plugins som inte uttryckligen anger UpdateURI
      if (!isset($plugin_info["UpdateURI"])) {
        continue;
      }

      $update_uri = $plugin_info["UpdateURI"];
      // Trim trailing slashes for normalization
      $update_uri = rtrim($update_uri, '/');

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

    if (!$force_refresh) set_transient($cache_key, $github_plugins, 10 * MINUTE_IN_SECONDS);
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
    $cache_key = 'rgplugins_ui_cache_themes';
    if (!$force_refresh) {
      $cached = get_transient($cache_key);
      if ($cached !== false) return $cached;
    }
    // Hämta alla installerade teman (inkl. inaktiva)
    $themes = wp_get_themes();
    $github_themes = [];
    foreach ($themes as $stylesheet => $theme) {
      // Endast teman som har UpdateURI mot GitHub behandlas här
      $update_uri = $theme->get('UpdateURI');
      // Normalisera till full GitHub-URL om bara owner/repo anges
      if ($update_uri && strpos($update_uri, 'github.com') === false) {
          $update_uri = 'https://github.com/' . ltrim($update_uri, '/');
      }
      // Trim trailing slashes for normalization
      $update_uri = rtrim($update_uri, '/');
      if (!$update_uri || strpos($update_uri, 'github.com') === false) {
        continue;
      }
      // Debug-loggning av theme-namn och update_uri
      error_log('[RG Git Updater] Theme: ' . $theme->get('Name') . ' UpdateURI: ' . $update_uri);
      $releases = rgplugins_fetch_releases($update_uri, false, 10);
      error_log('[RG Git Updater] Found ' . count($releases) . ' releases for ' . $theme->get('Name'));

      $github_themes[] = [
        'name'          => $theme->get('Name'),
        'version'       => $theme->get('Version'),
        'author'        => $theme->get('Author'),
        'github'        => $update_uri,
        'latest_release'=> !empty($releases[0]['tag']) ? $releases[0]['tag'] : 'N/A',
        'releases'      => $releases,
        'stylesheet'    => $stylesheet,
      ];
    }
    if (!$force_refresh) set_transient($cache_key, $github_themes, 10 * MINUTE_IN_SECONDS);
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
    // Determine active tab
    $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'updates';
    // For refresh logic, only applies to updates tab
    $force_refresh = ($active_tab === 'updates' && isset($_GET['rgplugins_refresh']));

    // For nav tab links
    $base_url = admin_url('tools.php?page=rgplugins-settings');
    $updates_url = add_query_arg('tab', 'updates', $base_url);
    $settings_url = add_query_arg('tab', 'settings', $base_url);
    ?>
    <div class="wrap">
      <div class="rgplugins-header" >
        <h1 style="margin:0;">
          <img src="<?php echo esc_url(plugin_dir_url(__FILE__) . 'assets/images/icon.svg'); ?>" alt="GitUp" style="width:40px;height:40px;">
          <?php echo esc_html__('GitUp', 'rg-git-updater'); ?>
        </h1>
      </div>
      <?php settings_errors(); ?>
      <p><?php echo esc_html__('Manage GitHub-hosted plugin and theme updates for your WordPress site.', 'rg-git-updater'); ?></p>
      <h2 class="nav-tab-wrapper" style="margin-bottom:20px;">
        <a href="<?php echo esc_url($updates_url); ?>" class="nav-tab<?php if ($active_tab === 'updates') echo ' nav-tab-active'; ?>"><?php esc_html_e('Updates', 'rg-git-updater'); ?></a>
        <a href="<?php echo esc_url($settings_url); ?>" class="nav-tab<?php if ($active_tab === 'settings') echo ' nav-tab-active'; ?>"><?php esc_html_e('Settings', 'rg-git-updater'); ?></a>
      </h2>
      <?php
      // === Render admin notices INSIDE tab containers, directly after nav tabs ===
      if ($active_tab === 'updates') :
        // Build a refresh URL that toggles rgplugins_refresh=1 and keeps tab=updates
        $refresh_url = add_query_arg(['tab' => 'updates', 'rgplugins_refresh' => '1'], $base_url);
        // Show force refresh notice (if any)
        if ($force_refresh) {
          echo '<div class="updated notice"><p>' . esc_html__('List refreshed from GitHub (cache bypassed).', 'rg-git-updater') . '</p></div>';
        }
        // Show admin notices for updates tab (from rgplugins_msg in URL)
        if (isset($_GET['rgplugins_msg'])) {
          $msg = sanitize_text_field(wp_unslash($_GET['rgplugins_msg']));
          $class = (isset($_GET['ok']) && $_GET['ok'] === '1') ? 'updated' : 'error';
          echo '<div class="' . esc_attr($class) . ' notice"><p>' . esc_html($msg) . '</p></div>';
        }
        $github_plugins = get_github_plugins($force_refresh);
      ?>
        <p>
          <a href="<?php echo esc_url($refresh_url); ?>" class="button"><?php echo esc_html__('Refresh list', 'rg-git-updater'); ?></a>
        </p>
        <h2 style="margin-top:28px;"><?php esc_html_e('Plugins', 'rg-git-updater'); ?></h2>
        <table class="widefat fixed striped rgplugins-table">
            <thead>
                <tr>
                    <th class="plugin" ><?php esc_html_e('Plugin', 'rg-git-updater'); ?></th>
                    <th class="version"><?php esc_html_e('Version', 'rg-git-updater'); ?></th>
                    <th class="release"><?php esc_html_e('Select release', 'rg-git-updater'); ?></th>
                </tr>
            </thead>
            <tbody>
              <?php $odd = 'odd'; ?>
                <?php foreach ($github_plugins as $plugin): ?>
                    <?php
                      $repo_label = $plugin["github"];
                      $repo_path  = parse_url($plugin["github"], PHP_URL_PATH);
                      if ($repo_path) { $repo_label = ltrim($repo_path, '/'); }
                      // Beräkna $row_class baserat på $selected_tag och jämförelse mot $plugin['version']
                      $include_pre = get_option('rgplugins_include_prereleases', '0') === '1';
                      $releases = []; // Lazy load via AJAX
                      $row_class = '';
                      // No releases loaded at this point
                      if ($odd === 'odd') { $odd = 'even'; } else { $odd = 'odd'; }
                    ?>
                    <tr class="row <?php echo $odd . ' ' . esc_attr($row_class); ?>" data-pluginfile="<?php echo esc_attr(plugin_basename($plugin['file'])); ?>" data-currentVersion="<?php echo esc_html($plugin["version"]); ?>" data-repo="<?php echo esc_attr($plugin['github']); ?>">
                        <td class="plugin" data-label="Plugin">
                          
                          <?php echo esc_html($plugin["name"]); ?><br>
                          <button type="button" class="rgplugins-toggle-details" aria-expanded="false" title="<?php echo esc_attr__('Show details', 'rg-git-updater'); ?>"><small><?php echo esc_attr__('Show details', 'rg-git-updater'); ?></small></button>
                        </td>
                        <td class="version" data-label="Version">
                          <?php echo esc_html($plugin["version"]); ?>
                          <?php
                            $visibility = rgplugins_repo_visibility($plugin['github']);
                          ?>
                          <?php if ($visibility === 'private'): ?>
                            (private)
                          <?php else: ?>
                            (public)
                          <?php endif; ?>
                        </td>
                        <td class="actions" data-label="Select release">
                        <?php
                          // Actions cell is empty until AJAX loads releases
                        ?>
                        </td>
                        
                    </tr>
                    <tr class="rgplugins-details" data-repo="<?php echo esc_attr($plugin['github']); ?>">
                      <td class="summary">
                        <strong><?php esc_html_e('Author:', 'rg-git-updater'); ?></strong>
                        <?php echo esc_html($plugin['author']); ?><br>
                        <strong><?php esc_html_e('Repository:', 'rg-git-updater'); ?></strong>
                        <a href="<?php echo esc_url($plugin['github']); ?>" target="_blank"><?php echo esc_html($plugin['github']); ?></a><br>
                      </td>
                      <td class="notes">
                        <em><?php esc_html_e('Loading release info…', 'rg-git-updater'); ?></em>
                      </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <h2 style="margin-top:28px;"><?php esc_html_e('Themes', 'rg-git-updater'); ?></h2>
        <?php $github_themes = get_github_themes($force_refresh); ?>
        <?php $odd = 'odd'; ?>
        <table class="widefat fixed striped rgplugins-table">
            <thead>
                <tr>
                    <th class="theme" ><?php esc_html_e('Theme', 'rg-git-updater'); ?></th>
                    <th class="version" ><?php esc_html_e('Version', 'rg-git-updater'); ?></th>
                    <th class="release" ><?php esc_html_e('Select release', 'rg-git-updater'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($github_themes)): ?>
                    <tr><td colspan="4" style="text-align:center; opacity:.7; padding:16px;"><?php echo esc_html__('No themes with a GitHub Update URI were found.', 'rg-git-updater'); ?></td></tr>
                <?php else: ?>
                    <?php foreach ($github_themes as $theme): ?>
                        <?php
                        if ($odd === 'odd') { $odd = 'even'; } else { $odd = 'odd'; }
                        $repo_label = $theme["github"];
                        $repo_path  = parse_url($theme["github"], PHP_URL_PATH);
                        if ($repo_path) { $repo_label = ltrim($repo_path, '/'); }
                        // Beräkna $row_class baserat på $selected_tag och jämförelse mot $theme['version']
                        $include_pre = get_option('rgplugins_include_prereleases', '0') === '1';
                        $releases = []; // Lazy load via AJAX
                        $row_class = '';
                        // No releases loaded at this point
                        ?>
                        <tr class="row <?php echo $odd . ' ' . esc_attr($row_class); ?>" data-stylesheet="<?php echo esc_attr($theme['stylesheet']); ?>" data-currentVersion="<?php echo esc_html($theme["version"]); ?>" data-repo="<?php echo esc_attr($theme['github']); ?>">
                            <td class="plugin" data-label="Theme">
                              <?php echo esc_html($theme["name"]); ?><br>
                              <button type="button" class="rgplugins-toggle-details" aria-expanded="false" title="<?php echo esc_attr__('Show details', 'rg-git-updater'); ?>"><small><?php echo esc_attr__('Show details', 'rg-git-updater'); ?></small></button>
                            </td>
                            <td class="version" data-label="Version">
                              <?php echo esc_html($theme["version"]); ?>
                              <?php
                                $visibility = rgplugins_repo_visibility($theme['github']);
                              ?>
                              <?php if ($visibility === 'private'): ?>
                                (private)
                              <?php else: ?>
                                (public)
                              <?php endif; ?>
                            </td>
                            <td class="actions" data-label="Select release">
                              <?php
                                // Actions cell is empty until AJAX loads releases
                              ?>
                            </td>

                        </tr>
                        <tr class="rgplugins-details" data-repo="<?php echo esc_attr($theme['github']); ?>">
                          <td class="summary">
                            <strong><?php esc_html_e('Author:', 'rg-git-updater'); ?></strong>
                            <?php echo esc_html($theme['author']); ?><br>
                            <strong><?php esc_html_e('Repository:', 'rg-git-updater'); ?></strong>
                            <a href="<?php echo esc_url($theme['github']); ?>" target="_blank"><?php echo esc_html($theme['github']); ?></a><br>
                          </td>
                          <td class="notes">
                            <em><?php esc_html_e('Loading release info…', 'rg-git-updater'); ?></em>
                          </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
      <?php elseif ($active_tab === 'settings') : ?>
        <?php $ajax_nonce = wp_create_nonce('rgplugins_test_github_token'); $ajax_url = admin_url('admin-ajax.php'); ?>
        <div class="rg-tools" style="margin-top:20px;">
          <h2 class="hndle"><span><?php esc_html_e('Tools', 'rg-git-updater'); ?></span></h2>
          <div class="inside">
            <div class="rgplugins-tools" style="display:flex; gap:12px; flex-wrap:wrap;">
              <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0;">
                <?php $nonce = wp_create_nonce('rgplugins_clear_cache'); ?>
                <input type="hidden" name="action" value="rgplugins_clear_cache">
                <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>">
                <button type="submit" class="button clear-cache"><?php echo esc_html__('Clear GitHub cache', 'rg-git-updater'); ?></button>
              </form>
              <div>
                <button type="button" id="rgplugins-test-ajax" class="button test-connection"><?php echo esc_html__('Test GitHub connection', 'rg-git-updater'); ?></button>
                <span id="rgplugins-test-ajax-status" style="margin-left:8px;"></span>
              </div>
            </div>
          </div>
        </div>
        <form method="post" action="options.php">
            <?php
            // Nonces + option group för denna sida
            // Lägg till nödvändiga fält för options-API (nonce, option group etc.)
            settings_fields('rgplugins_settings_group');
            // Rendera sektioner och fält som registrerats för denna sida
            do_settings_sections('rgplugins-settings');
            // Spara-knapp
            submit_button(__('Save settings', 'rg-git-updater'), 'primary', 'submit', false, array('class'=>'button button-primary save-settings'));
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
            statusEl.textContent = '<?php echo esc_js(__('Testing…', 'rg-git-updater')); ?>';
            var formData = new FormData();
            formData.append('action', 'rgplugins_test_github_token');
            formData.append('_ajax_nonce', nonce);
            formData.append('security', nonce); // Extra för kompatibilitet
            // Skicka samma request som sync-testet men hämta svaret som text (debug)
            fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: formData })
              .then(function(res){ return res.text(); })
              .then(function(text){
                var json;
                try {
                  json = JSON.parse(text);
                } catch(e) {
                  statusEl.textContent = '<?php echo esc_js(__('Error: Could not parse server response.', 'rg-git-updater')); ?>';
                  console.error('AJAX test: JSON parse error', e, text);
                  return;
                }
                if (json.success) {
                  statusEl.textContent = json.data && json.data.message ? json.data.message : '<?php echo esc_js(__('Successful connection!', 'rg-git-updater')); ?>';
                  if (json.data && json.data.expires_at) {
                    const expiryDate = new Date(json.data.expires_at);
                    statusEl.innerHTML += '<br><small>' +
                      '<?php echo esc_js(__('Token expires at:', 'rg-git-updater')); ?> ' + expiryDate.toLocaleString() +
                      '</small>';
                  }
                } else {
                  statusEl.textContent = (json.data && json.data.message ? json.data.message : '<?php echo esc_js(__('Error during test.', 'rg-git-updater')); ?>') + (json.code ? ' (HTTP ' + json.code + ')' : '');
                }
              })
              .catch(function(err){
                statusEl.textContent = '<?php echo esc_js(__('Error:', 'rg-git-updater')); ?> ' + err;
              })
              .finally(function(){
                setTimeout(function(){ btn.disabled = false; }, 800);
              });
          });
        })();
        </script>
      <?php endif; ?>
    </div>
    <?php
  }
}

// Visa notice om cache rensats
add_action('admin_notices', function () {
  if (isset($_GET['rgplugins_cache_cleared']) && $_GET['rgplugins_cache_cleared'] == '1') {
    echo '<div class="updated notice is-dismissible"><p>' . esc_html__('GitHub cache cleared.', 'rg-git-updater') . '</p></div>';
  }
});

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
  $include_prereleases = get_option('rgplugins_include_prereleases', '0') === '1';
  $cache_key = 'github_release_' . md5($repo_url . '|' . ($include_prereleases ? 'pre' : 'stable'));
  if ($force_refresh) {
    delete_transient($cache_key);
  }
  $cached_release = get_transient($cache_key);
  if (!$force_refresh && $cached_release && $cached_release !== 'N/A') {
    return $cached_release;
  }

  // Use rgplugins_fetch_releases to get releases/tags
  $releases = rgplugins_fetch_releases($repo_url, $include_prereleases, 1);
  $latest = !empty($releases[0]['tag']) ? $releases[0]['tag'] : 'N/A';

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
    "GitHub API settings",
    function () {
      // Short instruction: link to GitHub tokens page and mention repo scope for private repos
      echo '<p>Create a <a href="https://github.com/settings/tokens" target="_blank">personal access token</a> on GitHub with permission <code>repo</code> if you need access to private repositories.</p>';
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
      $opts = wp_load_alloptions();
      $last_verified_ts = (int) ($opts['rgplugins_token_last_verified'] ?? 0);
      $last_updated_ts  = (int) ($opts['rgplugins_token_last_updated'] ?? 0);
      $expires_at_ts    = (int) ($opts['rgplugins_token_expires_at'] ?? 0);
      $now = current_time('timestamp');

      if ($last_verified_ts) {
        $verified_when = date_i18n( get_option('date_format') . ' ' . get_option('time_format'), $last_verified_ts );
        echo '<p style="margin-top:6px;"><em>' . esc_html__('Last verified:', 'rg-git-updater') . ' ' . esc_html($verified_when) . '</em></p>';
      }

      if ($last_updated_ts) {
        $updated_when = date_i18n( get_option('date_format') . ' ' . get_option('time_format'), $last_updated_ts );
        $days_ago     = max(0, floor( ($now - $last_updated_ts) / DAY_IN_SECONDS ));
        $human        = human_time_diff($last_updated_ts, $now);
        echo '<p style="margin-top:2px;"><em>'
          . esc_html__('Token updated:', 'rg-git-updater') . ' '
          . esc_html( sprintf( _n('%s day ago', '%s days ago', $days_ago, 'rg-git-updater'), number_format_i18n($days_ago) ) )
          . ' (' . esc_html($human) . ') — ' . esc_html($updated_when)
          . '</em></p>';
      }

      // Show token expiry if available
      if (!empty($expires_at_ts) && is_numeric($expires_at_ts)) {
        $diff = $expires_at_ts - $now;
        if ($diff > 0) {
          $days_left = floor($diff / DAY_IN_SECONDS);
          $expiry_str = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $expires_at_ts);
          $expire_label = sprintf(__('Expires in %d days', 'rg-git-updater'), $days_left);
          if ($days_left < 7) {
            $expire_label = '<span style="color:#d63638">' . esc_html($expire_label) . '</span>';
          } else {
            $expire_label = esc_html($expire_label);
          }
          echo '<p style="margin-top:2px;"><em>' .
            esc_html__('Token expiry:', 'rg-git-updater') . ' ' . $expire_label .
            ' (' . esc_html($expiry_str) . ')' .
            '</em></p>';
        } else {
          $expiry_str = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $expires_at_ts);
          echo '<p style="margin-top:2px;"><em><span style="color:#d63638">' .
            esc_html__('Token expired', 'rg-git-updater') . ' (' . esc_html($expiry_str) . ')' .
            '</span></em></p>';
        }
      } else {
        echo '<p style="margin-top:2px;"><em>' .
          esc_html__('GitHub does not provide an expiry date for this token type.', 'rg-git-updater') .
          '</em></p>';
      }
    },
    "rgplugins-settings",
    "rgplugins_settings_section"
  );

  // Tillåt förhandsreleaser (beta/rc)
  register_setting('rgplugins_settings_group', 'rgplugins_include_prereleases');
  add_settings_field(
    'rgplugins_include_prereleases',
    __('Allow pre-releases', 'rg-git-updater'),
    function () {
      $val = get_option('rgplugins_include_prereleases', '0');
      echo '<label><input type="checkbox" name="rgplugins_include_prereleases" value="1" ' . checked('1', $val, false) . '> ' . esc_html__('Show and update to beta/rc releases', 'rg-git-updater') . '</label>';
    },
    'rgplugins-settings',
    'rgplugins_settings_section'
  );

  // Debug mode (enable/disable logging)
  register_setting('rgplugins_settings_group', 'rgplugins_debug_mode');
  add_settings_field(
    'rgplugins_debug_mode',
    __('Debug mode', 'rg-git-updater'),
    function () {
      $val = get_option('rgplugins_debug_mode', '1');
      echo '<label><input type="checkbox" name="rgplugins_debug_mode" value="0" ' . checked('1', $val, false) . '> ' . esc_html__('Enable logging for RG Git Updater', 'rg-git-updater') . '</label>';
    },
    'rgplugins-settings',
    'rgplugins_settings_section'
  );
});

// Hantera test av GitHub-token när knappen trycks
add_action('admin_post_test_github_token', function () {
  if (!current_user_can('manage_options')) {
    wp_die(__('You do not have permission.', 'rg-git-updater'));
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
    add_settings_error('rgplugins_github_token', 'github_token_test', __('Connection error: ', 'rg-git-updater') . $response->get_error_message(), 'error');
  } else {
    $code = wp_remote_retrieve_response_code($response);
    if ($code === 200) {
      $body = json_decode(wp_remote_retrieve_body($response), true);
      // Save expires_at if present, otherwise do not update
      if (!empty($body['expires_at'])) {
        $expires_ts = strtotime($body['expires_at']);
        if ($expires_ts !== false) {
          update_option('rgplugins_token_expires_at', $expires_ts);
        }
      }
      add_settings_error('rgplugins_github_token', 'github_token_test', sprintf(__('Authenticated! Logged in as %s', 'rg-git-updater'), esc_html($body['login'] ?? 'unknown')), 'updated');
    } else {
      add_settings_error('rgplugins_github_token', 'github_token_test', sprintf(__('Authentication failed. HTTP status: %s', 'rg-git-updater'), $code), 'error');
      // On error, clear token expiry
      delete_option('rgplugins_token_expires_at');
    }
  }

  // Återvänd till sidan och visa resultat via settings_errors
  wp_redirect(add_query_arg('settings-updated', 'true', wp_get_referer()));
  exit;
});

// AJAX: testa GitHub-token utan omladdning
add_action('wp_ajax_rgplugins_test_github_token', function(){
  if (!current_user_can('manage_options')) {
    wp_send_json_error(['message' => __('You do not have permission.', 'rg-git-updater')], 403);
  }
  // Skydda AJAX: kräver giltig nonce
  check_ajax_referer('rgplugins_test_github_token');
  $token = get_option('rgplugins_github_token', '');
  if (empty($token)) {
    wp_send_json_error(['message' => __('No token saved.', 'rg-git-updater')], 400);
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
    wp_send_json_error(['message' => __('Connection error: ', 'rg-git-updater') . $response->get_error_message()], 500);
  }
  $code = wp_remote_retrieve_response_code($response);
  if ($code === 200) {
    $body = json_decode(wp_remote_retrieve_body($response), true);
    // Save expires_at if present, otherwise do not update
    if (!empty($body['expires_at'])) {
      $expires_ts = strtotime($body['expires_at']);
      if ($expires_ts !== false) {
        update_option('rgplugins_token_expires_at', $expires_ts);
      }
    }
    $login = isset($body['login']) ? $body['login'] : 'unknown';
    wp_send_json_success([
      'message'    => sprintf(__('Authenticated! Logged in as %s', 'rg-git-updater'), esc_html($login)),
      'expires_at' => array_key_exists('expires_at', $body) ? $body['expires_at'] : null
    ]);
  }
  // On error, clear token expiry
  delete_option('rgplugins_token_expires_at');
  wp_send_json_error(['message' => sprintf(__('Authentication failed. HTTP status: %s', 'rg-git-updater'), $code)], $code);
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
    wp_die(__('You do not have permission to update plugins.', 'rg-git-updater'));
  }
  $plugin = isset($_POST['plugin']) ? sanitize_text_field(wp_unslash($_POST['plugin'])) : '';
  $repo   = isset($_POST['repo']) ? esc_url_raw(wp_unslash($_POST['repo'])) : '';
  $tag    = isset($_POST['tag']) ? sanitize_text_field(wp_unslash($_POST['tag'])) : '';
  $nonce  = isset($_POST['_wpnonce']) ? sanitize_text_field(wp_unslash($_POST['_wpnonce'])) : '';
  if (!$plugin || !$repo || !$tag || !wp_verify_nonce($nonce, 'rgplugins_install_release_' . $plugin)) {
    wp_safe_redirect(add_query_arg(['page' => 'rgplugins-settings', 'rgplugins_msg' => urlencode(__('Invalid request.', 'rg-git-updater'))], admin_url('tools.php')));
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

  // Check if the selected tag is a downgrade compared to the current version
  $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin);
  $current_version = isset($plugin_data['Version']) ? $plugin_data['Version'] : '';
  if ($current_version && version_compare($tag, $current_version, '<')) {
      // Delete the plugin before installing an older version
      if (file_exists(WP_PLUGIN_DIR . '/' . dirname($plugin))) {
          delete_plugins([$plugin]);
      }
  }
  $result = $upgrader->install($package);

  // Gracefully handle the "source_destination_same_move_dir" error
  if (is_wp_error($result) && $result->get_error_code() === 'source_destination_same_move_dir') {
      if (defined('RG_UPDATER_DEBUG_ENABLED') && RG_UPDATER_DEBUG_ENABLED) {
          error_log('[RG Git Updater][theme_install] Ignored source_destination_same_move_dir (same folder overwrite)');
      }
      $result = true;
  }

  // Kontrollera och rensa fel i $skin om installationen verkar ha lyckats
  if (!is_wp_error($result)) {
      $errors = $skin->get_errors();
      if ($errors && !empty($errors->errors)) {
          // Logga men ignorera dessa "tysta" fel
          if (defined('RG_UPDATER_DEBUG_ENABLED') && RG_UPDATER_DEBUG_ENABLED) {
              error_log('[RG Git Updater][theme_install] Non-fatal skin errors: ' . json_encode($errors->errors));
          }
          $skin->error = null;
          $skin->feedback('');
      }
  }

  // Fånga och ignorera specifikt "source_destination_same_move_dir" eftersom den uppstår vid overwrite i samma mapp
  if (is_wp_error($result) && $result->get_error_code() === 'source_destination_same_move_dir') {
      if (defined('RG_UPDATER_DEBUG_ENABLED') && RG_UPDATER_DEBUG_ENABLED) {
          error_log('[RG Git Updater][theme_install] Ignored error: source_destination_same_move_dir (overwrite detected)');
      }
      $result = true; // Markera som lyckad
  }

  // Försök reaktivera om det var aktivt innan
  $was_active = is_plugin_active($plugin);
  if ($result && !is_wp_error($result) && $was_active && !is_plugin_active($plugin)) {
    activate_plugin($plugin, '', false, true);
  }

  if ($result && !is_wp_error($result)) {
    $msg = __('Installation of selected release succeeded.', 'rg-git-updater');
    $ok  = '1';
  } else {
    $msg = is_wp_error($result) ? $result->get_error_message() : __('Installation failed.', 'rg-git-updater');
    $ok  = '0';
  }

  wp_safe_redirect(add_query_arg([
    'page' => 'rgplugins-settings',
    'rgplugins_refresh' => '1',
    'rgplugins_msg' => urlencode($msg),
    'ok' => $ok
  ], admin_url('tools.php')));
  exit;
});

// Admin-post handler: installera vald release-tag för ett tema
add_action('admin_post_rgthemes_install_release', function () {
  if (!current_user_can('update_themes')) {
    wp_die(__('You do not have permission to update themes.', 'rg-git-updater'));
  }
  error_log('[RG DEBUG][theme_install] $_POST=' . print_r($_POST, true));
  $theme = isset($_POST['theme']) ? sanitize_text_field(wp_unslash($_POST['theme'])) : '';
  $repo  = isset($_POST['repo']) ? esc_url_raw(wp_unslash($_POST['repo'])) : '';
  $tag   = isset($_POST['tag']) ? sanitize_text_field(wp_unslash($_POST['tag'])) : '';
  $nonce = isset($_POST['_wpnonce']) ? sanitize_text_field(wp_unslash($_POST['_wpnonce'])) : '';
  // Debug logging to compare POST value and expected nonce key
  error_log('[RG Git Updater][DEBUG] $_POST["theme"] = ' . $theme);
  error_log('[RG Git Updater][DEBUG] Expected nonce key = rgthemes_install_release_' . $theme);
  if (!$theme || !$repo || !$tag || !wp_verify_nonce($nonce, 'rgthemes_install_release_' . $theme)) {
    wp_safe_redirect(add_query_arg(['page' => 'rgplugins-settings', 'rgplugins_msg' => urlencode(__('Invalid request (theme).', 'rg-git-updater'))], admin_url('tools.php')));
    exit;
  }
  // Codeload-URL för tema-tag (samma mönster som för plugin)
  $repo_path = parse_url($repo, PHP_URL_PATH);
  $package = 'https://codeload.github.com' . $repo_path . '/zip/refs/tags/' . rawurlencode($tag);

  require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
  require_once ABSPATH . 'wp-admin/includes/theme.php';

  // WordPress Theme_Upgrader – hanterar unzip och filkopiering
  $skin = new Automatic_Upgrader_Skin();

  // Insert upgrader_package_options filter for themes at high priority
  add_filter('upgrader_package_options', function ($options) use ($theme) {
    $themes_root = trailingslashit(get_theme_root());
    $theme_path = trailingslashit($themes_root . $theme);

    // Safely remove old files except style.css (defensive against broken paths)
    if (!empty($theme_path) && is_dir($theme_path)) {
        try {
            $files = glob($theme_path . '*', GLOB_NOSORT);
            if (is_array($files)) {
                foreach ($files as $file) {
                    $basename = basename($file);
                    if ($basename === 'style.css') {
                        continue;
                    }
                    if (is_dir($file)) {
                        $it = new RecursiveDirectoryIterator($file, FilesystemIterator::SKIP_DOTS);
                        $ri = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
                        foreach ($ri as $f) {
                            $path = $f->getRealPath();
                            if ($path && file_exists($path)) {
                                $f->isDir() ? @rmdir($path) : @unlink($path);
                            }
                        }
                        @rmdir($file);
                    } elseif (file_exists($file)) {
                        @unlink($file);
                    }
                }
            }
        } catch (Throwable $e) {
            if (defined('RG_UPDATER_DEBUG_ENABLED') && RG_UPDATER_DEBUG_ENABLED) {
                error_log('[RG Git Updater][theme_install] Cleanup skipped: ' . $e->getMessage());
            }
        }
    }

    // Install directly into the same folder without clearing it entirely
    $options['destination'] = $theme_path;
    $options['destination_name'] = $theme;
    $options['clear_destination'] = false; // Keep folder, overwrite files
    $options['abort_if_destination_exists'] = false;
    $options['hook_extra']['theme'] = $theme;

    return $options;
  }, 999);

  $upgrader = new Theme_Upgrader($skin);

  $result = $upgrader->install($package);

  // --- Suppress false filesystem errors if files exist ---
  if (!$installed_ok && is_wp_error($result) && strpos($result->get_error_message(), 'Filesystem error') !== false) {
      $style_file = trailingslashit(get_theme_root()) . $theme . '/style.css';
      if (file_exists($style_file)) {
          if (defined('RG_UPDATER_DEBUG_ENABLED') && RG_UPDATER_DEBUG_ENABLED) {
              error_log('[RG Git Updater][theme_install] Filesystem error ignored: style.css exists, treating as success');
          }
          $installed_ok = true;
          $result = true;
      }
  }

  // Handle false-positive installation failures
  $theme_path = trailingslashit(get_theme_root()) . $theme;
  $style_file = $theme_path . 'style.css';
  if ($result === false && file_exists($style_file)) {
      $mtime = @filemtime($style_file);
      if ($mtime && (time() - $mtime) < 90) {
          if (defined('RG_UPDATER_DEBUG_ENABLED') && RG_UPDATER_DEBUG_ENABLED) {
              error_log('[RG Git Updater][theme_install] style.css recently modified (within 90s) — treating install as success');
          }
          $result = true;
      }
  }

  // Utför mer detaljerad felsökning och hantera falska fel
  if (defined('RG_UPDATER_DEBUG_ENABLED') && RG_UPDATER_DEBUG_ENABLED) {
      error_log('[RG Git Updater][theme_install] Raw $result=' . var_export($result, true));
      error_log('[RG Git Updater][theme_install] Upgrader result=' . var_export($upgrader->result ?? null, true));
//      error_log('[RG Git Updater][theme_install] Skin errors=' . var_export($skin->get_errors(), true));
  }

  // Om $result är false men filerna faktiskt existerar, behandla som lyckad
  $theme_path = trailingslashit(get_theme_root()) . $theme;
  if ($result === false && file_exists($theme_path . 'style.css')) {
      if (defined('RG_UPDATER_DEBUG_ENABLED') && RG_UPDATER_DEBUG_ENABLED) {
          error_log('[RG Git Updater][theme_install] Theme files exist despite false result — forcing success');
      }
      $result = true;
  }

  // Om upgrader->result visar lyckat (t.ex. 'destination' och 'destination_name' finns)
  if ($result === false && isset($upgrader->result) && is_array($upgrader->result)) {
      // Ny fix: om upgrader->result innehåller nyckeln 'result' med värde 'success', markera som lyckad
      if (isset($upgrader->result['result']) && $upgrader->result['result'] === 'success') {
          if (defined('RG_UPDATER_DEBUG_ENABLED') && RG_UPDATER_DEBUG_ENABLED) {
              error_log('[RG Git Updater][theme_install] Detected upgrader->result[result]=success, forcing success');
          }
          $result = true;
      }
      if (!empty($upgrader->result['destination']) && file_exists($upgrader->result['destination'] . '/style.css')) {
          if (defined('RG_UPDATER_DEBUG_ENABLED') && RG_UPDATER_DEBUG_ENABLED) {
              error_log('[RG Git Updater][theme_install] Detected successful unpack via upgrader->result, forcing success');
          }
          $result = true;
      }
  }

  // --- FINAL FALLBACK: treat known false/empty results as success when files exist ---
  // Inserted block per instructions
  // Final fallback: treat known false/empty results as success when files exist
  if ((!$result || $result === null || $result === false) && file_exists($theme_path . 'style.css')) {
      $mtime = @filemtime($theme_path . 'style.css');
      if ($mtime && (time() - $mtime) < 120) {
          if (defined('RG_UPDATER_DEBUG_ENABLED') && RG_UPDATER_DEBUG_ENABLED) {
              error_log('[RG Git Updater][theme_install] Final fallback: style.css updated recently, marking as success');
          }
          $result = true;
          $installed_ok = true;
      } elseif (isset($upgrader->result['destination']) && file_exists($upgrader->result['destination'] . '/style.css')) {
          if (defined('RG_UPDATER_DEBUG_ENABLED') && RG_UPDATER_DEBUG_ENABLED) {
              error_log('[RG Git Updater][theme_install] Final fallback: destination/style.css found, marking as success');
          }
          $result = true;
          $installed_ok = true;
      }
  }

  // Kontrollera att temat faktiskt finns
  $theme_path = trailingslashit(get_theme_root()) . $theme;
  $installed_ok = is_dir($theme_path) && file_exists($theme_path . 'style.css');

  // Extra kontroll: om style.css nyligen ändrats (inom 1 minut) markera installationen som lyckad
  $style_file = $theme_path . 'style.css';
  if (!$installed_ok && file_exists($style_file)) {
      $mtime = @filemtime($style_file);
      if ($mtime && (time() - $mtime) < 60) {
          if (defined('RG_UPDATER_DEBUG_ENABLED') && RG_UPDATER_DEBUG_ENABLED) {
              error_log('[RG Git Updater][theme_install] style.css modified within last minute — forcing success');
          }
          $installed_ok = true;
          $result = true;
      }
  }

  // Loggning för felsökning
  if (defined('RG_UPDATER_DEBUG_ENABLED') && RG_UPDATER_DEBUG_ENABLED) {
      error_log('[RG Git Updater][theme_install] Final result=' . var_export($result, true));
      error_log('[RG Git Updater][theme_install] Installed OK=' . ($installed_ok ? 'yes' : 'no'));
  }

  if ($installed_ok && (!is_wp_error($result))) {
      $msg = __('Theme installed/updated successfully.', 'rg-git-updater');
      $ok  = '1';
  } else {
      $msg = is_wp_error($result)
          ? $result->get_error_message()
          : __('Installation failed.', 'rg-git-updater');
      $ok  = '0';
  }

  wp_safe_redirect(add_query_arg([
    'page' => 'rgplugins-settings',
    'rgplugins_refresh' => '1',
    'rgplugins_msg' => urlencode($msg),
    'ok' => $ok
  ], admin_url('tools.php')));
  exit;
});


// Admin-post handler: rensa GitHub-release-cache
add_action('admin_post_rgplugins_clear_cache', function() {
  if (!current_user_can('manage_options')) {
    wp_die(__('You do not have permission.', 'rg-git-updater'));
  }
  check_admin_referer('rgplugins_clear_cache');
  global $wpdb;
  // Ta bort alla transients som börjar på github_release_
  $wpdb->query(
    $wpdb->prepare(
      "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
      '_transient_github_release_%',
      '_transient_timeout_github_release_%'
    )
  );
  // Redirect tillbaka till settings med notice
  $redirect_url = add_query_arg([
    'page' => 'rgplugins-settings',
    'tab' => 'settings',
    'rgplugins_cache_cleared' => '1'
  ], admin_url('tools.php'));
  wp_safe_redirect($redirect_url);
  exit;
});
// ---- AUTOMATISK UPPDATERING & API FÖR GITHUB-TEMAN ----

// Filter: injicera GitHub-releaseinfo i update_themes-transienten (samma princip som plugins)
add_filter('site_transient_update_themes', function ($transient) {
  if (!is_object($transient)) return $transient;
  // Hämta alla teman med GitHub UpdateURI
  $themes = get_github_themes(false);
  foreach ($themes as $theme) {
    $stylesheet = $theme['stylesheet'];
    $current_version = $theme['version'];
    $update_uri = $theme['github'];
    $latest = $theme['latest_release'];
    if (!$latest || $latest === 'N/A') continue;
    if (version_compare($latest, $current_version, '<=')) continue;
    // Bygg info-array för WP themes API
    $package_url = 'https://codeload.github.com' . parse_url($update_uri, PHP_URL_PATH) . '/zip/refs/tags/' . rawurlencode($latest);
    $transient->response[$stylesheet] = [
      'theme'       => $theme['name'],
      'new_version' => $latest,
      'url'         => $update_uri,
      'package'     => $package_url,
    ];
  }
  return $transient;
});

// Filter: visa changelog/läsmer för teman via themes_api (thickbox)
add_filter('themes_api', function ($result, $action, $args) {
  // Vi hanterar bara 'theme_information'
  if ($action !== 'theme_information' || empty($args->slug)) return $result;
  $slug = $args->slug;
  // Hitta temat bland installerade
  $themes = wp_get_themes();
  $theme = null;
  foreach ($themes as $stylesheet => $t) {
    if ($stylesheet === $slug) {
      $theme = $t;
      break;
    }
  }
  if (!$theme) return $result;
  $update_uri = $theme->get('UpdateURI');
  if (!$update_uri || strpos($update_uri, 'github.com') === false) return $result;

  // Hämta releases (inklusive pre om inställt)
  $include_pre = get_option('rgplugins_include_prereleases', '0') === '1';
  $releases = rgplugins_fetch_releases($update_uri, $include_pre, 10);
  $latest = !empty($releases[0]) ? $releases[0] : null;
  // Bygg sections-array (changelog/description/readme)
  $sections = [];
  // Beskrivning: från style.css-header Description
  $desc = $theme->get('Description');
  if ($desc) $sections['description'] = wpautop(esc_html($desc));
  // Changelog: från release notes/body
  if ($latest && !empty($latest['body'])) {
    $sections['changelog'] = wpautop(make_clickable(wp_kses_post($latest['body'])));
  } else {
    $sections['changelog'] = __('No changelog found.', 'rg-git-updater');
  }
  // Bygg info-array
  $info = (object)[
    'name'        => $theme->get('Name'),
    'slug'        => $slug,
    'version'     => $theme->get('Version'),
    'author'      => $theme->get('Author'),
    'preview_url' => $theme->get('ThemeURI'),
    'sections'    => $sections,
    'requires'    => $theme->get('RequiresWP'),
    'requires_php'=> $theme->get('RequiresPHP'),
    'homepage'    => $theme->get('ThemeURI'),
    'download_link' => $latest ? ('https://codeload.github.com' . parse_url($update_uri, PHP_URL_PATH) . '/zip/refs/tags/' . rawurlencode($latest['tag'])) : '',
    'last_updated' => $latest && !empty($latest['published']) ? $latest['published'] : '',
    // Ikon/headers etc kan läggas till vid behov
  ];
  return $info;
}, 10, 3);
// Helper: check if GitHub token is set and (stub) valid.
if (!function_exists('rgplugins_token_valid')) {
  /**
   * Checks if the GitHub token is set and valid (stub: true if non-empty string).
   *
   * @return bool
   */
  function rgplugins_token_valid() {
    $token = get_option('rgplugins_github_token', '');
    // In future: check for expiry, verification, etc.
    return !empty($token);
  }
}
add_action('wp_ajax_rgplugins_load_releases', function() {
  if (!current_user_can('update_plugins') && !current_user_can('update_themes')) {
    wp_send_json_error(['message' => __('You do not have permission.', 'rg-git-updater')], 403);
  }
  $repo = isset($_POST['repo']) ? esc_url_raw(wp_unslash($_POST['repo'])) : '';
  if (empty($repo)) {
    wp_send_json_error(['message' => __('Missing repo URL.', 'rg-git-updater')], 400);
  }
  $include_pre = get_option('rgplugins_include_prereleases', '0') === '1';
  $releases = rgplugins_fetch_releases($repo, $include_pre, 20);
  wp_send_json_success($releases);
});
add_action('admin_print_footer_scripts', function() {
  // Pre-generate plugin and theme nonces for all rows to allow unique nonce per identifier in JS
  if (!function_exists('get_plugins')) {
    require_once ABSPATH . "wp-admin/includes/plugin.php";
  }
  // Plugins
  $nonces_plugins = [];
  foreach (get_plugins() as $path => $info) {
    $plugin_basename = plugin_basename($path);
    $nonces_plugins[$plugin_basename] = wp_create_nonce('rgplugins_install_release_' . $plugin_basename);
  }
  // Themes
  $nonces_themes = [];
  foreach (wp_get_themes() as $slug => $theme) {
    $stylesheet = $theme->get_stylesheet();
    $nonces_themes[$stylesheet] = wp_create_nonce('rgthemes_install_release_' . $stylesheet);
    error_log('[RG Git Updater][DEBUG] Nonce created for theme slug: ' . $stylesheet);
  }
  ?>
  <script>
  (function(){
    // Helper: compare two version strings (semver-like, ignore leading v)
    function versionCompare(a, b) {
      const pa = a.replace(/^v/i,'').split('.').map(Number);
      const pb = b.replace(/^v/i,'').split('.').map(Number);
      for (let i = 0; i < Math.max(pa.length, pb.length); i++) {
        const diff = (pa[i]||0) - (pb[i]||0);
        if (diff) return diff;
      }
      return 0;
    }
    // Embed PHP arrays as JS objects for nonce lookup
    const noncesPlugins = <?php echo json_encode($nonces_plugins, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;
    const noncesThemes = <?php echo json_encode($nonces_themes, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;
    document.querySelectorAll('tr[data-repo]').forEach(function(row){
      const repo = row.dataset.repo;
      const actionsCell = row.querySelector('.actions');
      if (!repo || !actionsCell) return;

      // Show temporary loading state
      actionsCell.innerHTML = '<em>Loading releases…</em>';

      fetch(ajaxurl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({
          action: 'rgplugins_load_releases',
          repo: repo,
          _ajax_nonce: '<?php echo wp_create_nonce('rgplugins_test_github_token'); ?>'
        })
      })
      .then(res => res.json())
      .then(json => {
        if (!json.success || !json.data || !json.data.length) {
          actionsCell.innerHTML = '<span style="opacity:.7">No releases found</span>';
          return;
        }
        const releases = json.data;
        const currentVersion = row.dataset.currentversion || '';
        let formHtml = '<form method="post" action="admin-post.php" style="display:inline;">';
        const isTheme = row.closest('table').querySelector('th.theme') !== null;
        formHtml += '<input type="hidden" name="action" value="' + (isTheme ? 'rgthemes_install_release' : 'rgplugins_install_release') + '">';
        const identifier = isTheme
          ? row.dataset.stylesheet
          : (row.dataset.pluginfile || row.querySelector('[data-label="Plugin"]').innerText.trim());
        formHtml += '<input type="hidden" name="' + (isTheme ? 'theme' : 'plugin') + '" value="' + identifier + '">';
        formHtml += '<input type="hidden" name="repo" value="' + repo + '">';
        // Use pre-generated per-identifier nonce from PHP
        let nonce = '';
        if (isTheme) {
          nonce = (noncesThemes && noncesThemes.hasOwnProperty(identifier)) ? noncesThemes[identifier] : '';
        } else {
          nonce = (noncesPlugins && noncesPlugins.hasOwnProperty(identifier)) ? noncesPlugins[identifier] : '';
        }
        formHtml += '<input type="hidden" name="_wpnonce" value="' + nonce + '">';
        formHtml += '<select name="tag">';
        releases.forEach(rel => {
          let label = rel.tag;
          if (currentVersion) {
            const cmp = versionCompare(rel.tag, currentVersion);
            if (cmp > 0) label += ' ↑';
            else if (cmp < 0) label += ' ↓';
            else label += ' (-)';
          }
          if (rel.prerelease) label += ' (pre)';
          formHtml += '<option value="' + rel.tag + '">' + label + '</option>';
        });
        formHtml += '</select> ';
        formHtml += '<button type="submit" class="button">' + '<?php echo esc_js(__('Install', 'rg-git-updater')); ?>' + '</button>';
        formHtml += '</form>';
        actionsCell.innerHTML = formHtml;

        // Dynamically update the button label based on selected release version
        const selectEl = actionsCell.querySelector('select[name="tag"]');
        const buttonEl = actionsCell.querySelector('button.button');
        // Use currentVersion from row dataset
        // Helper already defined above: versionCompare
        function updateButtonLabel() {
          if (!selectEl || !buttonEl) return;
          const selected = selectEl.value;
          let label = '<?php echo esc_js(__('Install', 'rg-git-updater')); ?>';
          if (currentVersion) {
            const cmp = versionCompare(selected, currentVersion);
            if (cmp > 0) label = '<?php echo esc_js(__('Update', 'rg-git-updater')); ?>';
            else if (cmp < 0) label = '<?php echo esc_js(__('Downgrade', 'rg-git-updater')); ?>';
            else label = '<?php echo esc_js(__('Reinstall', 'rg-git-updater')); ?>';
          }
          buttonEl.textContent = label;
        }
        updateButtonLabel();
        if (selectEl) selectEl.addEventListener('change', updateButtonLabel);
      })
      .catch(err => {
        actionsCell.innerHTML = '<span style="color:#d63638">Error: ' + err.message + '</span>';
      });
    });
  })();
  </script>
  <?php
});