<?php
/*
Plugin Name: GitUp
Description: Handles automatic updates for GitHub-hosted plugins and themes.
Version: 2026.06.22.01-beta
Author: Johan Wistbacka
Plugin URI: https://github.com/johanwistbacka/gitup
Update URI: https://github.com/johanwistbacka/gitup
Text Domain: gitup
Domain Path: /languages
*/
// Kontrollera om huvudklassen redan är laddad (skydd mot dubbel-inkludering)
if (!class_exists("GitUpUpdater")) {
    // Ladda översättningar
    add_action('plugins_loaded', function () {
        load_plugin_textdomain('gitup', false, dirname(plugin_basename(__FILE__)) . '/languages');
        // Fallback: explicitly load locale-specific .mo if default loader misses
        if (function_exists('get_locale')) {
            $locale = function_exists('determine_locale') ? determine_locale() : get_locale();
            $base   = plugin_dir_path(__FILE__) . 'languages/';
            $mofile = $base . 'gitup-' . $locale . '.mo';
            if (file_exists($mofile)) {
                load_textdomain('gitup', $mofile);
            } else {
                // Some tools output only sv_SE.mo without the text domain prefix
                $fallback = $base . $locale . '.mo';
                if (file_exists($fallback)) {
                    load_textdomain('gitup', $fallback);
                }
            }
        }
    });

    // Ladda själva uppdateringsmotorn och options-sidan
require_once plugin_dir_path(__FILE__) . "gitup-updater.php";
require_once plugin_dir_path(__FILE__) . "options.php";
require_once plugin_dir_path(__FILE__) . "site-health.php";
require_once plugin_dir_path(__FILE__) . 'update-hooks.php';
require_once plugin_dir_path(__FILE__) . 'install-from-url.php';
require_once plugin_dir_path(__FILE__) . 'release-prefetch.php';
// Enqueue plugin CSS and JS only on the settings page
add_action('admin_enqueue_scripts', function($hook) {
    $screen = get_current_screen();
    if ($screen && $screen->id === 'tools_page_gitup-settings') {
        wp_enqueue_style(
            'gitup-css',
            plugin_dir_url(__FILE__) . 'assets/css/gitup.css',
            [],
            filemtime(plugin_dir_path(__FILE__) . 'assets/css/gitup.css')
        );
        wp_enqueue_script(
            'gitup-js',
            plugin_dir_url(__FILE__) . 'assets/js/gitup.js',
            ['jquery'],
            filemtime(plugin_dir_path(__FILE__) . 'assets/js/gitup.js'),
            true
        );
    }
});

}
add_filter('plugin_row_meta', function ($links, $file) {
    if ($file === plugin_basename(__FILE__)) {
        $icon_url = plugin_dir_url(__FILE__) . 'assets/images/icon.png';
        array_unshift($links, '<img src="' . esc_url($icon_url) . '" style="width:20px;height:20px;vertical-align:middle;margin-right:4px;">');
    }
    return $links;
}, 10, 2);
// Lägg till "Settings"-länk på pluginsidan
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function($links) {
    $settings_link = '<a href="' . esc_url(admin_url('tools.php?page=gitup-settings')) . '">' . __('Settings', 'gitup') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
});

// Lägg till "Settings"-länk i plugin_row_meta (under beskrivningen)
add_filter('plugin_row_meta', function($links, $file) {
    if ($file === plugin_basename(__FILE__)) {
        $links[] = '<a href="' . esc_url(admin_url('tools.php?page=gitup-settings')) . '">' . __('Settings', 'gitup') . '</a>';
    }
    return $links;
}, 10, 2);
