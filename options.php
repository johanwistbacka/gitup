<?php
/**
 * GitUp – Admin/Options UI
 *
 * Visar installerade plugins/teman som pekar på GitHub (via UpdateURI),
 * listar senaste releaser, och låter admin installera vald tag direkt.
 *
 * Prestanda
 * ---------
 *  - Denna sida kan göra nätverksanrop (GitHub API). Det sker bara här.
 *  - `get_latest_github_release()` cache:ar taggar (1h) och fel/N/A (5min).
 *  - "Uppdatera lista" sätter `gitup_refresh=1` och bypassar cache.
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
if (!function_exists('gitup_fetch_releases')) {
  function gitup_fetch_releases($repo_url, $include_prereleases = false, $limit = 20) {
    $releases = gitup_get_github_releases_data($repo_url, $include_prereleases, $limit);
    if (!empty($releases) && !empty($releases[0]['error'])) {
      return $releases;
    }

    $normalized = [];
    foreach ($releases as $release) {
      if (empty($release['tag_name'])) {
        continue;
      }
      $normalized[] = [
        'tag'        => $release['tag_name'],
        'name'       => !empty($release['name']) ? $release['name'] : $release['tag_name'],
        'prerelease' => !empty($release['prerelease']),
        'url'        => $release['html_url'] ?? '',
        'published'  => $release['published_at'] ?? '',
        'body'       => $release['body'] ?? '',
      ];
    }

    return $normalized;
  }
}

if (!function_exists('gitup_should_include_prereleases')) {
  function gitup_should_include_prereleases() {
    return get_option('gitup_include_prereleases', '0') === '1';
  }
}

if (!function_exists('gitup_extract_release_tags')) {
  function gitup_extract_release_tags($releases) {
    $valid_tags = [];
    foreach ($releases as $release) {
      if (!empty($release['tag'])) {
        $valid_tags[] = $release['tag'];
      }
    }
    return $valid_tags;
  }
}

if (!function_exists('gitup_validate_release_package_selection')) {
  function gitup_validate_release_package_selection($repo_url, $tag) {
    $releases = gitup_fetch_releases($repo_url, gitup_should_include_prereleases(), 50);
    $valid_tags = gitup_extract_release_tags($releases);

    if (!in_array($tag, $valid_tags, true)) {
      return new WP_Error(
        'gitup_release_not_verified',
        __('Selected release could not be verified.', 'gitup'),
        [
          'releases'   => $releases,
          'valid_tags' => $valid_tags,
        ]
      );
    }

    $package = function_exists('gitup_build_github_package_url') ? gitup_build_github_package_url($repo_url, $tag) : '';
    if ($package === '') {
      return new WP_Error(
        'gitup_package_url_failed',
        __('Could not build GitHub package URL.', 'gitup'),
        [
          'releases'   => $releases,
          'valid_tags' => $valid_tags,
        ]
      );
    }

    return [
      'package'    => $package,
      'releases'   => $releases,
      'valid_tags' => $valid_tags,
    ];
  }
}

if (!function_exists('gitup_get_verified_release_package_url')) {
  function gitup_get_verified_release_package_url($repo_url, $tag, $error_context = 'component') {
    $result = gitup_validate_release_package_selection($repo_url, $tag);
    if (is_wp_error($result)) {
      gitup_redirect_with_notice(
        sprintf(
          __('Selected %s release could not be verified.', 'gitup'),
          $error_context
        )
      );
    }

    return $result;
  }
}

if (!function_exists('gitup_prepare_plugin_release_install')) {
  function gitup_prepare_plugin_release_install($plugin, $tag) {
    $plugins = get_plugins();
    if (empty($plugins[$plugin])) {
      return new WP_Error('gitup_plugin_not_found', __('Plugin not found.', 'gitup'));
    }

    $plugin_info = $plugins[$plugin];
    $repo_url = function_exists('gitup_get_plugin_repo_url') ? gitup_get_plugin_repo_url($plugin_info) : '';
    if ($repo_url === '') {
      return new WP_Error('gitup_plugin_repo_missing', __('No valid GitHub repository configured for this plugin.', 'gitup'));
    }

    $release_package = gitup_validate_release_package_selection($repo_url, $tag);
    if (is_wp_error($release_package)) {
      return new WP_Error('gitup_plugin_release_not_verified', __('Selected plugin release could not be verified.', 'gitup'));
    }

    return [
      'plugin_info' => $plugin_info,
      'repo_url'    => $repo_url,
      'package'     => $release_package['package'],
      'releases'    => $release_package['releases'],
      'valid_tags'  => $release_package['valid_tags'],
    ];
  }
}

if (!function_exists('gitup_prepare_theme_release_install')) {
  function gitup_prepare_theme_release_install($theme_stylesheet, $tag) {
    $themes = wp_get_themes();
    if (empty($themes[$theme_stylesheet])) {
      return new WP_Error('gitup_theme_not_found', __('Theme not found.', 'gitup'));
    }

    $theme = $themes[$theme_stylesheet];
    $repo_url = function_exists('gitup_get_theme_repo_url') ? gitup_get_theme_repo_url($theme) : '';
    if ($repo_url === '') {
      return new WP_Error('gitup_theme_repo_missing', __('No valid GitHub repository configured for this theme.', 'gitup'));
    }

    $release_package = gitup_validate_release_package_selection($repo_url, $tag);
    if (is_wp_error($release_package)) {
      return new WP_Error('gitup_theme_release_not_verified', __('Selected theme release could not be verified.', 'gitup'));
    }

    return [
      'theme'      => $theme,
      'repo_url'   => $repo_url,
      'package'    => $release_package['package'],
      'releases'   => $release_package['releases'],
      'valid_tags' => $release_package['valid_tags'],
    ];
  }
}

if (!function_exists('gitup_build_plugin_package_options_filter')) {
  function gitup_build_plugin_package_options_filter($plugin) {
    return function ($options) use ($plugin) {
      $options['hook_extra']['plugin'] = $plugin;
      if (defined('WP_PLUGIN_DIR')) {
        $expected_dir = dirname($plugin);
        $plugins_dir = trailingslashit(WP_PLUGIN_DIR);
        $options['destination'] = trailingslashit($plugins_dir . $expected_dir);
        $options['destination_name'] = $expected_dir;
        $options['clear_destination'] = true;
        $options['abort_if_destination_exists'] = false;
      }
      return $options;
    };
  }
}

if (!function_exists('gitup_build_theme_package_options_filter')) {
  function gitup_build_theme_package_options_filter($theme_stylesheet) {
    return function ($options) use ($theme_stylesheet) {
      $options['hook_extra']['theme'] = $theme_stylesheet;
      $options['destination'] = get_theme_root();
      unset($options['destination_name']);
      $options['clear_destination'] = true;
      $options['abort_if_destination_exists'] = false;
      if (function_exists('gitup_log')) {
        gitup_log('manual theme install package_options: ' . json_encode($options));
      }
      return $options;
    };
  }
}

if (!function_exists('gitup_get_release_action_state')) {
  function gitup_get_release_action_state($selected_tag, $installed_version) {
    $cmp = function_exists('gitup_compare_version_tags')
      ? gitup_compare_version_tags($selected_tag, $installed_version)
      : version_compare(gitup_normalize_version_tag($selected_tag), gitup_normalize_version_tag($installed_version));

    $state = [
      'row_class'         => '',
      'button_label'      => __('Install', 'gitup'),
      'button_class'      => 'install',
      'confirm_message'   => __('Warning: downgrading may reintroduce bugs or incompatibilities. Make sure you know why you are installing an older release.', 'gitup'),
      'comparison_result' => $cmp,
    ];

    if ($cmp > 0) {
      $state['row_class'] = 'update';
      $state['button_label'] = __('Update', 'gitup');
      $state['button_class'] = 'update';
    } elseif ($cmp < 0) {
      $state['row_class'] = 'downgrade';
      $state['button_label'] = __('Downgrade', 'gitup');
      $state['button_class'] = 'downgrade';
    } else {
      $state['row_class'] = 'reinstall';
      $state['button_label'] = __('Re-install', 'gitup');
      $state['button_class'] = 'reinstall';
    }

    return $state;
  }
}

if (!function_exists('gitup_get_releases_error_code')) {
  function gitup_get_releases_error_code($releases) {
    return (!empty($releases) && !empty($releases[0]['error'])) ? $releases[0]['error'] : '';
  }
}

if (!function_exists('gitup_get_release_empty_state_message')) {
  function gitup_get_release_empty_state_message($repo_url, $releases) {
    $error_code = gitup_get_releases_error_code($releases);
    if ($error_code === 'rate_limit') {
      return __('GitHub API rate limit exceeded. Add a token.', 'gitup');
    }

    $visibility = gitup_repo_visibility($repo_url);
    $token_state = gitup_get_token_state();
    if ($visibility === 'private') {
      if (in_array($token_state, ['missing', 'invalid', 'expired'], true)) {
        return __('Private repo / 404. Update token.', 'gitup');
      }
      if ($token_state === 'unknown') {
        return __('Private repo. Verify token.', 'gitup');
      }
    }

    return __('No releases found', 'gitup');
  }
}

if (!function_exists('gitup_get_settings_page_url')) {
  function gitup_get_settings_page_url($args = []) {
    $defaults = ['page' => 'gitup-settings'];
    return add_query_arg(array_merge($defaults, $args), admin_url('tools.php'));
  }
}

if (!function_exists('gitup_redirect_with_notice')) {
  function gitup_redirect_with_notice($message, $ok = '0', $args = []) {
    $query_args = array_merge($args, [
      'gitup_msg' => (string) $message,
      'ok'        => (string) $ok,
    ]);
    wp_safe_redirect(gitup_get_settings_page_url($query_args));
    exit;
  }
}

if (!function_exists('gitup_clear_github_cache')) {
  function gitup_clear_github_cache() {
    global $wpdb;

    $patterns = [
      '_transient_github_release_%',
      '_transient_timeout_github_release_%',
      '_transient_github_releases_%',
      '_transient_timeout_github_releases_%',
      '_transient_github_repo_visibility_%',
      '_transient_timeout_github_repo_visibility_%',
    ];

    foreach ($patterns as $pattern) {
      $wpdb->query(
        $wpdb->prepare(
          "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
          $pattern
        )
      );
    }
  }
}

if (!function_exists('gitup_get_token_state')) {
  function gitup_get_token_state() {
    $token = get_option('gitup_github_token', '');
    if (empty($token)) {
      return 'missing';
    }

    $expires_at = (int) get_option('gitup_token_expires_at');
    if ($expires_at && $expires_at <= current_time('timestamp')) {
      return 'expired';
    }

    $status = get_option('gitup_token_status');
    $last_updated = (int) get_option('gitup_token_last_updated');
    $last_verified = (int) get_option('gitup_token_last_verified');

    if (is_array($status)) {
      $state = $status['status'] ?? '';
      $last_checked = (int) ($status['last_checked'] ?? 0);
      if ($state === 'invalid' && (!$last_updated || $last_checked >= $last_updated)) {
        return 'invalid';
      }
      if ($state === 'valid') {
        return 'valid';
      }
    }

    if ($last_verified && $last_verified >= (time() - 30 * DAY_IN_SECONDS)) {
      return 'valid';
    }

    return 'unknown';
  }
}
// Helper: Determine if a GitHub repo is public or private (cached).
if (!function_exists('gitup_repo_visibility')) {
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
  function gitup_repo_visibility($repo_url) {
    $repo_path = parse_url($repo_url, PHP_URL_PATH);
    if (!$repo_path) return 'unknown';
    $cache_key = 'github_repo_visibility_' . md5($repo_url);
    $cached = get_transient($cache_key);
    if ($cached !== false) return $cached;

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
      gitup_log('[repo_visibility] URL: ' . $api_url . ' code: ' . $code . ' body: ' . substr(wp_remote_retrieve_body($resp), 0, 200));
      if ($code === 200) {
        $body_json = json_decode(wp_remote_retrieve_body($resp), true);
        if (is_array($body_json) && array_key_exists('private', $body_json)) {
          $is_private = !empty($body_json['private']);
          set_transient($cache_key, $is_private ? 'private' : 'public', 30 * MINUTE_IN_SECONDS);
          return $is_private ? 'private' : 'public';
        } else {
          gitup_log('[repo_visibility] Unexpected 200 response body, marking as unknown');
          set_transient($cache_key, 'unknown', 15 * MINUTE_IN_SECONDS);
          return 'unknown';
        }
      }
      if ($code === 404 || $code === 401) {
        set_transient($cache_key, 'private', 30 * MINUTE_IN_SECONDS);
        return 'private';
      }
    }

    // 2) If rate limited or uncertain, retry with token if available
    $token = get_option('gitup_github_token', '');
    if (!empty($token)) {
      $headers['Authorization'] = 'Bearer ' . $token;
      $resp2 = wp_remote_get($api_url, [
        'headers'     => $headers,
        'timeout'     => 10,
        'redirection' => 2,
      ]);
      if (!is_wp_error($resp2)) {
        $code2 = (int) wp_remote_retrieve_response_code($resp2);
        gitup_log('[repo_visibility] URL: ' . $api_url . ' code: ' . $code2 . ' body: ' . substr(wp_remote_retrieve_body($resp2), 0, 200));
        if ($code2 === 200) {
          $body_json = json_decode(wp_remote_retrieve_body($resp2), true);
          if (is_array($body_json) && array_key_exists('private', $body_json)) {
            $is_private = !empty($body_json['private']);
            set_transient($cache_key, $is_private ? 'private' : 'public', 30 * MINUTE_IN_SECONDS);
            return $is_private ? 'private' : 'public';
          } else {
            gitup_log('[repo_visibility] Unexpected 200 response body, marking as unknown');
            set_transient($cache_key, 'unknown', 15 * MINUTE_IN_SECONDS);
            return 'unknown';
          }
        }
        if ($code2 === 404 || $code2 === 401) {
          set_transient($cache_key, 'private', 30 * MINUTE_IN_SECONDS);
          return 'private';
        }
      }
    }

    set_transient($cache_key, 'unknown', 15 * MINUTE_IN_SECONDS);
    return 'unknown';
  }
}

// Skapa en meny för plugininställningar
// OBS: sidans slug används i redirects/POST-handlers (page=gitup-settings)
add_action('admin_menu', function () {
  add_submenu_page(
    'tools.php',                                // parent: Tools
    __('GitUp – GitHub Updates', 'gitup'), // page_title
    __('GitUp', 'gitup'),                  // menu_title
    'update_core',                               // capability (alt: update_plugins)
    'gitup-settings',
    'gitup_settings_page'
);
});

// Ensure the submenu icon uses proper scaling for WP admin menu (20x20)
add_action('admin_head', function() {
  echo '<style>
    #tools_page_gitup-settings .wp-menu-image img {
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

    $all_plugins = get_plugins();
    $github_plugins = [];

    foreach ($all_plugins as $plugin_path => $plugin_info) {
      $update_uri = function_exists('gitup_get_plugin_repo_url') ? gitup_get_plugin_repo_url($plugin_info) : '';
      if ($update_uri !== '') {
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
      $update_uri = function_exists('gitup_get_theme_repo_url') ? gitup_get_theme_repo_url($theme) : '';
      if (!$update_uri) {
        continue;
      }
      // Debug-loggning av theme-namn och update_uri
      gitup_log('Theme: ' . $theme->get('Name') . ' UpdateURI: ' . $update_uri);
      $releases = gitup_fetch_releases($update_uri, false, 10);
      gitup_log('Found ' . count($releases) . ' releases for ' . $theme->get('Name'));

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
    return $github_themes;
  }
}
/**
 * Renderar adminsidan: tabeller för plugins och teman.
 * Innehåller en responsiv tabell med select över releasetaggar per rad.
 */
// Funktion för att visa plugin-information i adminpanelen
if (!function_exists("gitup_settings_page")) {
  function gitup_settings_page()
  {
    // Determine active tab
    $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'updates';
    // For refresh logic, only applies to updates tab
    $force_refresh = ($active_tab === 'updates' && isset($_GET['gitup_refresh']));

    // For nav tab links
    $base_url = admin_url('tools.php?page=gitup-settings');
    $updates_url = add_query_arg('tab', 'updates', $base_url);
    $install_url = add_query_arg('tab', 'install', $base_url);
    $settings_url = add_query_arg('tab', 'settings', $base_url);
    ?>
    <div class="wrap">
      <div class="gitup-header" >
        <h1 style="margin:0;">
          <img src="<?php echo esc_url(plugin_dir_url(__FILE__) . 'assets/images/icon.svg'); ?>" alt="GitUp" style="width:40px;height:40px;">
          <?php echo esc_html__('GitUp', 'gitup'); ?>
        </h1>
      </div>
      <?php settings_errors(); ?>
      <p><?php echo esc_html__('Manage GitHub-hosted plugin and theme updates for your WordPress site.', 'gitup'); ?></p>
      <h2 class="nav-tab-wrapper" style="margin-bottom:20px;">
        <a href="<?php echo esc_url($updates_url); ?>" class="nav-tab<?php if ($active_tab === 'updates') echo ' nav-tab-active'; ?>"><?php esc_html_e('Updates', 'gitup'); ?></a>
        <a href="<?php echo esc_url($install_url); ?>" class="nav-tab<?php if ($active_tab === 'install') echo ' nav-tab-active'; ?>"><?php esc_html_e('Install from URL', 'gitup'); ?></a>
        <a href="<?php echo esc_url($settings_url); ?>" class="nav-tab<?php if ($active_tab === 'settings') echo ' nav-tab-active'; ?>"><?php esc_html_e('Settings', 'gitup'); ?></a>
      </h2>
      <?php
      // === Render admin notices INSIDE tab containers, directly after nav tabs ===
      if ($active_tab === 'updates') :
        // Build a refresh URL that toggles gitup_refresh=1 and keeps tab=updates
        $refresh_url = add_query_arg(['tab' => 'updates', 'gitup_refresh' => '1'], $base_url);
        // Show force refresh notice (if any)
        if ($force_refresh) {
          echo '<div class="updated notice"><p>' . esc_html__('List refreshed from GitHub (cache bypassed).', 'gitup') . '</p></div>';
        }
        // Show admin notices for updates tab (from gitup_msg in URL)
        if (isset($_GET['gitup_msg'])) {
          $msg = sanitize_text_field(wp_unslash($_GET['gitup_msg']));
          $class = (isset($_GET['ok']) && $_GET['ok'] === '1') ? 'updated' : 'error';
          echo '<div class="' . esc_attr($class) . ' notice"><p>' . esc_html($msg) . '</p></div>';
        }
        $github_plugins = get_github_plugins($force_refresh);
      ?>
        <p>
          <a href="<?php echo esc_url($refresh_url); ?>" class="button"><?php echo esc_html__('Refresh list', 'gitup'); ?></a>
        </p>
        <h2 style="margin-top:28px;"><?php esc_html_e('Plugins', 'gitup'); ?></h2>
        <table class="widefat fixed striped gitup-table">
            <thead>
                <tr>
                    <th class="plugin" ><?php esc_html_e('Plugin', 'gitup'); ?></th>
                    <th class="version"><?php esc_html_e('Version', 'gitup'); ?></th>
                    <th class="release"><?php esc_html_e('Select release', 'gitup'); ?></th>
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
                      $include_pre = function_exists('gitup_include_prereleases_enabled') ? gitup_include_prereleases_enabled() : (get_option('gitup_include_prereleases', '0') === '1');
                      $releases = gitup_fetch_releases($plugin['github'], $include_pre, 20);
                      $row_class = '';
                      $default_action_state = null;
                      if (!empty($releases)) {
                        $default_action_state = gitup_get_release_action_state($releases[0]['tag'], $plugin['version']);
                        $row_class = $default_action_state['row_class'];
                      }
                      if ($odd === 'odd') { $odd = 'even'; } else { $odd = 'odd'; }
                    ?>
                    <?php if (!empty($releases) && !empty($default_action_state) && $default_action_state['comparison_result'] > 0): ?>
                      <tr class="has-update row <?php echo $odd . ' ' . esc_attr($row_class); ?>" data-currentVersion="<?php echo esc_html($plugin["version"]); ?>">
                    <?php else: ?>
                      <tr class="row <?php echo $odd . ' ' . esc_attr($row_class); ?>" data-currentVersion="<?php echo esc_html($plugin["version"]); ?>">
                    <?php endif; ?>
                        <td class="plugin" data-label="Plugin">
                          
                          <?php echo esc_html($plugin["name"]); ?><br>
                          <button type="button" class="gitup-toggle-details" aria-expanded="false" title="<?php echo esc_attr__('Show details', 'gitup'); ?>"><small><?php echo esc_attr__('Show details', 'gitup'); ?></small></button>
                        </td>
                        <td class="version" data-label="Version">
                          <?php echo esc_html($plugin["version"]); ?>
                          <?php
                            $visibility = gitup_repo_visibility($plugin['github']);
                          ?>
                          <?php if ($visibility === 'private'): ?>
                            (private)
                          <?php else: ?>
                            (public)
                          <?php endif; ?>
                        </td>
                        <td class="actions" data-label="Select release">
                          <?php
                            // New logic for plugin actions cell:
                            // 1. If $releases is empty:
                            //    - If $visibility === 'private':
                            //        - If no token or invalid token → show "Private repo / 404. Update token."
                            //        - Else → show "No releases found".
                            //    - Else (public) → show "No releases found".
                            // 2. If $releases is not empty: always render form.
                          $visibility = gitup_repo_visibility($plugin['github']);
                          $release_error = gitup_get_releases_error_code($releases);
                          if ($release_error === 'rate_limit' || empty($releases)) {
                            $message = gitup_get_release_empty_state_message($plugin['github'], $releases);
                            $style = ($release_error === 'rate_limit') ? 'opacity:.7;color:#d63638' : 'opacity:.7';
                            echo '<span style="' . esc_attr($style) . '">' . esc_html($message) . '</span>';
                          } else {
                              $action = admin_url('admin-post.php');
                              $plugin_file = $plugin['file'];
                              $action_state = $default_action_state ?: gitup_get_release_action_state($releases[0]['tag'], $plugin['version']);
                              // CSRF-skydd: unik nonce per pluginrad
                              $nonce = wp_create_nonce('gitup_install_release_' . $plugin_file);
                              echo '<form method="post" action="' . esc_url($action) . '" class="gitup-release-form" data-downgrade-confirm="' . esc_attr($action_state['confirm_message'] ?? __('Warning: downgrading may reintroduce bugs or incompatibilities. Make sure you know why you are installing an older release.', 'gitup')) . '">';
                              echo '<input type="hidden" name="action" value="gitup_install_release">';
                              echo '<input type="hidden" name="plugin" value="' . esc_attr($plugin_file) . '">';
                              echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($nonce) . '">';
                              echo '<select class="gitup-version-select" name="tag">';
                              foreach ($releases as $rel) {
                                $is_latest = ($rel['tag'] === $plugin['latest_release']);
                                $prefix = '';
                                // Markera senaste release om installerad version är äldre
                                if ($is_latest && (function_exists('gitup_compare_version_tags') ? gitup_compare_version_tags($plugin['version'], $plugin['latest_release']) : version_compare(gitup_normalize_version_tag($plugin['version']), gitup_normalize_version_tag($plugin['latest_release']))) < 0) {
                                  $prefix .= '⭐ ';
                                }
                                // Markera den release som är samma som installerad version
                                if ($rel['tag'] === $plugin['version']) $prefix .= '✓ ';
                                // Markera om release är äldre än installerad version
                                if ((function_exists('gitup_compare_version_tags') ? gitup_compare_version_tags($rel['tag'], $plugin['version']) : version_compare(gitup_normalize_version_tag($rel['tag']), gitup_normalize_version_tag($plugin['version']))) < 0) {
                                  $prefix .= '⬇ ';
                                }
                                $label = $prefix . $rel['tag'] . ($rel['prerelease'] ? ' (pre)' : '');
                                echo '<option value="' . esc_attr($rel['tag']) . '">' . esc_html($label) . '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</option>';
                              }
                              echo '</select>';
                              // POST: går till admin-post handler som kör WP Upgrader
                              $btn_class = 'button ' . $action_state['button_class'];
                              echo '<input type="submit" name="submit" class="' . esc_attr($btn_class) . '" value="' . esc_attr($action_state['button_label']) . '">';
                              echo '</form>';
                            }
                          ?>
                        </td>
                        
                    </tr>
                    <tr class="gitup-details">
                      <td class="summary">
                        <strong><?php esc_html_e('Author:', 'gitup'); ?></strong>
                        <?php echo esc_html($plugin['author']); ?><br>
                        <strong><?php esc_html_e('Repository:', 'gitup'); ?></strong>
                        <a href="<?php echo esc_url($plugin['github']); ?>" target="_blank"><?php echo esc_html($plugin['github']); ?></a><br>
                        <strong><?php esc_html_e('Latest release:', 'gitup'); ?></strong>
                        <?php if (!empty($releases[0]['tag'])): ?>
                          <?php echo esc_html($releases[0]['tag']); ?>
                          <?php if (!empty($releases[0]['url'])): ?>
                            — <a href="<?php echo esc_url($releases[0]['url']); ?>" target="_blank">
                              <?php echo esc_html__('View on GitHub', 'gitup'); ?>
                            </a>
                          <?php endif; ?>
                        <?php else: ?>
                          <?php esc_html_e('N/A', 'gitup'); ?>
                        <?php endif; ?>
                        <?php
                        if (!empty($releases[0]['published'])) {
                          $published_str = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($releases[0]['published']));
                          echo '<br><strong>' . esc_html__('Published:', 'gitup') . '</strong> ' . esc_html($published_str);
                        }
                        ?>
                      </td>
                      <td class="notes">
                        <?php
                          if (!empty($releases[0]['body'])) {
                            // Output raw body, preserving HTML entities, allowing markdown formatting and line breaks.
                            $raw_body = $releases[0]['body'];
                            echo wpautop(make_clickable(wp_kses_post($raw_body)));
                          } else {
                            echo '<span style="opacity:.7">' . esc_html__('No release notes', 'gitup') . '</span>';
                          }
                        ?>
                      </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <h2 style="margin-top:28px;"><?php esc_html_e('Themes', 'gitup'); ?></h2>
        <?php $github_themes = get_github_themes($force_refresh); ?>
        <?php $odd = 'odd'; ?>
        <table class="widefat fixed striped gitup-table">
            <thead>
                <tr>
                    <th class="theme" ><?php esc_html_e('Theme', 'gitup'); ?></th>
                    <th class="version" ><?php esc_html_e('Version', 'gitup'); ?></th>
                    <th class="release" ><?php esc_html_e('Select release', 'gitup'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($github_themes)): ?>
                    <tr><td colspan="4" style="text-align:center; opacity:.7; padding:16px;"><?php echo esc_html__('No themes with a GitHub Update URI were found.', 'gitup'); ?></td></tr>
                <?php else: ?>
                    <?php foreach ($github_themes as $theme): ?>
                        <?php
                        if ($odd === 'odd') { $odd = 'even'; } else { $odd = 'odd'; }
                        $repo_label = $theme["github"];
                        $repo_path  = parse_url($theme["github"], PHP_URL_PATH);
                        if ($repo_path) { $repo_label = ltrim($repo_path, '/'); }
                        // Beräkna $row_class baserat på $selected_tag och jämförelse mot $theme['version']
                        $include_pre = function_exists('gitup_include_prereleases_enabled') ? gitup_include_prereleases_enabled() : (get_option('gitup_include_prereleases', '0') === '1');
                        $releases = gitup_fetch_releases($theme['github'], $include_pre, 20);
                        $row_class = '';
                        $default_action_state = null;
                        if (!empty($releases)) {
                          $default_action_state = gitup_get_release_action_state($releases[0]['tag'], $theme['version']);
                          $row_class = $default_action_state['row_class'];
                        }
                        ?>
                        <?php if (!empty($releases) && !empty($default_action_state) && $default_action_state['comparison_result'] > 0): ?>
                          <tr class="has-update <?php echo $odd . ' ' . esc_attr($row_class); ?>" data-currentVersion="<?php echo esc_html($theme["version"]); ?>">
                        <?php else: ?>
                          <tr class="row <?php echo $odd . ' ' . esc_attr($row_class); ?>" data-currentVersion="<?php echo esc_html($theme["version"]); ?>">
                        <?php endif; ?>
                            <td class="plugin" data-label="Theme">
                              <?php echo esc_html($theme["name"]); ?><br>
                              <button type="button" class="gitup-toggle-details" aria-expanded="false" title="<?php echo esc_attr__('Show details', 'gitup'); ?>"><small><?php echo esc_attr__('Show details', 'gitup'); ?></small></button>
                            </td>
                            <td class="version" data-label="Version">
                              <?php echo esc_html($theme["version"]); ?>
                              <?php
                                $visibility = gitup_repo_visibility($theme['github']);
                              ?>
                              <?php if ($visibility === 'private'): ?>
                                (private)
                              <?php else: ?>
                                (public)
                              <?php endif; ?>
                            </td>
                            <td class="actions" data-label="Select release">
                              <?php
                                // New logic for theme actions cell:
                                // 1. If $releases is empty:
                                //    - If $visibility === 'private':
                                //        - If no token or invalid token → show "Private repo / 404. Update token."
                                //        - Else → show "No releases found".
                                //    - Else (public) → show "No releases found".
                                // 2. If $releases is not empty: always render form.
                                $visibility = gitup_repo_visibility($theme['github']);
                                $release_error = gitup_get_releases_error_code($releases);
                                if ($release_error === 'rate_limit' || empty($releases)) {
                                  $message = gitup_get_release_empty_state_message($theme['github'], $releases);
                                  $style = ($release_error === 'rate_limit') ? 'opacity:.7;color:#d63638' : 'opacity:.7';
                                  echo '<span style="' . esc_attr($style) . '">' . esc_html($message) . '</span>';
                                } else {
                                  $action = admin_url('admin-post.php');
                                  $theme_stylesheet = $theme['stylesheet'];
                                  $action_state = $default_action_state ?: gitup_get_release_action_state($releases[0]['tag'], $theme['version']);
                                  // CSRF-skydd: unik nonce per tema
                                  $nonce = wp_create_nonce('gitup_themes_install_release_' . $theme_stylesheet);
                                  echo '<form method="post" action="' . esc_url($action) . '" class="gitup-release-form" data-downgrade-confirm="' . esc_attr($action_state['confirm_message'] ?? __('Warning: downgrading may reintroduce bugs or incompatibilities. Make sure you know why you are installing an older release.', 'gitup')) . '">';
                                  echo '<input type="hidden" name="action" value="gitup_themes_install_release">';
                                  echo '<input type="hidden" name="theme" value="' . esc_attr($theme_stylesheet) . '">';
                                  echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($nonce) . '">';
                                  echo '<select class="gitup-version-select" name="tag">';
                                  foreach ($releases as $rel) {
                                    $is_latest = ($rel['tag'] === $theme['latest_release']);
                                    $prefix = '';
                                    if ($is_latest && (function_exists('gitup_compare_version_tags') ? gitup_compare_version_tags($theme['version'], $theme['latest_release']) : version_compare(gitup_normalize_version_tag($theme['version']), gitup_normalize_version_tag($theme['latest_release']))) < 0) {
                                      $prefix .= '⭐ ';
                                    }
                                    if ($rel['tag'] === $theme['version']) $prefix .= '✓ ';
                                    if ((function_exists('gitup_compare_version_tags') ? gitup_compare_version_tags($rel['tag'], $theme['version']) : version_compare(gitup_normalize_version_tag($rel['tag']), gitup_normalize_version_tag($theme['version']))) < 0) {
                                      $prefix .= '⬇ ';
                                    }
                                    $label = $prefix . $rel['tag'] . ($rel['prerelease'] ? ' (pre)' : '');
                                    echo '<option value="' . esc_attr($rel['tag']) . '">' . esc_html($label) . '</option>';
                                  }
                                  echo '</select>';
                                  echo '<br>';
                                  // POST: admin-post handler för teman (Theme_Upgrader)
                                  $btn_class = 'button ' . $action_state['button_class'];
                                  echo '<input type="submit" name="submit" class="' . esc_attr($btn_class) . '" value="' . esc_attr($action_state['button_label']) . '">';
                                  echo '</form>';
                                }
                              ?>
                            </td>

                        </tr>
                        <tr class="gitup-details">
                          <td class="summary">
                            <strong><?php esc_html_e('Author:', 'gitup'); ?></strong>
                            <?php echo esc_html($theme['author']); ?><br>
                            <strong><?php esc_html_e('Repository:', 'gitup'); ?></strong>
                            <a href="<?php echo esc_url($theme['github']); ?>" target="_blank"><?php echo esc_html($theme['github']); ?></a><br>
                            <strong><?php esc_html_e('Latest release:', 'gitup'); ?></strong>
                            <?php if (!empty($theme['releases'][0]['tag'])): ?>
                              <?php echo esc_html($theme['releases'][0]['tag']); ?>
                              <?php if (!empty($theme['releases'][0]['url'])): ?>
                                — <a href="<?php echo esc_url($theme['releases'][0]['url']); ?>" target="_blank">
                                  <?php echo esc_html__('View on GitHub', 'gitup'); ?>
                                </a>
                              <?php endif; ?>
                            <?php else: ?>
                              <?php esc_html_e('N/A', 'gitup'); ?>
                            <?php endif; ?>
                            <?php
                            if (!empty($theme['releases'][0]['published'])) {
                              $published_str = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($theme['releases'][0]['published']));
                              echo '<br><strong>' . esc_html__('Published:', 'gitup') . '</strong> ' . esc_html($published_str);
                            }
                            ?>
                          </td>
                          <td class="notes">
                            <?php
                              if (!empty($theme['releases'][0]['body'])) {
                                // Output raw body, preserving HTML entities, allowing markdown formatting and line breaks.
                                $raw_body = $theme['releases'][0]['body'];
                                echo wpautop(make_clickable(wp_kses_post($raw_body)));
                              } else {
                                echo '<span style="opacity:.7">' . esc_html__('No release notes', 'gitup') . '</span>';
                              }
                            ?>
                          </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
      <?php elseif ($active_tab === 'install') : ?>
        <?php
        // Show admin notices passed via redirect (?gitup_msg=...&ok=...) for the install tab.
        if (isset($_GET['gitup_msg'])) {
          $msg = sanitize_text_field(wp_unslash($_GET['gitup_msg']));
          $class = (isset($_GET['ok']) && $_GET['ok'] === '1') ? 'updated' : 'error';
          echo '<div class="' . esc_attr($class) . ' notice"><p>' . esc_html($msg) . '</p></div>';
        }
        if (function_exists('gitup_render_install_from_url_tab')) {
          gitup_render_install_from_url_tab();
        }
        ?>
      <?php elseif ($active_tab === 'settings') : ?>
        <?php $ajax_nonce = wp_create_nonce('gitup_test_github_token'); $ajax_url = admin_url('admin-ajax.php'); ?>
        <div class="rg-tools" style="margin-top:20px;">
          <h2 class="hndle"><span><?php esc_html_e('Tools', 'gitup'); ?></span></h2>
          <div class="inside">
            <div class="gitup-tools" style="display:flex; gap:12px; flex-wrap:wrap;">
              <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0;">
                <?php $nonce = wp_create_nonce('gitup_clear_cache'); ?>
                <input type="hidden" name="action" value="gitup_clear_cache">
                <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>">
                <button type="submit" class="button clear-cache"><?php echo esc_html__('Clear GitHub cache', 'gitup'); ?></button>
              </form>
              <div>
                <button type="button" id="gitup-test-ajax" class="button test-connection"><?php echo esc_html__('Test GitHub connection', 'gitup'); ?></button>
                <span id="gitup-test-ajax-status" style="margin-left:8px;"></span>
              </div>
            </div>
          </div>
        </div>
        <form method="post" action="options.php">
            <?php
            // Nonces + option group för denna sida
            // Lägg till nödvändiga fält för options-API (nonce, option group etc.)
            settings_fields('gitup_settings_group');
            // Rendera sektioner och fält som registrerats för denna sida
            do_settings_sections('gitup-settings');
            // Spara-knapp
            submit_button(__('Save settings', 'gitup'), 'primary', 'submit', false, array('class'=>'button button-primary save-settings'));
            ?>
        </form>
        <script>
        (function(){
          // Liten AJAX-testknapp för att verifiera token utan att lämna sidan
          var btn = document.getElementById('gitup-test-ajax');
          if(!btn) return;
          var statusEl = document.getElementById('gitup-test-ajax-status');
          var ajaxUrl = <?php echo json_encode($ajax_url); ?>;
          var nonce = <?php echo json_encode($ajax_nonce); ?>;
          btn.addEventListener('click', function(){
            btn.disabled = true;
            statusEl.textContent = '<?php echo esc_js(__('Testing…', 'gitup')); ?>';
            var formData = new FormData();
            formData.append('action', 'gitup_test_github_token');
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
                  statusEl.textContent = '<?php echo esc_js(__('Error: Could not parse server response.', 'gitup')); ?>';
                  console.error('AJAX test: JSON parse error', e, text);
                  return;
                }
                if (json.success) {
                  statusEl.textContent = json.data && json.data.message ? json.data.message : '<?php echo esc_js(__('Successful connection!', 'gitup')); ?>';
                  if (json.data && json.data.expires_at) {
                    const expiryDate = new Date(json.data.expires_at);
                    statusEl.innerHTML += '<br><small>' +
                      '<?php echo esc_js(__('Token expires at:', 'gitup')); ?> ' + expiryDate.toLocaleString() +
                      '</small>';
                  }
                } else {
                  statusEl.textContent = (json.data && json.data.message ? json.data.message : '<?php echo esc_js(__('Error during test.', 'gitup')); ?>') + (json.code ? ' (HTTP ' + json.code + ')' : '');
                }
              })
              .catch(function(err){
                statusEl.textContent = '<?php echo esc_js(__('Error:', 'gitup')); ?> ' + err;
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
  if (!current_user_can('manage_options')) {
    return;
  }
  if (empty($_GET['page']) || sanitize_key(wp_unslash($_GET['page'])) !== 'gitup-settings') {
    return;
  }
  if (isset($_GET['gitup_cache_cleared']) && $_GET['gitup_cache_cleared'] == '1') {
    echo '<div class="updated notice is-dismissible"><p>' . esc_html__('GitHub cache cleared.', 'gitup') . '</p></div>';
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
  if (function_exists('gitup_get_latest_github_release_tag')) {
    return gitup_get_latest_github_release_tag($repo_url, $force_refresh);
  }

  $include_prereleases = function_exists('gitup_include_prereleases_enabled')
    ? gitup_include_prereleases_enabled()
    : (get_option('gitup_include_prereleases', '0') === '1');
  $releases = gitup_fetch_releases($repo_url, $include_prereleases, 1);

  return !empty($releases[0]['tag']) ? $releases[0]['tag'] : 'N/A';
}

/**
 * Registrera inställningar/fields för denna sida.
 * - Token (password)
 * - Checkbox: tillåt förhandsreleaser (beta/rc)
 * (Beta-branch-fältet är kvarkommenterat men lämnat för framtida bruk.)
 */

add_action("admin_init", function () {
  register_setting("gitup_settings_group", "gitup_github_token");
  add_settings_section(
    "gitup_settings_section",
    "GitHub API settings",
    function () {
      // Short instruction: link to GitHub tokens page and mention repo scope for private repos
      echo '<p>Create a <a href="https://github.com/settings/tokens" target="_blank">personal access token</a> on GitHub with permission <code>repo</code> if you need access to private repositories.</p>';
    },
    "gitup-settings"
  );
  add_settings_field(
    "gitup_github_token",
    "GitHub Token",
    function () {
      $token = get_option("gitup_github_token", "");
      echo '<input type="password" name="gitup_github_token" value="' .
        esc_attr($token) .
        '" class="regular-text" autocomplete="off" placeholder="ghp_...">';

      // Statusrad: senast verifierad (via http_response 200) och senast uppdaterad (när option ändrades)
      $last_verified_ts = (int) get_option('gitup_token_last_verified');
      $last_updated_ts  = (int) get_option('gitup_token_last_updated');
      $now              = current_time('timestamp');

      if ($last_verified_ts) {
        $verified_when = date_i18n( get_option('date_format') . ' ' . get_option('time_format'), $last_verified_ts );
        echo '<p style="margin-top:6px;"><em>' . esc_html__('Last verified:', 'gitup') . ' ' . esc_html($verified_when) . '</em></p>';
      }

      if ($last_updated_ts) {
        $updated_when = date_i18n( get_option('date_format') . ' ' . get_option('time_format'), $last_updated_ts );
        $days_ago     = max(0, floor( ($now - $last_updated_ts) / DAY_IN_SECONDS ));
        $human        = human_time_diff($last_updated_ts, $now);
        echo '<p style="margin-top:2px;"><em>'
          . esc_html__('Token updated:', 'gitup') . ' '
          . esc_html( sprintf( _n('%s day ago', '%s days ago', $days_ago, 'gitup'), number_format_i18n($days_ago) ) )
          . ' (' . esc_html($human) . ') — ' . esc_html($updated_when)
          . '</em></p>';
      }

      // Show token expiry if available
      $expires_at_ts = get_option('gitup_token_expires_at');
      if (!empty($expires_at_ts) && is_numeric($expires_at_ts)) {
        $now = current_time('timestamp');
        $diff = $expires_at_ts - $now;
        if ($diff > 0) {
          $days_left = floor($diff / DAY_IN_SECONDS);
          $expiry_str = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $expires_at_ts);
          $expire_label = sprintf(__('Expires in %d days', 'gitup'), $days_left);
          if ($days_left < 7) {
            $expire_label = '<span style="color:#d63638">' . esc_html($expire_label) . '</span>';
          } else {
            $expire_label = esc_html($expire_label);
          }
          echo '<p style="margin-top:2px;"><em>' .
            esc_html__('Token expiry:', 'gitup') . ' ' . $expire_label .
            ' (' . esc_html($expiry_str) . ')' .
            '</em></p>';
        } else {
          $expiry_str = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $expires_at_ts);
          echo '<p style="margin-top:2px;"><em><span style="color:#d63638">' .
            esc_html__('Token expired', 'gitup') . ' (' . esc_html($expiry_str) . ')' .
            '</span></em></p>';
        }
      } else {
        echo '<p style="margin-top:2px;"><em>' .
          esc_html__('GitHub does not provide an expiry date for this token type.', 'gitup') .
          '</em></p>';
      }
    },
    "gitup-settings",
    "gitup_settings_section"
  );

  // Tillåt förhandsreleaser (beta/rc)
  register_setting('gitup_settings_group', 'gitup_include_prereleases');
  add_settings_field(
    'gitup_include_prereleases',
    __('Allow pre-releases', 'gitup'),
    function () {
      $val = get_option('gitup_include_prereleases', '0');
      echo '<label><input type="checkbox" name="gitup_include_prereleases" value="1" ' . checked('1', $val, false) . '> ' . esc_html__('Show and update to beta/rc releases', 'gitup') . '</label>';
    },
    'gitup-settings',
    'gitup_settings_section'
  );

  // Debug mode (enable/disable logging)
  register_setting('gitup_settings_group', 'gitup_debug_mode');
  add_settings_field(
    'gitup_debug_mode',
    __('Debug mode', 'gitup'),
    function () {
      $val = get_option('gitup_debug_mode', '1');
      echo '<label><input type="checkbox" name="gitup_debug_mode" value="1" ' . checked('1', $val, false) . '> ' . esc_html__('Enable logging for GitUp', 'gitup') . '</label>';
    },
    'gitup-settings',
    'gitup_settings_section'
  );
});

// Hantera test av GitHub-token när knappen trycks
add_action('admin_post_test_github_token', function () {
  if (!current_user_can('manage_options')) {
    wp_die(__('You do not have permission.', 'gitup'));
  }
  // Skydda POST: kräver korrekt options-API nonce
  check_admin_referer('gitup_settings_group-options');
  $token = get_option('gitup_github_token', '');
  // Minsta möjliga anrop mot /user för att verifiera token
  $args = [
    'headers' => [
      'User-Agent' => 'WordPress Plugin',
      'Authorization' => 'Bearer ' . $token,
    ]
  ];
  $response = wp_remote_get('https://api.github.com/user', $args);

  if (is_wp_error($response)) {
    add_settings_error('gitup_github_token', 'github_token_test', __('Connection error: ', 'gitup') . $response->get_error_message(), 'error');
  } else {
    $code = wp_remote_retrieve_response_code($response);
    if ($code === 200) {
      $body = json_decode(wp_remote_retrieve_body($response), true);
      // Save expires_at if present, otherwise do not update
      if (!empty($body['expires_at'])) {
        $expires_ts = strtotime($body['expires_at']);
        if ($expires_ts !== false) {
          update_option('gitup_token_expires_at', $expires_ts);
        }
      }
      add_settings_error('gitup_github_token', 'github_token_test', sprintf(__('Authenticated! Logged in as %s', 'gitup'), esc_html($body['login'] ?? 'unknown')), 'updated');
    } else {
      add_settings_error('gitup_github_token', 'github_token_test', sprintf(__('Authentication failed. HTTP status: %s', 'gitup'), $code), 'error');
      // On error, clear token expiry
      delete_option('gitup_token_expires_at');
    }
  }

  // Återvänd till sidan och visa resultat via settings_errors
  $referer = wp_get_referer();
  if (!$referer) {
    $referer = gitup_get_settings_page_url(['tab' => 'settings']);
  }
  wp_safe_redirect(add_query_arg('settings-updated', 'true', $referer));
  exit;
});

// AJAX: testa GitHub-token utan omladdning
add_action('wp_ajax_gitup_test_github_token', function(){
  if (!current_user_can('manage_options')) {
    wp_send_json_error(['message' => __('You do not have permission.', 'gitup')], 403);
  }
  // Skydda AJAX: kräver giltig nonce
  check_ajax_referer('gitup_test_github_token');
  $token = get_option('gitup_github_token', '');
  if (empty($token)) {
    wp_send_json_error(['message' => __('No token saved.', 'gitup')], 400);
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
    wp_send_json_error(['message' => __('Connection error: ', 'gitup') . $response->get_error_message()], 500);
  }
  $code = wp_remote_retrieve_response_code($response);
  if ($code === 200) {
    $body = json_decode(wp_remote_retrieve_body($response), true);
    // Save expires_at if present, otherwise do not update
    if (!empty($body['expires_at'])) {
      $expires_ts = strtotime($body['expires_at']);
      if ($expires_ts !== false) {
        update_option('gitup_token_expires_at', $expires_ts);
      }
    }
    $login = isset($body['login']) ? $body['login'] : 'unknown';
    wp_send_json_success([
      'message'    => sprintf(__('Authenticated! Logged in as %s', 'gitup'), esc_html($login)),
      'expires_at' => array_key_exists('expires_at', $body) ? $body['expires_at'] : null
    ]);
  }
  // On error, clear token expiry
  delete_option('gitup_token_expires_at');
  wp_send_json_error(['message' => sprintf(__('Authentication failed. HTTP status: %s', 'gitup'), $code)], $code);
});

// Rensa cache för GitHub-releaser när token uppdateras
add_action('update_option_gitup_github_token', function ($old, $new) {
  if ($old === $new) {
    return;
  }
  update_option('gitup_token_last_updated', time());
  gitup_clear_github_cache();
}, 10, 2);

// Admin-post handler: installera vald release-tag för ett plugin
add_action('admin_post_gitup_install_release', function () {
  if (!current_user_can('update_plugins')) {
    wp_die(__('You do not have permission to update plugins.', 'gitup'));
  }
  $plugin = isset($_POST['plugin']) ? sanitize_text_field(wp_unslash($_POST['plugin'])) : '';
  $tag    = isset($_POST['tag']) ? sanitize_text_field(wp_unslash($_POST['tag'])) : '';
  $nonce  = isset($_POST['_wpnonce']) ? sanitize_text_field(wp_unslash($_POST['_wpnonce'])) : '';
  if (!$plugin || !$tag || !wp_verify_nonce($nonce, 'gitup_install_release_' . $plugin)) {
    gitup_redirect_with_notice(__('Invalid request.', 'gitup'));
  }

  require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
  require_once ABSPATH . 'wp-admin/includes/plugin.php';

  $prepared = gitup_prepare_plugin_release_install($plugin, $tag);
  if (is_wp_error($prepared)) {
    gitup_redirect_with_notice($prepared->get_error_message());
  }
  $package = $prepared['package'];

  // Använd WordPress inbyggda upgrader-API (visar status i UI)
  $skin = new Automatic_Upgrader_Skin();
  $upgrader = new Plugin_Upgrader($skin);

  // Peka WordPress mot rätt destinationsmapp (utan tag i namnet)
  $package_options_filter = gitup_build_plugin_package_options_filter($plugin);
  add_filter('upgrader_package_options', $package_options_filter);

  $was_active = is_plugin_active($plugin);
  $result = $upgrader->install($package);
  remove_filter('upgrader_package_options', $package_options_filter);

  // Försök reaktivera om det var aktivt innan
  if ($result && !is_wp_error($result) && $was_active && !is_plugin_active($plugin)) {
    activate_plugin($plugin, '', false, true);
  }

  if ($result && !is_wp_error($result)) {
    $msg = __('Installation of selected release succeeded.', 'gitup');
    $ok  = '1';
  } else {
    $msg = is_wp_error($result) ? $result->get_error_message() : __('Installation failed.', 'gitup');
    $ok  = '0';
  }

  gitup_redirect_with_notice($msg, $ok);
});

// Admin-post handler: installera vald release-tag för ett tema
add_action('admin_post_gitup_themes_install_release', function () {
  if (!current_user_can('update_themes')) {
    wp_die(__('You do not have permission to update themes.', 'gitup'));
  }
  $theme_stylesheet = isset($_POST['theme']) ? sanitize_text_field(wp_unslash($_POST['theme'])) : '';
  $tag   = isset($_POST['tag']) ? sanitize_text_field(wp_unslash($_POST['tag'])) : '';
  $nonce = isset($_POST['_wpnonce']) ? sanitize_text_field(wp_unslash($_POST['_wpnonce'])) : '';
  if (!$theme_stylesheet || !$tag || !wp_verify_nonce($nonce, 'gitup_themes_install_release_' . $theme_stylesheet)) {
    gitup_redirect_with_notice(__('Invalid request (theme).', 'gitup'));
  }

  require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
  require_once ABSPATH . 'wp-admin/includes/theme.php';

  $prepared = gitup_prepare_theme_release_install($theme_stylesheet, $tag);
  if (is_wp_error($prepared)) {
    gitup_redirect_with_notice($prepared->get_error_message());
  }
  $theme = $prepared['theme'];
  $repo_url = $prepared['repo_url'];
  $package = $prepared['package'];
  $valid_tags = $prepared['valid_tags'];

  // WordPress Theme_Upgrader – hanterar unzip och filkopiering
  $skin = new Automatic_Upgrader_Skin();
  $upgrader = new Theme_Upgrader($skin);
  $active_stylesheet = get_stylesheet();
  $active_template = get_template();
  $touches_active_theme = ($active_stylesheet === $theme_stylesheet || $active_template === $theme_stylesheet);
  if (function_exists('gitup_log')) {
    gitup_log('manual theme install: stylesheet=' . $theme_stylesheet . ' active_stylesheet=' . $active_stylesheet . ' active_template=' . $active_template . ' touches_active=' . ($touches_active_theme ? 'yes' : 'no'));
    gitup_log('manual theme install: repo=' . $repo_url . ' tag=' . $tag . ' package=' . $package);
    gitup_log('manual theme install: valid_tags=' . json_encode($valid_tags));
  }

  // Sätt destination till temats mapp (utan tag i katalognamnet)
  $package_options_filter = gitup_build_theme_package_options_filter($theme_stylesheet);
  add_filter('upgrader_package_options', $package_options_filter);
  add_filter('upgrader_pre_install', [$upgrader, 'current_before'], 10, 2);
  add_filter('upgrader_post_install', [$upgrader, 'current_after'], 10, 2);
  add_filter('upgrader_clear_destination', [$upgrader, 'delete_old_theme'], 10, 4);
  add_filter('upgrader_source_selection', [$upgrader, 'check_package']);

  $upgrader->init();
  $upgrader->upgrade_strings();
  $upgrader->run([
    'package'           => $package,
    'destination'       => get_theme_root($theme_stylesheet),
    'clear_destination' => true,
    'clear_working'     => true,
    'hook_extra'        => [
      'theme'       => $theme_stylesheet,
      'type'        => 'theme',
      'action'      => 'update',
      'temp_backup' => [
        'slug' => $theme_stylesheet,
        'src'  => get_theme_root($theme_stylesheet),
        'dir'  => 'themes',
      ],
    ],
  ]);
  $result = $upgrader->result;

  remove_filter('upgrader_source_selection', [$upgrader, 'check_package']);
  remove_filter('upgrader_clear_destination', [$upgrader, 'delete_old_theme'], 10);
  remove_filter('upgrader_post_install', [$upgrader, 'current_after'], 10);
  remove_filter('upgrader_pre_install', [$upgrader, 'current_before'], 10);
  remove_filter('upgrader_package_options', $package_options_filter);
  if (function_exists('gitup_log')) {
    if (function_exists('gitup_log_upgrader_result')) {
      gitup_log_upgrader_result('manual theme install result', $result);
    } else {
      gitup_log('manual theme install result: ' . json_encode($result));
    }
  }

  if ($result && !is_wp_error($result)) {
    wp_clean_themes_cache();
    if (function_exists('gitup_log')) {
      gitup_log('manual theme install: themes cache cleaned for stylesheet=' . $theme_stylesheet);
    }

    if ($touches_active_theme) {
      $restored_theme = wp_get_theme($active_stylesheet);
      $restored_parent = $active_template ? wp_get_theme($active_template) : null;
      $active_theme_is_valid = $restored_theme->exists() && (!$restored_parent || !$active_template || $restored_parent->exists());
      if (function_exists('gitup_log')) {
        gitup_log('manual theme restore check: stylesheet_exists=' . ($restored_theme->exists() ? 'yes' : 'no') . ' parent_exists=' . (($restored_parent && $active_template) ? ($restored_parent->exists() ? 'yes' : 'no') : 'n/a'));
      }

      if ($active_theme_is_valid) {
        switch_theme($active_stylesheet, $active_template);
        if (function_exists('gitup_log')) {
          gitup_log('manual theme restore: switch_theme called with stylesheet=' . $active_stylesheet . ' template=' . $active_template);
        }
      } else {
        $result = new WP_Error('theme_reactivation_failed', __('Theme files were updated, but the active theme could not be restored cleanly.', 'gitup'));
        if (function_exists('gitup_log')) {
          gitup_log('manual theme restore: active theme could not be restored after install');
        }
      }
    }
  }

  // Om aktiva temat uppdaterades krävs ingen reaktivering; WP använder mappen.
  if ($result && !is_wp_error($result)) {
    $msg = __('Theme installed/updated.', 'gitup');
    $ok  = '1';
  } else {
    $msg = is_wp_error($result) ? $result->get_error_message() : __('Installation failed.', 'gitup');
    $ok  = '0';
  }

  gitup_redirect_with_notice($msg, $ok);
});


// Admin-post handler: rensa GitHub-release-cache
add_action('admin_post_gitup_clear_cache', function() {
  if (!current_user_can('manage_options')) {
    wp_die(__('You do not have permission.', 'gitup'));
  }
  check_admin_referer('gitup_clear_cache');
  gitup_clear_github_cache();
  // Redirect tillbaka till settings med notice
  $redirect_url = gitup_get_settings_page_url([
    'tab' => 'settings',
    'gitup_cache_cleared' => '1'
  ]);
  wp_safe_redirect($redirect_url);
  exit;
});
if (!function_exists('gitup_token_valid')) {
  /**
   * Checks if the GitHub token is usable based on saved validation state.
   *
   * @return bool
   */
  function gitup_token_valid() {
    return in_array(gitup_get_token_state(), ['valid', 'unknown'], true);
  }
}
