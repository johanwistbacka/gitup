<?php
/**
 * Site Health: GitUp status test
 */

if (!function_exists('gitup_get_site_health_badge')) {
  function gitup_get_site_health_badge($color = 'blue') {
    return [
      'label' => __('GitUp', 'gitup'),
      'color' => $color,
    ];
  }
}

if (!function_exists('gitup_get_settings_action_link')) {
  function gitup_get_settings_action_link($label = null, $button_class = '') {
    $label = $label ?: __('Go to settings', 'gitup');
    $class_attr = $button_class !== '' ? ' class="' . esc_attr($button_class) . '"' : '';

    return sprintf(
      '<a href="%s"%s>%s</a>',
      esc_url(admin_url('tools.php?page=gitup-settings')),
      $class_attr,
      esc_html($label)
    );
  }
}

if (!function_exists('gitup_get_site_health_token_summary')) {
  function gitup_get_site_health_token_summary() {
    $token = get_option('gitup_github_token', '');
    $last_verified = (int) get_option('gitup_token_last_verified');
    $last_updated = (int) get_option('gitup_token_last_updated');
    $now = current_time('timestamp');
    $token_state = function_exists('gitup_get_token_state')
      ? gitup_get_token_state()
      : (!empty($token) ? 'unknown' : 'missing');

    $summary = [
      'token'         => $token,
      'token_state'   => $token_state,
      'last_verified' => $last_verified,
      'last_updated'  => $last_updated,
      'now'           => $now,
      'token_ok'      => false,
      'label'         => __('No GitHub token saved', 'gitup'),
      'description'   => __('No GitHub token saved', 'gitup'),
      'site_status'   => 'critical',
      'badge_color'   => 'red',
      'action_label'  => __('Go to settings', 'gitup'),
    ];

    if ($token_state === 'missing') {
      $summary['description'] = __('Public repositories will work, but private repositories require a valid token.', 'gitup');
      $summary['site_status'] = 'recommended';
      $summary['badge_color'] = 'blue';
      return $summary;
    }

    if (in_array($token_state, ['invalid', 'expired'], true)) {
      $summary['label'] = __('GitHub token is invalid or expired', 'gitup');
      $summary['description'] = __('Your saved GitHub token is no longer usable. Update it in settings.', 'gitup');
      $summary['action_label'] = __('Update token', 'gitup');
      return $summary;
    }

    if ($token_state === 'valid' && $last_verified) {
      $summary['token_ok'] = ($now - $last_verified) < 14 * DAY_IN_SECONDS;
      $summary['label'] = sprintf(
        __('GitHub token verified %s ago', 'gitup'),
        human_time_diff($last_verified, $now)
      );
      $summary['description'] = sprintf(
        __('Token last verified on %s.', 'gitup'),
        date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $last_verified)
      );
      $summary['site_status'] = $summary['token_ok'] ? 'good' : 'recommended';
      $summary['badge_color'] = $summary['token_ok'] ? 'green' : 'orange';
      $summary['action_label'] = __('Re-test token', 'gitup');
      return $summary;
    }

    $summary['label'] = __('GitHub token has not been verified recently', 'gitup');
    $summary['description'] = __('Your token has not been verified in the last 30 days. Visit settings to re-test.', 'gitup');
    $summary['site_status'] = 'recommended';
    $summary['badge_color'] = 'orange';
    $summary['action_label'] = __('Re-test token', 'gitup');

    return $summary;
  }
}

if (!function_exists('gitup_get_site_health_release_summary')) {
  function gitup_get_site_health_release_summary() {
    $summary = [
      'status'        => 'N/A',
      'label'         => '',
      'error'         => '',
      'checked_at'    => null,
    ];

    $github_plugins = function_exists('get_github_plugins') ? get_github_plugins(false) : [];
    $github_themes = function_exists('get_github_themes') ? get_github_themes(false) : [];
    $item = !empty($github_plugins) ? $github_plugins[0] : (!empty($github_themes) ? $github_themes[0] : null);

    if (!$item || empty($item['github'])) {
      return $summary;
    }

    $repo_url = $item['github'];
    $include_pre = function_exists('gitup_include_prereleases_enabled')
      ? gitup_include_prereleases_enabled()
      : (function_exists('gitup_should_include_prereleases') ? gitup_should_include_prereleases() : (get_option('gitup_include_prereleases', '0') === '1'));
    $cache_key = 'github_release_' . md5($repo_url . '|' . ($include_pre ? 'pre' : 'stable'));
    $transient = get_transient($cache_key);
    $summary['label'] = $item['name'] . ' (' . $repo_url . ')';

    if (is_string($transient) && $transient !== 'N/A') {
      $summary['status'] = $transient;
      global $wpdb;
      if (isset($wpdb) && is_object($wpdb) && method_exists($wpdb, 'get_var') && method_exists($wpdb, 'prepare')) {
        $timeout_val = $wpdb->get_var($wpdb->prepare(
          "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
          '_transient_timeout_' . $cache_key
        ));
        if ($timeout_val) {
          $summary['checked_at'] = ((int) $timeout_val) - HOUR_IN_SECONDS;
        }
      }
      return $summary;
    }

    if (is_array($transient) && !empty($transient[0]['error'])) {
      $summary['error'] = $transient[0]['error'];
      $summary['status'] = strtoupper($summary['error']);
    }

    return $summary;
  }
}

if (!function_exists('gitup_build_site_health_result')) {
  function gitup_build_site_health_result($args) {
    return [
      'label'       => $args['label'],
      'status'      => $args['status'],
      'badge'       => gitup_get_site_health_badge($args['badge_color'] ?? 'blue'),
      'description' => $args['description'],
      'actions'     => $args['actions'] ?? '',
      'test'        => $args['test'],
    ];
  }
}

// Site Health integration: token status
add_filter('site_status_tests', function ($tests) {
  $tests['direct']['gitup_token'] = [
    'label' => __('GitHub Token Status', 'gitup'),
    'test'  => function () {
      $summary = gitup_get_site_health_token_summary();

      return gitup_build_site_health_result([
        'label'       => $summary['label'],
        'status'      => $summary['site_status'],
        'badge_color' => $summary['badge_color'],
        'description' => $summary['description'],
        'actions'     => gitup_get_settings_action_link($summary['action_label']),
        'test'        => 'gitup_token',
      ]);
    },
  ];

  $tests['direct']['gitup'] = [
    'label' => __('GitUp status', 'gitup'),
    'test'  => 'gitup_health_test',
  ];

  return $tests;
});

/**
 * Callback for Site Health test: GitUp
 *
 * @return array
 */
function gitup_health_test() {
  if (function_exists('gitup_log')) {
    gitup_log('Site Health test: gitup_health_test called');
  }

  $token_summary = gitup_get_site_health_token_summary();
  $release_summary = gitup_get_site_health_release_summary();

  $status = $token_summary['site_status'] === 'good' ? 'good' : $token_summary['site_status'];
  $description_parts = [];

  if ($token_summary['token_state'] === 'missing') {
    $description_parts[] = __('No GitHub token saved. Private repositories and higher API rate limits require a token.', 'gitup');
  } elseif (in_array($token_summary['token_state'], ['invalid', 'expired'], true)) {
    $description_parts[] = __('GitHub token is invalid or expired. Please update it.', 'gitup');
    $status = 'critical';
  } elseif (!$token_summary['token_ok']) {
    $description_parts[] = __('GitHub token verification is old or missing. Please verify your token.', 'gitup');
    if ($status !== 'critical') {
      $status = 'recommended';
    }
  }

  if ($release_summary['error'] === 'rate_limit') {
    $description_parts[] = __('GitHub API rate limit was reached while checking releases. Add or verify a token.', 'gitup');
    if ($status !== 'critical') {
      $status = 'recommended';
    }
  } elseif ($release_summary['status'] === 'N/A') {
    $description_parts[] = __('No recent release info could be loaded from GitHub. Check your token and network.', 'gitup');
    if ($status !== 'critical') {
      $status = 'recommended';
    }
  }

  $details = [];
  if (!empty($token_summary['token'])) {
    if ($token_summary['last_verified']) {
      $details[] = sprintf(
        __('Token last verified: %s', 'gitup'),
        date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $token_summary['last_verified'])
      );
    }
    if ($token_summary['last_updated']) {
      $details[] = sprintf(
        __('Token last updated: %s', 'gitup'),
        date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $token_summary['last_updated'])
      );
    }
  }

  if ($release_summary['label'] !== '') {
    $release_detail = sprintf(
      __('Latest release cache for %s: <strong>%s</strong>', 'gitup'),
      esc_html($release_summary['label']),
      esc_html($release_summary['status'])
    );

    if ($release_summary['checked_at']) {
      $release_detail .= ' — ' . sprintf(
        __('checked %s ago', 'gitup'),
        human_time_diff($release_summary['checked_at'], $token_summary['now'])
      );
    }

    $details[] = $release_detail;
  }

  $description = implode('<br>', $description_parts);
  if (!empty($details)) {
    $description .= '<ul style="margin-top:8px"><li>' . implode('</li><li>', $details) . '</li></ul>';
  }

  return [
    'status'      => $status,
    'label'       => $token_summary['label'],
    'description' => $description,
    'badge'       => gitup_get_site_health_badge('blue'),
    'actions'     => [gitup_get_settings_action_link(__('Go to settings', 'gitup'), 'button button-small')],
    'test'        => 'gitup_health_test',
  ];
}
