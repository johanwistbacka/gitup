<?php
defined('ABSPATH') || exit;
/*
Plugin Name: Rätt Grafiska Git Updater
Description: Handles automatic updates for Ratt Grafiska's plugins via GitHub.
Version: 2025.09.18.01
Author: Ratt Grafiska
Plugin URI: https://github.com/Ratt-Grafiska/rg-git-updater
Update URI: https://github.com/Ratt-Grafiska/rg-git-updater
Text Domain: rg-git-updater
Domain Path: /languages
*/
// Kontrollera om huvudklassen redan är laddad (skydd mot dubbel-inkludering)
if (!class_exists("RgGitUpdaterClass")) {

    // Ladda översättningar
    add_action('plugins_loaded', function () {
        static $loaded = false;
        if ($loaded) return;
        load_plugin_textdomain('rg-git-updater', false, dirname(plugin_basename(__FILE__)) . '/languages');
        $loaded = true;
    });

    // Ladda själva uppdateringsmotorn och options-sidan
require_once plugin_dir_path(__FILE__) . "rg-git-updater.php";
require_once plugin_dir_path(__FILE__) . "options.php";
require_once plugin_dir_path(__FILE__) . "site-health.php";
require_once plugin_dir_path(__FILE__) . 'update-hooks.php';
// Enqueue plugin CSS and JS only on the settings page
add_action('admin_enqueue_scripts', function($hook) {
    if ($hook !== 'tools_page_rgplugins-settings') return;
    $version = '2025.09.18.01';
    wp_enqueue_style(
        'rg-gitup-css',
        plugin_dir_url(__FILE__) . 'assets/css/gitup.css',
        [],
        $version
    );
    wp_enqueue_script(
        'rg-gitup-js',
        plugin_dir_url(__FILE__) . 'assets/js/gitup.js',
        ['jquery'],
        $version,
        true
    );
});

}
add_filter('plugin_row_meta', function($links, $file) {
    if ($file === plugin_basename(__FILE__)) {
        $icon_url = plugin_dir_url(__FILE__) . 'assets/images/icon.png';
        array_unshift($links, '<img src="' . esc_url($icon_url) . '" style="width:20px;height:20px;vertical-align:middle;margin-right:4px;">');
        $links[] = '<a href="' . esc_url(admin_url('tools.php?page=rgplugins-settings')) . '">' . __('Settings', 'rg-git-updater') . '</a>';
    }
    return $links;
}, 10, 2);
// Lägg till "Settings"-länk på pluginsidan
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function($links) {
    $settings_link = '<a href="' . esc_url(admin_url('tools.php?page=rgplugins-settings')) . '">' . __('Settings', 'rg-git-updater') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
});
