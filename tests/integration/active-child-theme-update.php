<?php
/**
 * Regression for manual updating an active child theme in a disposable site.
 * Run with wp-mamp eval-file after defining GITUP_INTEGRATION_TEST in its config.
 */
if (!defined('ABSPATH') || !defined('GITUP_INTEGRATION_TEST') || !GITUP_INTEGRATION_TEST || wp_get_environment_type() !== 'local') {
    throw new RuntimeException('A disposable integration test site is required.');
}
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/theme.php';
wp_set_current_user(1);
$originalTheme = get_stylesheet();
$root = get_theme_root();
$parent = 'gitup-release-parent';
$child = 'gitup-release-child';
if (file_exists($root . '/' . $parent) || file_exists($root . '/' . $child)) {
    throw new RuntimeException('Fixture theme names already exist.');
}
$archive = wp_tempnam('gitup-child-test.zip');
$repo = 'https://github.com/fixture/gitup-release-child';
$style = "/*\nTheme Name: GitUp Release Child\nVersion: 2.0.0\nTemplate: $parent\nUpdate URI: $repo\n*/\n";
$zip = new ZipArchive();
$zip->open($archive, ZipArchive::OVERWRITE);
$zip->addFromString('gitup-release-child-2.0.0/style.css', $style);
$zip->addFromString('gitup-release-child-2.0.0/index.php', '<?php // Inert fixture.');
$zip->close();
$http = function ($pre, $args, $url) use ($archive) {
    if (strpos($url, 'https://api.github.com/repos/fixture/gitup-release-child/releases') === 0) {
        return array('response' => array('code' => 200), 'headers' => array(), 'body' => json_encode(array('tag_name' => '2.0.0', 'name' => '2.0.0')));
    }
    if ($url === 'https://codeload.github.com/fixture/gitup-release-child/zip/refs/tags/2.0.0') {
        if (!empty($args['stream']) && !empty($args['filename'])) { copy($archive, $args['filename']); }
        return array('response' => array('code' => 200), 'headers' => array('content-type' => 'application/zip'), 'body' => file_get_contents($archive));
    }
    return $pre;
};
class GitupChildTestRedirect extends RuntimeException {}
$redirect = function ($url) { throw new GitupChildTestRedirect($url); };
$bufferLevel = ob_get_level();
try {
    wp_mkdir_p($root . '/' . $parent);
    wp_mkdir_p($root . '/' . $child);
    file_put_contents($root . '/' . $parent . '/style.css', "/*\nTheme Name: GitUp Release Parent\nVersion: 1.0.0\n*/\n");
    file_put_contents($root . '/' . $parent . '/index.php', '<?php // Inert fixture.');
    file_put_contents($root . '/' . $child . '/style.css', str_replace('2.0.0', '1.0.0', $style));
    file_put_contents($root . '/' . $child . '/index.php', '<?php // Inert fixture.');
    wp_clean_themes_cache(false);
    switch_theme($child);
    add_filter('pre_http_request', $http, PHP_INT_MAX, 3);
    add_filter('wp_redirect', $redirect, -999);
    $_POST = array('theme' => $child, 'tag' => '2.0.0', '_wpnonce' => wp_create_nonce('gitup_themes_install_release_' . $child));
    ob_start();
    try {
        do_action('admin_post_gitup_themes_install_release');
        throw new RuntimeException('Missing redirect after update.');
    } catch (GitupChildTestRedirect $e) {
        parse_str(parse_url($e->getMessage(), PHP_URL_QUERY), $query);
        if (empty($query['ok'])) { throw new RuntimeException('Update failed: ' . ($query['gitup_msg'] ?? 'unknown')); }
    }
    if (get_stylesheet() !== $child || get_template() !== $parent || wp_get_theme($child)->get('Version') !== '2.0.0') {
        throw new RuntimeException('Active child theme or parent was not preserved.');
    }
} finally {
    while (ob_get_level() > $bufferLevel) { ob_end_clean(); }
    remove_filter('wp_redirect', $redirect, -999);
    remove_filter('pre_http_request', $http, PHP_INT_MAX);
    switch_theme($originalTheme);
    require_once ABSPATH . 'wp-admin/includes/file.php';
    WP_Filesystem();
    global $wp_filesystem;
    $wp_filesystem->delete($root . '/' . $child, true);
    $wp_filesystem->delete($root . '/' . $parent, true);
    unlink($archive);
    wp_clean_themes_cache(false);
}
echo 'PASS manual active child-theme update preserves stylesheet and parent; WordPress ' . get_bloginfo('version') . PHP_EOL;
