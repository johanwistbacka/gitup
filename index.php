<?php
/*
Plugin Name: Rätt Grafiska Git Updater
Description: Handles automatic updates for Ratt Grafiska's plugins via GitHub.
Version: 2025.09.12.04-beta
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
        load_plugin_textdomain('rg-git-updater', false, dirname(plugin_basename(__FILE__)) . '/languages');
        // Fallback: explicitly load locale-specific .mo if default loader misses
        if (function_exists('get_locale')) {
            $locale = function_exists('determine_locale') ? determine_locale() : get_locale();
            $base   = plugin_dir_path(__FILE__) . 'languages/';
            $mofile = $base . 'rg-git-updater-' . $locale . '.mo';
            if (file_exists($mofile)) {
                load_textdomain('rg-git-updater', $mofile);
            } else {
                // Some tools output only sv_SE.mo without the text domain prefix
                $fallback = $base . $locale . '.mo';
                if (file_exists($fallback)) {
                    load_textdomain('rg-git-updater', $fallback);
                }
            }
        }
    });

    // Ladda själva uppdateringsmotorn och options-sidan
    require_once plugin_dir_path(__FILE__) . "rg-git-updater.php";
    require_once plugin_dir_path(__FILE__) . "options.php";

}