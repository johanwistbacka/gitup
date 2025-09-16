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
        // Hämta releases för detta repo
        $include_pre = get_option('rgplugins_include_prereleases', '0') === '1';
        $releases = rgplugins_fetch_releases($plugin_info['UpdateURI'], $include_pre, 50);

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
          // Hämta README från GitHub för den nya taggen
          $tag = $releases[0]['tag'];
          $readme_url = str_replace('github.com', 'raw.githubusercontent.com', $plugin_info['UpdateURI']);
          $readme_url = rtrim($readme_url, '/') . '/' . $tag . '/README.md';
          $readme_content = wp_remote_get($readme_url);
          if (!is_wp_error($readme_content) && wp_remote_retrieve_response_code($readme_content) === 200) {
            $raw_readme = wp_remote_retrieve_body($readme_content);
          }
        } else {
          // Hämta lokal README.md
          $plugin_dir = WP_PLUGIN_DIR . '/' . dirname($plugin_path);
          $local_readme = $plugin_dir . '/README.md';
          if (file_exists($local_readme)) {
            $raw_readme = file_get_contents($local_readme);
          }
        }
        if (!empty($raw_readme)) {
          $description .= '<h3>Readme</h3>';
          // Konvertera markdown till HTML om möjligt, annars visa rå text med wpautop
          if (class_exists('Parsedown')) {
            $Parsedown = new Parsedown();
            $description .= $Parsedown->text($raw_readme);
          } else {
            $description .= wpautop(esc_html($raw_readme));
          }
        }

        // Bygg changelog från upp till 10 senaste releaser
        $changelog = '';
        if (!empty($releases)) {
          usort($releases, function($a, $b) {
            return strtotime($b['date']) <=> strtotime($a['date']);
          });
          $count = 0;
          foreach ($releases as $release) {
            if ($count >= 10) {
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
  if (!empty($plugin_data['UpdateURI']) && strpos($plugin_data['UpdateURI'], 'github.com') !== false) {
    $slug = dirname($file);
    $update_data = get_site_transient('update_plugins');
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