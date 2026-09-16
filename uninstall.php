<?php
/**
 * Remove GitUp credentials and related settings when the plugin is uninstalled.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$gitup_options = [
    'gitup_github_token',
    'gitup_token_status',
    'gitup_token_last_verified',
    'gitup_token_last_updated',
    'gitup_token_expires_at',
    'gitup_include_prereleases',
    'gitup_debug_mode',
];

$gitup_delete_site_data = static function () use ($gitup_options) {
    foreach ($gitup_options as $gitup_option) {
        delete_option($gitup_option);
    }

    delete_transient('gitup_token_mail_sent');
};

if (function_exists('is_multisite') && is_multisite() && function_exists('get_sites')) {
    $gitup_site_ids = get_sites([
        'fields' => 'ids',
        'number' => 0,
    ]);

    foreach ($gitup_site_ids as $gitup_site_id) {
        switch_to_blog((int) $gitup_site_id);
        $gitup_delete_site_data();
        restore_current_blog();
    }
} else {
    $gitup_delete_site_data();
}
