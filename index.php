<?php
/*
Plugin Name: Rätt Grafiska Git Updater
Description: Handles automatic updates for Ratt Grafiska's plugins via GitHub.
Version: 2025.09.15.02-beta
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
add_filter('plugin_row_meta', function ($links, $file) {
    if ($file === plugin_basename(__FILE__)) {
        $icon_url = plugin_dir_url(__FILE__) . 'assets/icon.png';
        array_unshift($links, '<img src="' . esc_url($icon_url) . '" style="width:20px;height:20px;vertical-align:middle;margin-right:4px;">');
    }
    return $links;
}, 10, 2);
// Lägg till "Settings"-länk på pluginsidan
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function($links) {
    $settings_link = '<a href="' . esc_url(admin_url('tools.php?page=rgplugins-settings')) . '">' . __('Settings', 'rg-git-updater') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
});

// Lägg till "Settings"-länk i plugin_row_meta (under beskrivningen)
add_filter('plugin_row_meta', function($links, $file) {
    if ($file === plugin_basename(__FILE__)) {
        $links[] = '<a href="' . esc_url(admin_url('tools.php?page=rgplugins-settings')) . '">' . __('Settings', 'rg-git-updater') . '</a>';
    }
    return $links;
}, 10, 2);

// Site Health integration: token status
add_filter('site_status_tests', function ($tests) {
    $tests['direct']['rg_git_updater_token'] = [
        'label' => __('GitHub Token Status', 'rg-git-updater'),
        'test'  => function () {
            $last_verified = intval(get_option('rgplugins_token_last_verified', 0));
            $token        = get_option('rgplugins_github_token');

            if (empty($token)) {
                return [
                    'label'       => __('No GitHub token set', 'rg-git-updater'),
                    'status'      => 'recommended',
                    'badge'       => [
                        'label' => __('RG Git Updater', 'rg-git-updater'),
                        'color' => 'blue',
                    ],
                    'description' => __('Public repositories will work, but private repositories require a valid token.', 'rg-git-updater'),
                    'actions'     => sprintf('<a href="%s">%s</a>', esc_url(admin_url('tools.php?page=rgplugins-settings')), __('Go to settings', 'rg-git-updater')),
                    'test'        => 'rg_git_updater_token',
                ];
            }

            if (!$last_verified || $last_verified < (time() - 30 * DAY_IN_SECONDS)) {
                return [
                    'label'       => __('GitHub token has not been verified recently', 'rg-git-updater'),
                    'status'      => 'recommended',
                    'badge'       => [
                        'label' => __('RG Git Updater', 'rg-git-updater'),
                        'color' => 'orange',
                    ],
                    'description' => __('Your token has not been verified in the last 30 days. Visit settings to re-test.', 'rg-git-updater'),
                    'actions'     => sprintf('<a href="%s">%s</a>', esc_url(admin_url('tools.php?page=rgplugins-settings')), __('Re-test token', 'rg-git-updater')),
                    'test'        => 'rg_git_updater_token',
                ];
            }

            return [
                'label'       => __('GitHub token is valid', 'rg-git-updater'),
                'status'      => 'good',
                'badge'       => [
                    'label' => __('RG Git Updater', 'rg-git-updater'),
                    'color' => 'green',
                ],
                'description' => sprintf(
                    __('Token last verified on %s.', 'rg-git-updater'),
                    date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $last_verified)
                ),
                'test'        => 'rg_git_updater_token',
            ];
        }
    ];
    return $tests;
});