<?php 
/**
 * Site Health: GitUp status test
 */

// Site Health integration: token status
add_filter('site_status_tests', function ($tests) {
    $tests['direct']['gitup_token'] = [
        'label' => __('GitHub Token Status', 'gitup'),
        'test'  => function () {
            $last_verified = intval(get_option('gitup_token_last_verified', 0));
            $token_state   = function_exists('gitup_get_token_state') ? gitup_get_token_state() : (get_option('gitup_github_token') ? 'unknown' : 'missing');
            $token         = get_option('gitup_github_token');

            if ($token_state === 'missing') {
                return [
                    'label'       => __('No GitHub token set', 'gitup'),
                    'status'      => 'recommended',
                    'badge'       => [
                        'label' => __('GitUp', 'gitup'),
                        'color' => 'blue',
                    ],
                    'description' => __('Public repositories will work, but private repositories require a valid token.', 'gitup'),
                    'actions'     => sprintf('<a href="%s">%s</a>', esc_url(admin_url('tools.php?page=gitup-settings')), __('Go to settings', 'gitup')),
                    'test'        => 'gitup_token',
                ];
            }

            if (in_array($token_state, ['invalid', 'expired'], true)) {
                return [
                    'label'       => __('GitHub token is invalid or expired', 'gitup'),
                    'status'      => 'critical',
                    'badge'       => [
                        'label' => __('GitUp', 'gitup'),
                        'color' => 'red',
                    ],
                    'description' => __('Your saved GitHub token is no longer usable. Update it in settings.', 'gitup'),
                    'actions'     => sprintf('<a href="%s">%s</a>', esc_url(admin_url('tools.php?page=gitup-settings')), __('Update token', 'gitup')),
                    'test'        => 'gitup_token',
                ];
            }

            if (!$last_verified || $last_verified < (time() - 30 * DAY_IN_SECONDS)) {
                return [
                    'label'       => __('GitHub token has not been verified recently', 'gitup'),
                    'status'      => 'recommended',
                    'badge'       => [
                        'label' => __('GitUp', 'gitup'),
                        'color' => 'orange',
                    ],
                    'description' => __('Your token has not been verified in the last 30 days. Visit settings to re-test.', 'gitup'),
                    'actions'     => sprintf('<a href="%s">%s</a>', esc_url(admin_url('tools.php?page=gitup-settings')), __('Re-test token', 'gitup')),
                    'test'        => 'gitup_token',
                ];
            }

            return [
                'label'       => __('GitHub token is valid', 'gitup'),
                'status'      => 'good',
                'badge'       => [
                    'label' => __('GitUp', 'gitup'),
                    'color' => 'green',
                ],
                'description' => sprintf(
                    __('Token last verified on %s.', 'gitup'),
                    date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $last_verified)
                ),
                'test'        => 'gitup_token',
            ];
        }
    ];
    return $tests;
});
add_filter('site_status_tests', function($tests) {
    $tests['direct']['gitup'] = array(
        'label'    => __('GitUp status', 'gitup'),
        'test'     => 'gitup_health_test',
    );
    return $tests;
});

/**
 * Callback for Site Health test: GitUp
 *
 * @return array
 */
function gitup_health_test() {
    // Log that the test was run
    if (function_exists('gitup_log')) {
        gitup_log('Site Health test: gitup_health_test called');
    }

    $token = get_option('gitup_github_token', '');
    $last_verified = (int)get_option('gitup_token_last_verified');
    $last_updated  = (int)get_option('gitup_token_last_updated');
    $now = current_time('timestamp');
    $token_state = function_exists('gitup_get_token_state') ? gitup_get_token_state() : (!empty($token) ? 'unknown' : 'missing');
    $token_status = '';
    $token_ok = false;
    $token_days_ago = null;
    if ($token_state !== 'missing') {
        if ($token_state === 'valid' && $last_verified) {
            $token_days_ago = floor(($now - $last_verified) / DAY_IN_SECONDS);
            $token_status = sprintf(
                __('GitHub token verified %s ago', 'gitup'),
                human_time_diff($last_verified, $now)
            );
            $token_ok = ($now - $last_verified < 14 * DAY_IN_SECONDS);
        } elseif ($token_state === 'expired') {
            $token_status = __('GitHub token has expired', 'gitup');
        } elseif ($token_state === 'invalid') {
            $token_status = __('GitHub token is invalid', 'gitup');
        } else {
            $token_status = __('GitHub token saved, not verified', 'gitup');
        }
    } else {
        $token_status = __('No GitHub token saved', 'gitup');
    }

    // Try to get release cache for at least one plugin or theme with GitHub UpdateURI
    $release_info = '';
    $release_status = 'N/A';
    $release_date = null;
    $release_label = '';
    $release_error = '';
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
        $include_pre = function_exists('gitup_include_prereleases_enabled') ? gitup_include_prereleases_enabled() : (get_option('gitup_include_prereleases', '0') === '1');
        $cache_key = 'github_release_' . md5($repo_url . '|' . ($include_pre ? 'pre' : 'stable'));
        $transient = get_transient($cache_key);
        $release_label = $item['name'] . ' (' . $repo_url . ')';
        if (is_string($transient) && $transient !== 'N/A') {
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
        } elseif (is_array($transient) && !empty($transient[0]['error'])) {
            $release_error = $transient[0]['error'];
            $release_status = strtoupper($release_error);
        } else {
            $release_status = 'N/A';
        }
    }

    // Compose result
    $status = 'good';
    $description = '';
    $actions = array();
    $badge = array(
        'label' => 'GitUp',
        'color' => 'blue',
    );
    // Determine status
    if ($token_state === 'missing') {
        $status = 'critical';
        $description .= __('No GitHub token saved. Private repositories and higher API rate limits require a token.', 'gitup');
    } elseif (in_array($token_state, ['invalid', 'expired'], true)) {
        $status = 'critical';
        $description .= __('GitHub token is invalid or expired. Please update it.', 'gitup');
    } elseif (!$token_ok) {
        $status = 'recommended';
        $description .= __('GitHub token verification is old or missing. Please verify your token.', 'gitup');
    }
    if ($release_error === 'rate_limit') {
        $status = ($status === 'critical') ? 'critical' : 'recommended';
        $description .= '<br>' . __('GitHub API rate limit was reached while checking releases. Add or verify a token.', 'gitup');
    } elseif ($release_status === 'N/A') {
        $status = ($status === 'critical') ? 'critical' : 'recommended';
        $description .= '<br>' . __('No recent release info could be loaded from GitHub. Check your token and network.', 'gitup');
    }

    // Build label
    if ($token_state !== 'missing') {
        if ($token_state === 'valid' && $last_verified) {
            $label = sprintf(
                __('GitHub token verified %s ago', 'gitup'),
                human_time_diff($last_verified, $now)
            );
        } elseif ($token_state === 'expired') {
            $label = __('GitHub token has expired', 'gitup');
        } elseif ($token_state === 'invalid') {
            $label = __('GitHub token is invalid', 'gitup');
        } else {
            $label = __('GitHub token saved, not verified', 'gitup');
        }
    } else {
        $label = __('No GitHub token saved', 'gitup');
    }

    // Details for description
    $description .= '<ul style="margin-top:8px">';
    if (!empty($token)) {
        if ($last_verified) {
            $description .= '<li>' . sprintf(
                __('Token last verified: %s', 'gitup'),
                date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $last_verified)
            ) . '</li>';
        }
        if ($last_updated) {
            $description .= '<li>' . sprintf(
                __('Token last updated: %s', 'gitup'),
                date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $last_updated)
            ) . '</li>';
        }
    }
    if ($release_label) {
        $description .= '<li>' . sprintf(
            __('Latest release cache for %s: <strong>%s</strong>', 'gitup'),
            esc_html($release_label),
            esc_html($release_status)
        );
        if ($release_date) {
            $description .= ' — ' . sprintf(
                __('checked %s ago', 'gitup'),
                human_time_diff($release_date, $now)
            );
        }
        $description .= '</li>';
    }
    $description .= '</ul>';

    // Add action to go to settings page
    $actions[] = sprintf(
        '<a href="%s" class="button button-small">%s</a>',
        esc_url(admin_url('tools.php?page=gitup-settings')),
        esc_html__('Go to settings', 'gitup')
    );

    return array(
        'status'      => $status,
        'label'       => $label,
        'description' => $description,
        'badge'       => $badge,
        'actions'     => $actions,
        'test'        => 'gitup_health_test',
    );
}
