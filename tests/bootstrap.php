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

if (!defined('WP_DEBUG')) {
    define('WP_DEBUG', false);
}

$GLOBALS['gitup_test_hooks'] = [];
$GLOBALS['gitup_test_options'] = [];
$GLOBALS['gitup_test_transients'] = [];
$GLOBALS['gitup_test_http_responses'] = [];
$GLOBALS['gitup_test_http_calls'] = [];
$GLOBALS['gitup_test_theme_root'] = sys_get_temp_dir() . '/gitup-test-themes';

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

function trailingslashit($value) {
    return rtrim((string) $value, '/\\') . '/';
}

function untrailingslashit($value) {
    return rtrim((string) $value, '/\\');
}

function get_theme_root($stylesheet_or_template = null) {
    return $GLOBALS['gitup_test_theme_root'];
}

function current_user_can($capability) {
    return true;
}

function admin_url($path = '') {
    return 'https://example.test/wp-admin/' . ltrim((string) $path, '/');
}

function esc_url($url) {
    return (string) $url;
}

function esc_html__($text, $domain = null) {
    return $text;
}

function esc_html($text) {
    return (string) $text;
}

function __($text, $domain = null) {
    return $text;
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
        return $response;
    }

    $response = $queued;
    unset($GLOBALS['gitup_test_http_responses'][$url]);
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

function gitup_test_reset_state(): void {
    $GLOBALS['gitup_test_options'] = [];
    $GLOBALS['gitup_test_transients'] = [];
    $GLOBALS['gitup_test_http_responses'] = [];
    $GLOBALS['gitup_test_http_calls'] = [];
    $GLOBALS['gitup_test_theme_root'] = sys_get_temp_dir() . '/gitup-test-themes';
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
