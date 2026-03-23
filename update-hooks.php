<?php
/**
 * GitUp – Update Core row meta links
 *
 * Själva update/info-flödet hanteras i huvudklassen. Den här filen kompletterar
 * bara med "View details"-länkar på plugin- och temasidorna.
 */

// Add "View details of version ..." link on plugins.php
add_filter('plugin_row_meta', function($links, $file, $plugin_data) {
  $repo_url = function_exists('gitup_get_plugin_repo_url') ? gitup_get_plugin_repo_url($plugin_data) : '';
  if ($repo_url !== '') {
    $slug = dirname($file);
    $update_data = get_site_transient('update_plugins');
    if (empty($update_data->response[$file])) {
      $new_version = $plugin_data['Version'] ?? '';
      if (!empty($new_version)) {
        $info_url = self_admin_url("plugin-install.php?tab=plugin-information&plugin={$slug}&TB_iframe=true&width=600&height=550");
        $links[] = sprintf(
          '<a href="%s" class="thickbox open-plugin-details-modal">%s</a>',
          esc_url($info_url),
          sprintf(__('View details of version %s', 'gitup'), esc_html($new_version))
        );
      }
    }
  }
  return $links;
}, 10, 3);

// Add "View details of version ..." link on themes.php for GitHub themes
add_filter('theme_row_meta', function($links, $theme_slug, $theme) {
  $repo_url = function_exists('gitup_get_theme_repo_url') ? gitup_get_theme_repo_url($theme) : '';
  if ($repo_url !== '') {
    // Check if update is available
    $update_data = get_site_transient('update_themes');
    $stylesheet = $theme->get_stylesheet();
    $has_update = !empty($update_data->response[$stylesheet]);
    if (!$has_update) {
      $new_version = $theme->get('Version');
      if (!empty($new_version)) {
        $info_url = self_admin_url("theme-install.php?tab=theme-information&theme={$theme_slug}&TB_iframe=true&width=600&height=550");
        $links[] = sprintf(
          '<a href="%s" class="thickbox open-plugin-details-modal">%s</a>',
          esc_url($info_url),
          sprintf(__('View details of version %s', 'gitup'), esc_html($new_version))
        );
      }
    }
  }
  return $links;
}, 10, 3);
