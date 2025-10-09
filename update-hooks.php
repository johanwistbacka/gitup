<?php
/**
 * RG Git Updater – Update Core hooks
 *
 * Hanterar integration med WordPress update-core.php och plugin-information
 * (popup med "Visa uppgifter om version ...").
 */

if (!function_exists('rgplugins_plugins_api_handler')) {
  add_filter('plugins_api', function($res, $action, $args) {
    if ($action !== 'plugin_information' || empty($args->slug)) {
      return $res;
    }

    // Leta upp pluginet baserat på slug
    if (!function_exists('get_plugins')) {
      require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    $all_plugins = get_plugins();

    foreach ($all_plugins as $plugin_path => $plugin_info) {
      $slug = dirname($plugin_path);
      if ($slug === $args->slug && !empty($plugin_info['UpdateURI']) && strpos($plugin_info['UpdateURI'], 'github.com') !== false) {
        // Hämta releases för detta repo med transient cache
        $include_pre = get_option('rgplugins_include_prereleases', '0') === '1';
        $cache_key = 'rg_updater_info_' . md5($plugin_info['UpdateURI']);
        $releases = get_transient($cache_key);
        if ($releases === false) {
          $releases = rgplugins_fetch_releases($plugin_info['UpdateURI'], $include_pre, 50);
          set_transient($cache_key, $releases, 30 * MINUTE_IN_SECONDS);
        }

        // Ladda beskrivning: hämta README från GitHub endast om nyare version finns, annars lokalt
        $description = '<p>' . esc_html($plugin_info['Description'] ?? '') . '</p>';
        $raw_readme = '';
        $has_update = false;
        if (!empty($releases) && !empty($releases[0]['tag'])) {
          // Jämför versioner
          if (version_compare($releases[0]['tag'], $plugin_info['Version'], '>')) {
            $has_update = true;
          }
        }
        if ($has_update) {
          // Hämta README från GitHub för den nya taggen med transient cache
          $tag = $releases[0]['tag'];
          $readme_url = str_replace('github.com', 'raw.githubusercontent.com', $plugin_info['UpdateURI']);
          $readme_url = rtrim($readme_url, '/') . '/' . $tag . '/README.md';
          $readme_cache_key = 'rg_updater_readme_' . md5($plugin_info['UpdateURI'] . $tag);
          $cached_readme = get_transient($readme_cache_key);
          if ($cached_readme === false) {
            $readme_content = wp_remote_get($readme_url, ['timeout' => 8, 'redirection' => 3]);
            if (!is_wp_error($readme_content) && wp_remote_retrieve_response_code($readme_content) === 200) {
              $cached_readme = wp_remote_retrieve_body($readme_content);
              set_transient($readme_cache_key, $cached_readme, 12 * HOUR_IN_SECONDS);
            }
          }
          $raw_readme = $cached_readme;
        } else {
          // Hämta lokal README.md med transient cache
          $plugin_dir = WP_PLUGIN_DIR . '/' . dirname($plugin_path);
          $local_cache_key = 'rg_updater_local_readme_' . md5($plugin_dir);
          $raw_readme = get_transient($local_cache_key);
          if ($raw_readme === false && file_exists($plugin_dir . '/README.md')) {
            $raw_readme = file_get_contents($plugin_dir . '/README.md');
            set_transient($local_cache_key, $raw_readme, HOUR_IN_SECONDS);
          }
        }
        if (!empty($raw_readme)) {
          $description .= '<h3>Readme</h3>';
          // Konvertera markdown till HTML om möjligt, annars visa rå text med wpautop
          static $Parsedown = null;
          if ($Parsedown === null && class_exists('Parsedown')) {
            $Parsedown = new Parsedown();
          }
          if ($Parsedown) {
            $description .= $Parsedown->text($raw_readme);
          } else {
            $description .= wpautop(esc_html($raw_readme));
          }
        }

        // Bygg changelog från upp till 3 senaste releaser
        $changelog = '';
        if (!empty($releases)) {
          usort($releases, function($a, $b) {
            return strtotime($b['date']) <=> strtotime($a['date']);
          });
          $count = 0;
          foreach ($releases as $release) {
            if ($count >= 3) {
              break;
            }
            $tag = esc_html($release['tag'] ?? '');
            $date = !empty($release['date']) ? date_i18n(get_option('date_format'), strtotime($release['date'])) : '';
            $body = !empty($release['body'])
                ? '<div class="rg-changelog-body">' . nl2br(make_clickable(wp_kses_post($release['body']))) . '</div>'
                : '<p><em>' . __('No details provided for this release.', 'rg-git-updater') . '</em></p>';
            $changelog .= '<h4>' . $tag . ' - ' . $date . '</h4>' . $body;
            $count++;
          }
          $repo_releases_url = rtrim($plugin_info['UpdateURI'], '/') . '/releases';
          $changelog .= '<p><a href="' . esc_url($repo_releases_url) . '" target="_blank" rel="noopener noreferrer">' . __('View all releases on GitHub', 'rg-git-updater') . '</a></p>';
        } else {
          $changelog = '<p>' . __('No changelog provided.', 'rg-git-updater') . '</p>';
        }

        return (object)[
          'name'        => $plugin_info['Name'],
          'slug'        => $args->slug,
          'version'     => $releases[0]['tag'] ?? $plugin_info['Version'],
          'author'      => $plugin_info['Author'],
          'homepage'    => $plugin_info['PluginURI'] ?: $plugin_info['UpdateURI'],
          'sections'    => [
            'description' => $description,
            'changelog'   => $changelog,
          ],
        ];
      }
    }

    return $res;
  }, 10, 3);
}

// Add "View details of version ..." link on plugins.php
add_filter('plugin_row_meta', function($links, $file, $plugin_data) {
  if (empty($plugin_data['UpdateURI']) || strpos($plugin_data['UpdateURI'], 'github.com') === false) {
    return $links;
  }
  static $plugin_update_data = null;
  if ($plugin_update_data === null) {
      $plugin_update_data = get_site_transient('update_plugins');
  }
  $update_data = $plugin_update_data;
  if (!empty($plugin_data['UpdateURI']) && strpos($plugin_data['UpdateURI'], 'github.com') !== false) {
    $slug = dirname($file);
    if (empty($update_data->response[$file])) {
      $new_version = $plugin_data['Version'] ?? '';
      if (!empty($new_version)) {
        $info_url = self_admin_url("plugin-install.php?tab=plugin-information&plugin={$slug}&TB_iframe=true&width=600&height=550");
        $links[] = sprintf(
          '<a href="%s" class="thickbox open-plugin-details-modal">%s</a>',
          esc_url($info_url),
          sprintf(__('View details of version %s', 'rg-git-updater'), esc_html($new_version))
        );
      }
    }
  }
  return $links;
}, 10, 3);

// Add themes_api filter for GitHub themes
add_filter('themes_api', function($res, $action, $args) {
  if ($action !== 'theme_information' || empty($args->slug)) {
    return $res;
  }

  // Get all themes
  if (!function_exists('wp_get_themes')) {
    require_once ABSPATH . 'wp-includes/theme.php';
  }
  $all_themes = wp_get_themes();

  foreach ($all_themes as $theme_slug => $theme_obj) {
    // The slug may be the directory name
    if ($theme_slug === $args->slug) {
      $update_uri = $theme_obj->get('UpdateURI');
      if (!empty($update_uri) && strpos($update_uri, 'github.com') !== false) {
        // Fetch releases for this repo with transient cache
        $include_pre = get_option('rgplugins_include_prereleases', '0') === '1';
        $cache_key = 'rg_updater_info_' . md5($update_uri);
        $releases = get_transient($cache_key);
        if ($releases === false) {
          $releases = rgplugins_fetch_releases($update_uri, $include_pre, 50);
          set_transient($cache_key, $releases, 30 * MINUTE_IN_SECONDS);
        }

        // Load description: fetch README from GitHub only if newer version exists, else local
        $description = '<p>' . esc_html($theme_obj->get('Description')) . '</p>';
        $raw_readme = '';
        $has_update = false;
        if (!empty($releases) && !empty($releases[0]['tag'])) {
          if (version_compare($releases[0]['tag'], $theme_obj->get('Version'), '>')) {
            $has_update = true;
          }
        }
        if ($has_update) {
          $tag = $releases[0]['tag'];
          $readme_url = str_replace('github.com', 'raw.githubusercontent.com', $update_uri);
          $readme_url = rtrim($readme_url, '/') . '/' . $tag . '/README.md';
          $readme_cache_key = 'rg_updater_readme_' . md5($update_uri . $tag);
          $cached_readme = get_transient($readme_cache_key);
          if ($cached_readme === false) {
            $readme_content = wp_remote_get($readme_url, ['timeout' => 8, 'redirection' => 3]);
            if (!is_wp_error($readme_content) && wp_remote_retrieve_response_code($readme_content) === 200) {
              $cached_readme = wp_remote_retrieve_body($readme_content);
              set_transient($readme_cache_key, $cached_readme, 12 * HOUR_IN_SECONDS);
            }
          }
          $raw_readme = $cached_readme;
        } else {
          // Try to load local README.md with transient cache
          $theme_dir = $theme_obj->get_stylesheet_directory();
          $local_cache_key = 'rg_updater_local_readme_' . md5($theme_dir);
          $raw_readme = get_transient($local_cache_key);
          if ($raw_readme === false && file_exists($theme_dir . '/README.md')) {
            $raw_readme = file_get_contents($theme_dir . '/README.md');
            set_transient($local_cache_key, $raw_readme, HOUR_IN_SECONDS);
          }
        }
        if (!empty($raw_readme)) {
          $description .= '<h3>Readme</h3>';
          static $Parsedown = null;
          if ($Parsedown === null && class_exists('Parsedown')) {
            $Parsedown = new Parsedown();
          }
          if ($Parsedown) {
            $description .= $Parsedown->text($raw_readme);
          } else {
            $description .= wpautop(esc_html($raw_readme));
          }
        }

        // Build changelog from up to 3 latest releases
        $changelog = '';
        if (!empty($releases)) {
          usort($releases, function($a, $b) {
            return strtotime($b['date']) <=> strtotime($a['date']);
          });
          $count = 0;
          foreach ($releases as $release) {
            if ($count >= 3) {
              break;
            }
            $tag = esc_html($release['tag'] ?? '');
            $date = !empty($release['date']) ? date_i18n(get_option('date_format'), strtotime($release['date'])) : '';
            $body = !empty($release['body'])
                ? '<div class="rg-changelog-body">' . nl2br(make_clickable(wp_kses_post($release['body']))) . '</div>'
                : '<p><em>' . __('No details provided for this release.', 'rg-git-updater') . '</em></p>';
            $changelog .= '<h4>' . $tag . ' - ' . $date . '</h4>' . $body;
            $count++;
          }
          $repo_releases_url = rtrim($update_uri, '/') . '/releases';
          $changelog .= '<p><a href="' . esc_url($repo_releases_url) . '" target="_blank" rel="noopener noreferrer">' . __('View all releases on GitHub', 'rg-git-updater') . '</a></p>';
        } else {
          $changelog = '<p>' . __('No changelog provided.', 'rg-git-updater') . '</p>';
        }

        return (object)[
          'name'        => $theme_obj->get('Name'),
          'slug'        => $args->slug,
          'version'     => $releases[0]['tag'] ?? $theme_obj->get('Version'),
          'author'      => $theme_obj->get('Author'),
          'homepage'    => $theme_obj->get('ThemeURI') ?: $update_uri,
          'sections'    => [
            'description' => $description,
            'changelog'   => $changelog,
          ],
        ];
      }
    }
  }
  return $res;
}, 10, 3);

// Add "View details of version ..." link on themes.php for GitHub themes
add_filter('theme_row_meta', function($links, $theme_slug, $theme) {
  $update_uri = $theme->get('UpdateURI');
  if (empty($update_uri) || strpos($update_uri, 'github.com') === false) {
      return $links;
  }
  static $theme_update_data = null;
  if ($theme_update_data === null) {
      $theme_update_data = get_site_transient('update_themes');
  }
  $update_data = $theme_update_data;
  if (!empty($update_uri) && strpos($update_uri, 'github.com') !== false) {
    // Check if update is available
    $stylesheet = $theme->get_stylesheet();
    $has_update = !empty($update_data->response[$stylesheet]);
    if (!$has_update) {
      $new_version = $theme->get('Version');
      if (!empty($new_version)) {
        $info_url = self_admin_url("theme-install.php?tab=theme-information&theme={$theme_slug}&TB_iframe=true&width=600&height=550");
        $links[] = sprintf(
          '<a href="%s" class="thickbox open-plugin-details-modal">%s</a>',
          esc_url($info_url),
          sprintf(__('View details of version %s', 'rg-git-updater'), esc_html($new_version))
        );
      }
    }
  }
  return $links;
}, 10, 3);
// --- Nonce variables for use in options.php table rendering ---
// These will be used when rendering the admin table for plugins/themes.
add_action('load-toplevel_page_rg-git-updater', function() {
    // Only initialize on our admin page.
    global $nonce_plugin, $nonce_theme;
    $nonce_plugin = wp_create_nonce('rgplugins_install_release_');
    $nonce_theme  = wp_create_nonce('rgthemes_install_release_');
});