<?php
/**
 * Exercises the real core install_package() and direct filesystem against disposable
 * extracted-package fixtures, without booting a site or connecting to its database.
 * Usage: php tests/theme-upgrade-integration.php /path/to/wordpress [baseline-repo] [mode]
 * Optional environment: GITUP_TEST_STYLESHEET, GITUP_TEST_INTEGER_ID=1,
 * GITUP_TEST_LAYOUT (run just one extracted-package layout).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit;
}

$core = isset($argv[1]) ? realpath($argv[1]) : false;
if (!$core || !is_file($core . '/wp-admin/includes/class-wp-upgrader.php')) {
    fwrite(STDERR, "Supply a WordPress core directory.\n");
    exit(2);
}
define('ABSPATH', $core . '/');
$fixture = sys_get_temp_dir() . '/gitup-core-' . uniqid('', true);
define('WP_CONTENT_DIR', $fixture . '/content');
define('FS_CHMOD_DIR', 0755);
define('FS_CHMOD_FILE', 0644);
require ($argv[2] ?? dirname(__DIR__)) . '/tests/bootstrap.php';
require ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
require ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
require ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

// Site/environment services are isolated; destination choice and deletion use core.
function wp_parse_args($args, $defaults = []) { return array_merge($defaults, $args); }
function wp_get_wp_version() { return '7.2'; }
function get_stylesheet() { return $GLOBALS['integration_active_theme']; }
function wp_clean_themes_cache() {}
function switch_theme($stylesheet) { $GLOBALS['integration_active_theme'] = $stylesheet; }
function is_php_version_compatible($version) { return true; }
function is_wp_version_compatible($version) { return true; }
function get_file_data($file, $headers) {
    $contents = file_get_contents($file);
    foreach ($headers as $key => $header) {
        preg_match('/^\s*' . preg_quote($header, '/') . ':\s*(.+)$/mi', $contents, $match);
        $headers[$key] = trim($match[1] ?? '');
    }
    return $headers;
}
function copy_dir($from, $to) {
    global $wp_filesystem;
    $wp_filesystem->mkdir($to);
    foreach ($wp_filesystem->dirlist($from) as $name => $info) {
        $result = $info['type'] === 'd'
            ? copy_dir($from . '/' . $name, $to . '/' . $name)
            : $wp_filesystem->copy($from . '/' . $name, $to . '/' . $name, true);
        if ($result !== true) {
            return new WP_Error('fixture_copy_failed', 'Fixture copy failed');
        }
    }
    return true;
}
function integration_assert($condition, $message) {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function integration_theme($path, $marker) {
    mkdir($path, 0755, true);
    file_put_contents($path . '/style.css', "/*\nTheme Name: Fixture\nVersion: 2.0\n*/\n");
    file_put_contents($path . '/index.php', "<?php // " . $marker);
}

$wp_filesystem = new WP_Filesystem_Direct(null);
$baseHooks = $GLOBALS['gitup_test_hooks'];
$cases = 0;
$stylesheet = getenv('GITUP_TEST_STYLESHEET');
$stylesheet = $stylesheet === false ? 'expected' : $stylesheet;
$identity = getenv('GITUP_TEST_INTEGER_ID') === '1' ? (int) $stylesheet : $stylesheet;
try {
    foreach (isset($argv[3]) ? [$argv[3]] : ['automatic', 'manual', 'first-install'] as $mode) {
        foreach (getenv('GITUP_TEST_LAYOUT') ? [getenv('GITUP_TEST_LAYOUT')] : ['collision', 'ambiguous', 'versioned', 'exact', 'existing', 'foreign-root'] as $layout) {
            $cases++;
            $case = $fixture . '/' . $mode . '-' . $layout;
            $themes = $case . '/themes';
            $source = $case . '/extracted';
            mkdir($source, 0755, true);
            integration_theme($themes . '/victim', 'victim-original');
            if ($mode !== 'first-install' || $layout === 'existing') {
                integration_theme($themes . '/' . $stylesheet, 'expected-original');
            }
            if ($layout === 'collision') {
                integration_theme($source . '/victim', 'malicious');
                mkdir($source . '/' . $stylesheet);
                file_put_contents($source . '/' . $stylesheet . '/blocker', 'blocker');
            } elseif ($layout === 'ambiguous') {
                integration_theme($source . '/one', 'ambiguous-one');
                integration_theme($source . '/two', 'ambiguous-two');
            } else {
                integration_theme($source . '/' . ($layout === 'exact' ? $stylesheet : ($layout === 'foreign-root' ? 'victim' : 'repo-2.0')), 'expected-new');
            }
            $GLOBALS['gitup_test_hooks'] = $baseHooks;
            $GLOBALS['gitup_test_theme_root'] = $themes;
            $GLOBALS['integration_active_theme'] = $stylesheet;
            $wp_theme_directories = [$themes];
            $upgrader = new Theme_Upgrader(new class {
                public function feedback(...$args) {}
            });
            $upgrader->bulk = true; // Avoid maintenance-file writes outside fixtures.
            $upgrader->strings = array_fill_keys(['remove_old', 'remove_old_failed', 'fs_error', 'dir_not_readable', 'incompatible_archive', 'bad_request', 'folder_exists'], 'Fixture error');
            add_filter('upgrader_post_install', [$upgrader, 'current_after'], 10, 2);
            if ($mode === 'manual') {
                add_filter('upgrader_package_options', gitup_build_theme_package_options_filter($identity));
                add_filter('upgrader_source_selection', [$upgrader, 'check_package']);
                add_filter('upgrader_clear_destination', [$upgrader, 'delete_old_theme'], 10, 4);
            } elseif ($mode === 'first-install') {
                add_filter('upgrader_package_options', gitup_build_theme_install_package_options_filter($identity));
                add_filter('upgrader_source_selection', [$upgrader, 'check_package']);
            }
            $options = apply_filters('upgrader_package_options', [
                'destination' => $themes,
                'clear_destination' => true,
                'abort_if_destination_exists' => false,
                'hook_extra' => ['theme' => $identity, 'type' => 'theme', 'action' => $mode === 'first-install' ? 'install' : 'update'],
            ]);
            $result = $upgrader->install_package(array_merge($options, [
                'source' => $source,
                'clear_working' => false,
            ]));
            $result = apply_filters('upgrader_install_package_result', $result, $options['hook_extra']);
            integration_assert(file_get_contents($themes . '/victim/index.php') === '<?php // victim-original', "$mode/$layout overwrote victim");
            if ($layout === 'collision' || $layout === 'ambiguous' || ($layout === 'existing' && $mode === 'first-install')) {
                integration_assert(is_wp_error($result), "$mode/$layout did not fail closed");
                if ($mode !== 'first-install' || $layout === 'existing') {
                    integration_assert(file_get_contents($themes . '/' . $stylesheet . '/index.php') === '<?php // expected-original', "$mode/$layout changed authorized theme on failure");
                }
            } else {
                integration_assert(!is_wp_error($result), "$mode/$layout rejected a legitimate package: " . (is_wp_error($result) ? $result->get_error_message() : ''));
                integration_assert($result['destination'] === $themes . '/' . $stylesheet . '/', "$mode/$layout wrong destination");
                integration_assert($result['destination_name'] === $stylesheet, "$mode/$layout wrong identity");
                integration_assert(file_get_contents($themes . '/' . $stylesheet . '/index.php') === '<?php // expected-new', "$mode/$layout did not update expected theme");
            }
            integration_assert(get_stylesheet() === $stylesheet, "$mode/$layout switched the active theme");
            echo "PASS $mode/$layout\n";
        }
    }
    echo "Passed: $cases real-core fixture cases\n";
} finally {
    $wp_filesystem->delete($fixture, true);
}
