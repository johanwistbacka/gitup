<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    define('ABSPATH', '/tmp/wordpress/');
}

if (!defined('WP_PLUGIN_DIR')) {
    define('WP_PLUGIN_DIR', sys_get_temp_dir() . '/gitup-wp-plugins');
}

if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

if (!defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}

if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

if (!defined('WP_DEBUG')) {
    define('WP_DEBUG', false);
}

$GLOBALS['gitup_test_hooks'] = [];
$GLOBALS['gitup_test_options'] = [];
$GLOBALS['gitup_test_transients'] = [];
$GLOBALS['gitup_test_site_transients'] = [];
$GLOBALS['gitup_test_http_responses'] = [];
$GLOBALS['gitup_test_http_calls'] = [];
$GLOBALS['gitup_test_theme_root'] = sys_get_temp_dir() . '/gitup-test-themes';
$GLOBALS['gitup_test_github_plugins'] = [];
$GLOBALS['gitup_test_github_themes'] = [];
$GLOBALS['gitup_test_installed_plugins'] = [];
$GLOBALS['gitup_test_installed_themes'] = [];
$GLOBALS['gitup_test_sent_mail'] = [];
$GLOBALS['gitup_test_active_plugins'] = [];
$GLOBALS['gitup_test_activate_plugin_result'] = null;
$GLOBALS['gitup_test_activate_plugin_marks_active'] = true;
$GLOBALS['gitup_test_activate_plugin_calls'] = [];

function add_filter($hook_name, $callback, $priority = 10, $accepted_args = 1) {
    $GLOBALS['gitup_test_hooks'][$hook_name][$priority][] = [
        'callback' => $callback,
        'accepted_args' => $accepted_args,
    ];
    return true;
}

function add_action($hook_name, $callback, $priority = 10, $accepted_args = 1) {
    return add_filter($hook_name, $callback, $priority, $accepted_args);
}

function remove_filter($hook_name, $callback, $priority = 10) {
    return true;
}

function remove_action($hook_name, $callback, $priority = 10) {
    return true;
}

function apply_filters($hook_name, $value, ...$args) {
    if (empty($GLOBALS['gitup_test_hooks'][$hook_name])) {
        return $value;
    }

    ksort($GLOBALS['gitup_test_hooks'][$hook_name]);
    foreach ($GLOBALS['gitup_test_hooks'][$hook_name] as $callbacks) {
        foreach ($callbacks as $entry) {
            $accepted_args = max(1, (int) $entry['accepted_args']);
            $call_args = array_merge([$value], $args);
            $value = $entry['callback'](...array_slice($call_args, 0, $accepted_args));
        }
    }

    return $value;
}

function do_action($hook_name, ...$args) {
    if (empty($GLOBALS['gitup_test_hooks'][$hook_name])) {
        return;
    }

    ksort($GLOBALS['gitup_test_hooks'][$hook_name]);
    foreach ($GLOBALS['gitup_test_hooks'][$hook_name] as $callbacks) {
        foreach ($callbacks as $entry) {
            $accepted_args = max(0, (int) $entry['accepted_args']);
            $entry['callback'](...array_slice($args, 0, $accepted_args));
        }
    }
}

function get_option($key, $default = false) {
    return $GLOBALS['gitup_test_options'][$key] ?? $default;
}

function update_option($key, $value) {
    $GLOBALS['gitup_test_options'][$key] = $value;
    return true;
}

function delete_option($key) {
    unset($GLOBALS['gitup_test_options'][$key]);
    return true;
}

function set_transient($key, $value, $expiration = 0) {
    $GLOBALS['gitup_test_transients'][$key] = $value;
    return true;
}

function get_transient($key) {
    return $GLOBALS['gitup_test_transients'][$key] ?? false;
}

function delete_transient($key) {
    unset($GLOBALS['gitup_test_transients'][$key]);
    return true;
}

function get_site_transient($key) {
    return $GLOBALS['gitup_test_site_transients'][$key] ?? false;
}

function trailingslashit($value) {
    return rtrim((string) $value, '/\\') . '/';
}

function untrailingslashit($value) {
    return rtrim((string) $value, '/\\');
}

function get_theme_root($stylesheet_or_template = null) {
    return $GLOBALS['gitup_test_theme_roots'][$stylesheet_or_template ?? ''] ?? $GLOBALS['gitup_test_theme_root'];
}

function current_user_can($capability) {
    $denied = $GLOBALS['gitup_test_denied_caps'] ?? [];
    return !in_array($capability, (array) $denied, true);
}

function wp_is_file_mod_allowed($context) {
    return apply_filters('file_mod_allowed', !defined('DISALLOW_FILE_MODS') || !DISALLOW_FILE_MODS, $context);
}

function admin_url($path = '') {
    return 'https://example.test/wp-admin/' . ltrim((string) $path, '/');
}

function add_query_arg($args, $url = '', $base_url = '') {
    if (!is_array($args)) {
        $args = [$args => $url];
        $url = $base_url;
    }
    $query = http_build_query((array) $args);
    if ($query === '') {
        return $url;
    }

    return rtrim((string) $url, '?') . (str_contains((string) $url, '?') ? '&' : '?') . $query;
}

function esc_url($url) {
    return (string) $url;
}

function esc_url_raw($url) {
    return (string) $url;
}

function wp_parse_url($url, $component = -1) {
    return parse_url((string) $url, $component);
}

function esc_html__($text, $domain = null) {
    return $text;
}

function esc_html_e($text, $domain = null) {
    echo (string) $text;
}

function esc_attr_e($text, $domain = null) {
    echo (string) $text;
}

function esc_html($text) {
    return (string) $text;
}

function esc_attr($text) {
    return (string) $text;
}

function esc_attr__($text, $domain = null) {
    return $text;
}

function plugin_dir_url($file) {
    return 'https://example.test/wp-content/plugins/gitup/';
}

function settings_errors() {
}

function settings_fields($group) {
}

function do_settings_sections($page) {
}

function submit_button($text, $type = 'primary', $name = 'submit', $wrap = true, $attributes = []) {
}

function esc_js($text) {
    return (string) $text;
}

function wpautop($text) {
    return (string) $text;
}

function make_clickable($text) {
    return (string) $text;
}

class GitupTestPermissionDenied extends RuntimeException {
}

class GitupTestRedirect extends RuntimeException {
}

function wp_die($message) {
    throw new GitupTestPermissionDenied((string) $message);
}

function check_admin_referer($action) {
    $GLOBALS['gitup_test_checked_nonces'][] = $action;
    return true;
}

function __($text, $domain = null) {
    return $text;
}

function wp_create_nonce($action = -1) {
    return 'test-nonce-' . md5((string) $action);
}

function get_current_user_id() {
    return $GLOBALS['gitup_test_current_user_id'] ?? 1;
}

function sanitize_text_field($value) {
    return is_scalar($value) ? trim((string) $value) : '';
}

function sanitize_key($key) {
    if (!is_scalar($key)) {
        return '';
    }
    return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $key));
}

function wp_unslash($value) {
    return $value;
}

function wp_verify_nonce($nonce, $action = -1) {
    return true;
}

function wp_safe_redirect($location, $status = 302, $x_redirect_by = 'WordPress') {
    $GLOBALS['gitup_test_last_redirect'] = $location;
    if (!empty($GLOBALS['gitup_test_throw_on_redirect'])) {
        throw new GitupTestRedirect((string) $location);
    }
    return true;
}

function current_time($type, $gmt = 0) {
    if ($type === 'timestamp') {
        return time();
    }
    return time();
}

function human_time_diff($from, $to = null) {
    $to = $to ?? time();
    $diff = max(0, (int) $to - (int) $from);

    if ($diff >= DAY_IN_SECONDS) {
        $value = (int) floor($diff / DAY_IN_SECONDS);
        return $value . ' day' . ($value === 1 ? '' : 's');
    }

    if ($diff >= HOUR_IN_SECONDS) {
        $value = (int) floor($diff / HOUR_IN_SECONDS);
        return $value . ' hour' . ($value === 1 ? '' : 's');
    }

    $value = max(1, (int) floor($diff / MINUTE_IN_SECONDS));
    return $value . ' min';
}

function date_i18n($format, $timestamp) {
    return date($format, (int) $timestamp);
}

function get_bloginfo($show = '') {
    if ($show === 'name') {
        return 'GitUp Test Site';
    }
    return '';
}

function wp_mail($to, $subject, $message, $headers = '', $attachments = []) {
    $GLOBALS['gitup_test_sent_mail'][] = compact('to', 'subject', 'message', 'headers', 'attachments');
    return true;
}

function get_github_plugins($refresh = false) {
    $GLOBALS['gitup_test_component_reads'][] = 'plugins';
    return $GLOBALS['gitup_test_github_plugins'];
}

function get_github_themes($refresh = false) {
    $GLOBALS['gitup_test_component_reads'][] = 'themes';
    return $GLOBALS['gitup_test_github_themes'];
}

function get_plugins() {
    return $GLOBALS['gitup_test_installed_plugins'];
}

function is_plugin_active($plugin) {
    return in_array($plugin, $GLOBALS['gitup_test_active_plugins'], true);
}

function activate_plugin($plugin, $redirect = '', $network_wide = false, $silent = false) {
    $GLOBALS['gitup_test_activate_plugin_calls'][] = [
        'plugin' => $plugin,
        'redirect' => $redirect,
        'network_wide' => $network_wide,
        'silent' => $silent,
    ];

    $result = $GLOBALS['gitup_test_activate_plugin_result'];
    if (!is_wp_error($result) && $GLOBALS['gitup_test_activate_plugin_marks_active']) {
        if (!in_array($plugin, $GLOBALS['gitup_test_active_plugins'], true)) {
            $GLOBALS['gitup_test_active_plugins'][] = $plugin;
        }
    }

    return $result;
}

function wp_get_themes() {
    return $GLOBALS['gitup_test_installed_themes'];
}

function wp_kses_post($text) {
    return (string) $text;
}

function wp_remote_retrieve_response_code($response) {
    return $response['response']['code'] ?? 0;
}

function wp_remote_retrieve_body($response) {
    return $response['body'] ?? '';
}

function wp_remote_retrieve_header($response, $header) {
    return $response['headers'][$header] ?? '';
}

function wp_tempnam($filename = '', $dir = '') {
    $targetDir = $dir !== '' ? $dir : sys_get_temp_dir();
    return tempnam($targetDir, 'gitup-download-');
}

function wp_remote_get($url, $args = []) {
    $GLOBALS['gitup_test_http_calls'][] = ['url' => $url, 'args' => $args];

    if (!array_key_exists($url, $GLOBALS['gitup_test_http_responses'])) {
        return new WP_Error('missing_http_stub', 'No HTTP stub queued for ' . $url);
    }

    $queued = &$GLOBALS['gitup_test_http_responses'][$url];
    if (is_array($queued) && array_key_exists(0, $queued)) {
        $response = array_shift($queued);
        if ($queued === []) {
            unset($GLOBALS['gitup_test_http_responses'][$url]);
        }
        if (!is_wp_error($response) && !empty($args['stream']) && !empty($args['filename'])) {
            file_put_contents($args['filename'], (string) ($response['body'] ?? ''));
        }
        return $response;
    }

    $response = $queued;
    unset($GLOBALS['gitup_test_http_responses'][$url]);
    if (!is_wp_error($response) && !empty($args['stream']) && !empty($args['filename'])) {
        file_put_contents($args['filename'], (string) ($response['body'] ?? ''));
    }
    return $response;
}

class WP_Error {
    private $code;
    private $message;
    private $data;

    public function __construct($code = '', $message = '', $data = null) {
        $this->code = $code;
        $this->message = $message;
        $this->data = $data;
    }

    public function get_error_code() {
        return $this->code;
    }

    public function get_error_message() {
        return $this->message;
    }

    public function get_error_data() {
        return $this->data;
    }
}

function is_wp_error($thing) {
    return $thing instanceof WP_Error;
}

class GitupTestTheme
{
    private array $headers;

    public function __construct(array $headers)
    {
        $this->headers = $headers;
    }

    public function get(string $key): string
    {
        return $this->headers[$key] ?? '';
    }
}

function gitup_test_reset_state(): void {
    $_GET = [];
    $_POST = [];
    $GLOBALS['gitup_test_options'] = [];
    $GLOBALS['gitup_test_transients'] = [];
    $GLOBALS['gitup_test_site_transients'] = [];
    $GLOBALS['gitup_test_http_responses'] = [];
    $GLOBALS['gitup_test_http_calls'] = [];
    $GLOBALS['gitup_test_component_reads'] = [];
    $GLOBALS['gitup_test_checked_nonces'] = [];
    $GLOBALS['gitup_test_throw_on_redirect'] = false;
    $GLOBALS['gitup_test_theme_root'] = sys_get_temp_dir() . '/gitup-test-themes';
    $GLOBALS['gitup_test_theme_roots'] = [];
    $GLOBALS['gitup_test_last_redirect'] = null;
    $GLOBALS['gitup_test_github_plugins'] = [];
    $GLOBALS['gitup_test_github_themes'] = [];
    $GLOBALS['gitup_test_installed_plugins'] = [];
    $GLOBALS['gitup_test_installed_themes'] = [];
    $GLOBALS['gitup_test_sent_mail'] = [];
    $GLOBALS['gitup_test_denied_caps'] = [];
    $GLOBALS['gitup_test_active_plugins'] = [];
    $GLOBALS['gitup_test_activate_plugin_result'] = null;
    $GLOBALS['gitup_test_activate_plugin_marks_active'] = true;
    $GLOBALS['gitup_test_activate_plugin_calls'] = [];
}

function gitup_test_queue_http_response(string $url, $response): void {
    if (!isset($GLOBALS['gitup_test_http_responses'][$url])) {
        $GLOBALS['gitup_test_http_responses'][$url] = [];
    }

    if (!is_array($GLOBALS['gitup_test_http_responses'][$url]) || !array_key_exists(0, $GLOBALS['gitup_test_http_responses'][$url])) {
        $GLOBALS['gitup_test_http_responses'][$url] = [];
    }

    $GLOBALS['gitup_test_http_responses'][$url][] = $response;
}

function gitup_test_http_call_count(string $url): int {
    $count = 0;
    foreach ($GLOBALS['gitup_test_http_calls'] as $call) {
        if (($call['url'] ?? '') === $url) {
            $count++;
        }
    }
    return $count;
}

require_once dirname(__DIR__) . '/gitup-updater.php';
require_once dirname(__DIR__) . '/options.php';
require_once dirname(__DIR__) . '/site-health.php';
require_once dirname(__DIR__) . '/install-from-url.php';
require_once dirname(__DIR__) . '/release-prefetch.php';
