<?php 
/**
 * Site Health: RG Git Updater status test
 */

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
add_filter('site_status_tests', function($tests) {
    $tests['direct']['rg_git_updater'] = array(
        'label'    => __('RG Git Updater status', 'rg-git-updater'),
        'test'     => 'rg_git_updater_health_test',
    );
    return $tests;
});

/**
 * Callback for Site Health test: RG Git Updater
 *
 * @return array
 */
function rg_git_updater_health_test() {
    // Log that the test was run
    if (function_exists('rg_updater_log')) {
        rg_updater_log('Site Health test: rg_git_updater_health_test called');
    }

    $token = get_option('rgplugins_github_token', '');
    $last_verified = (int)get_option('rgplugins_token_last_verified');
    $last_updated  = (int)get_option('rgplugins_token_last_updated');
    $now = current_time('timestamp');
    $token_status = '';
    $token_ok = false;
    $token_days_ago = null;
    if (!empty($token)) {
        if ($last_verified) {
            $token_days_ago = floor(($now - $last_verified) / DAY_IN_SECONDS);
            $token_status = sprintf(
                __('GitHub token verified %s ago', 'rg-git-updater'),
                human_time_diff($last_verified, $now)
            );
            $token_ok = ($now - $last_verified < 14 * DAY_IN_SECONDS);
        } else {
            $token_status = __('GitHub token saved, not verified', 'rg-git-updater');
        }
    } else {
        $token_status = __('No GitHub token saved', 'rg-git-updater');
    }

    // Try to get release cache for at least one plugin or theme with GitHub UpdateURI
    $release_info = '';
    $release_status = 'N/A';
    $release_date = null;
    $release_label = '';
    $github_plugins = function_exists('get_github_plugins') ? get_github_plugins(false) : array();
    $github_themes  = function_exists('get_github_themes') ? get_github_themes(false) : array();
    $item = null;
    if (!empty($github_plugins)) {
        $item = $github_plugins[0];
    } elseif (!empty($github_themes)) {
        $item = $github_themes[0];
    }
    if ($item && !empty($item['github'])) {
        // Find the transient key for this repo
        $repo_url = $item['github'];
        $include_pre = get_option('rgplugins_include_prereleases', '0') === '1';
        $cache_key = 'github_release_' . md5($repo_url . '|' . ($include_pre ? 'pre' : 'stable'));
        $transient = get_transient($cache_key);
        $release_label = $item['name'] . ' (' . $repo_url . ')';
        if ($transient && $transient !== 'N/A') {
            $release_status = $transient;
            // Try to get transient timeout (for when it was set)
            global $wpdb;
            $timeout_val = $wpdb->get_var( $wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
                '_transient_timeout_' . $cache_key
            ));
            if ($timeout_val) {
                // WP stores it as a timestamp for expiration; subtract 1h or 5min to estimate set time
                $timeout = (int)$timeout_val;
                $lifespan = (is_string($transient) && $transient !== 'N/A') ? HOUR_IN_SECONDS : 5 * MINUTE_IN_SECONDS;
                $release_date = $timeout - $lifespan;
            }
        } else {
            $release_status = 'N/A';
        }
    }

    // Compose result
    $status = 'good';
    $description = '';
    $actions = array();
    $badge = array(
        'label' => 'RG Git Updater',
        'color' => 'blue',
    );
    // Determine status
    if (empty($token)) {
        $status = 'critical';
        $description .= __('No GitHub token saved. Private repositories and higher API rate limits require a token.', 'rg-git-updater');
    } elseif (!$token_ok) {
        $status = 'recommended';
        $description .= __('GitHub token verification is old or missing. Please verify your token.', 'rg-git-updater');
    }
    if ($release_status === 'N/A') {
        $status = ($status === 'critical') ? 'critical' : 'recommended';
        $description .= '<br>' . __('No recent release info could be loaded from GitHub. Check your token and network.', 'rg-git-updater');
    }

    // Build label
    if (!empty($token)) {
        if ($last_verified) {
            $label = sprintf(
                __('GitHub token verified %s ago', 'rg-git-updater'),
                human_time_diff($last_verified, $now)
            );
        } else {
            $label = __('GitHub token saved, not verified', 'rg-git-updater');
        }
    } else {
        $label = __('No GitHub token saved', 'rg-git-updater');
    }

    // Details for description
    $description .= '<ul style="margin-top:8px">';
    if (!empty($token)) {
        if ($last_verified) {
            $description .= '<li>' . sprintf(
                __('Token last verified: %s', 'rg-git-updater'),
                date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $last_verified)
            ) . '</li>';
        }
        if ($last_updated) {
            $description .= '<li>' . sprintf(
                __('Token last updated: %s', 'rg-git-updater'),
                date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $last_updated)
            ) . '</li>';
        }
    }
    if ($release_label) {
        $description .= '<li>' . sprintf(
            __('Latest release cache for %s: <strong>%s</strong>', 'rg-git-updater'),
            esc_html($release_label),
            esc_html($release_status)
        );
        if ($release_date) {
            $description .= ' — ' . sprintf(
                __('checked %s ago', 'rg-git-updater'),
                human_time_diff($release_date, $now)
            );
        }
        $description .= '</li>';
    }
    $description .= '</ul>';

    // Add action to go to settings page
    $actions[] = sprintf(
        '<a href="%s" class="button button-small">%s</a>',
        esc_url(admin_url('tools.php?page=rgplugins-settings')),
        esc_html__('Go to settings', 'rg-git-updater')
    );

    return array(
        'status'      => $status,
        'label'       => $label,
        'description' => $description,
        'badge'       => $badge,
        'actions'     => $actions,
        'test'        => 'rg_git_updater_health_test',
    );
}