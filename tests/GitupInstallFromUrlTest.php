<?php

declare(strict_types=1);

final class GitupInstallFromUrlTest extends GitupTestCase
{
    public function test_parse_install_source_rejects_empty_input(): void
    {
        $result = gitup_parse_install_source('');

        $this->assertTrue(is_wp_error($result));
        $this->assertSame('gitup_invalid_url', $result->get_error_code());
    }

    public function test_parse_install_source_rejects_non_string_input(): void
    {
        $result = gitup_parse_install_source(null);

        $this->assertTrue(is_wp_error($result));
        $this->assertSame('gitup_invalid_url', $result->get_error_code());
    }

    public function test_parse_install_source_accepts_full_https_url(): void
    {
        $result = gitup_parse_install_source('https://github.com/owner/repo');

        $this->assertFalse(is_wp_error($result));
        $this->assertSame('https://github.com/owner/repo', $result['repo_url']);
        $this->assertSame(null, $result['tag']);
    }

    public function test_parse_install_source_trims_surrounding_whitespace(): void
    {
        $result = gitup_parse_install_source("  https://github.com/owner/repo\n");

        $this->assertFalse(is_wp_error($result));
        $this->assertSame('https://github.com/owner/repo', $result['repo_url']);
    }

    public function test_parse_install_source_upgrades_http_to_https(): void
    {
        $result = gitup_parse_install_source('http://github.com/owner/repo');

        $this->assertFalse(is_wp_error($result));
        $this->assertSame('https://github.com/owner/repo', $result['repo_url']);
    }

    public function test_parse_install_source_accepts_bare_owner_repo(): void
    {
        $result = gitup_parse_install_source('owner/repo');

        $this->assertFalse(is_wp_error($result));
        $this->assertSame('https://github.com/owner/repo', $result['repo_url']);
        $this->assertSame(null, $result['tag']);
    }

    public function test_parse_install_source_strips_dot_git_suffix(): void
    {
        $result = gitup_parse_install_source('https://github.com/owner/repo.git');

        $this->assertFalse(is_wp_error($result));
        $this->assertSame('https://github.com/owner/repo', $result['repo_url']);
    }

    public function test_parse_install_source_accepts_trailing_slash(): void
    {
        $result = gitup_parse_install_source('https://github.com/owner/repo/');

        $this->assertFalse(is_wp_error($result));
        $this->assertSame('https://github.com/owner/repo', $result['repo_url']);
        $this->assertSame(null, $result['tag']);
    }

    public function test_parse_install_source_extracts_tag_from_tree_url(): void
    {
        $result = gitup_parse_install_source('https://github.com/owner/repo/tree/v1.2.3');

        $this->assertFalse(is_wp_error($result));
        $this->assertSame('https://github.com/owner/repo', $result['repo_url']);
        $this->assertSame('v1.2.3', $result['tag']);
    }

    public function test_parse_install_source_extracts_tag_with_slash_from_tree_url(): void
    {
        $result = gitup_parse_install_source('https://github.com/owner/repo/tree/release/2026-03-beta');

        $this->assertFalse(is_wp_error($result));
        $this->assertSame('https://github.com/owner/repo', $result['repo_url']);
        $this->assertSame('release/2026-03-beta', $result['tag']);
    }

    public function test_parse_install_source_extracts_tag_from_releases_tag_url(): void
    {
        $result = gitup_parse_install_source('https://github.com/owner/repo/releases/tag/1.0.0');

        $this->assertFalse(is_wp_error($result));
        $this->assertSame('https://github.com/owner/repo', $result['repo_url']);
        $this->assertSame('1.0.0', $result['tag']);
    }

    public function test_parse_install_source_extracts_tag_from_archive_zip_url(): void
    {
        $result = gitup_parse_install_source('https://github.com/owner/repo/archive/refs/tags/1.0.0.zip');

        $this->assertFalse(is_wp_error($result));
        $this->assertSame('https://github.com/owner/repo', $result['repo_url']);
        $this->assertSame('1.0.0', $result['tag']);
    }

    public function test_parse_install_source_rejects_ssh_url(): void
    {
        $result = gitup_parse_install_source('git@github.com:owner/repo.git');

        $this->assertTrue(is_wp_error($result));
        $this->assertSame('gitup_invalid_url', $result->get_error_code());
    }

    public function test_parse_install_source_rejects_gist_host(): void
    {
        $result = gitup_parse_install_source('https://gist.github.com/owner/abc123');

        $this->assertTrue(is_wp_error($result));
        $this->assertSame('gitup_invalid_url', $result->get_error_code());
    }

    public function test_parse_install_source_rejects_enterprise_host(): void
    {
        $result = gitup_parse_install_source('https://github.example.com/owner/repo');

        $this->assertTrue(is_wp_error($result));
        $this->assertSame('gitup_invalid_url', $result->get_error_code());
    }

    public function test_parse_install_source_rejects_raw_host(): void
    {
        $result = gitup_parse_install_source('https://raw.githubusercontent.com/owner/repo/main/file.php');

        $this->assertTrue(is_wp_error($result));
        $this->assertSame('gitup_invalid_url', $result->get_error_code());
    }

    public function test_parse_install_source_rejects_owner_without_repo(): void
    {
        $result = gitup_parse_install_source('https://github.com/owner');

        $this->assertTrue(is_wp_error($result));
        $this->assertSame('gitup_invalid_url', $result->get_error_code());
    }

    public function test_parse_install_source_rejects_unsupported_path(): void
    {
        $result = gitup_parse_install_source('https://github.com/owner/repo/wiki');

        $this->assertTrue(is_wp_error($result));
        $this->assertSame('gitup_invalid_url', $result->get_error_code());
    }

    public function test_parse_install_source_rejects_garbage_input(): void
    {
        $result = gitup_parse_install_source('not a url at all');

        $this->assertTrue(is_wp_error($result));
        $this->assertSame('gitup_invalid_url', $result->get_error_code());
    }

    public function test_parse_install_source_rejects_bare_owner_without_repo(): void
    {
        $result = gitup_parse_install_source('justowner');

        $this->assertTrue(is_wp_error($result));
        $this->assertSame('gitup_invalid_url', $result->get_error_code());
    }

    public function test_detect_repo_component_type_returns_plugin_for_php_with_plugin_name_header(): void
    {
        $this->queueContentsListing('owner/repo', '1.0.0', [
            ['name' => 'my-plugin.php', 'type' => 'file'],
            ['name' => 'README.md', 'type' => 'file'],
        ]);
        $this->queueFileContent('owner/repo', 'my-plugin.php', '1.0.0', "<?php\n/*\nPlugin Name: My Plugin\n*/\n");

        $result = gitup_detect_repo_component_type('https://github.com/owner/repo', '1.0.0');

        $this->assertFalse(is_wp_error($result));
        $this->assertSame('plugin', $result['type']);
        $this->assertSame('My Plugin', $result['plugin_name']);
        $this->assertSame(null, $result['theme_name']);
    }

    public function test_detect_repo_component_type_returns_theme_for_style_css_with_theme_name(): void
    {
        $this->queueContentsListing('owner/repo', '1.0.0', [
            ['name' => 'style.css', 'type' => 'file'],
            ['name' => 'index.php', 'type' => 'file'],
        ]);
        $this->queueFileContent('owner/repo', 'style.css', '1.0.0', "/*\nTheme Name: My Theme\n*/\n");
        // index.php has no Plugin Name header — should not trigger plugin detection.
        $this->queueFileContent('owner/repo', 'index.php', '1.0.0', "<?php\n// silence\n");

        $result = gitup_detect_repo_component_type('https://github.com/owner/repo', '1.0.0');

        $this->assertFalse(is_wp_error($result));
        $this->assertSame('theme', $result['type']);
        $this->assertSame('My Theme', $result['theme_name']);
        $this->assertSame(null, $result['plugin_name']);
    }

    public function test_detect_repo_component_type_returns_both_when_plugin_and_theme_headers_found(): void
    {
        $this->queueContentsListing('owner/repo', '1.0.0', [
            ['name' => 'style.css', 'type' => 'file'],
            ['name' => 'my-plugin.php', 'type' => 'file'],
        ]);
        $this->queueFileContent('owner/repo', 'style.css', '1.0.0', "/*\nTheme Name: My Theme\n*/\n");
        $this->queueFileContent('owner/repo', 'my-plugin.php', '1.0.0', "<?php\n/*\nPlugin Name: My Plugin\n*/\n");

        $result = gitup_detect_repo_component_type('https://github.com/owner/repo', '1.0.0');

        $this->assertFalse(is_wp_error($result));
        $this->assertSame('both', $result['type']);
        $this->assertSame('My Plugin', $result['plugin_name']);
        $this->assertSame('My Theme', $result['theme_name']);
    }

    public function test_detect_repo_component_type_returns_none_when_no_headers_found(): void
    {
        $this->queueContentsListing('owner/repo', '1.0.0', [
            ['name' => 'README.md', 'type' => 'file'],
            ['name' => 'LICENSE', 'type' => 'file'],
        ]);

        $result = gitup_detect_repo_component_type('https://github.com/owner/repo', '1.0.0');

        $this->assertFalse(is_wp_error($result));
        $this->assertSame('none', $result['type']);
        $this->assertSame(null, $result['plugin_name']);
        $this->assertSame(null, $result['theme_name']);
    }

    public function test_detect_repo_component_type_prefers_repo_named_php_file(): void
    {
        // Repo is 'gitup' — gitup.php should be checked before some-other.php.
        $this->queueContentsListing('owner/gitup', '1.0.0', [
            ['name' => 'some-other.php', 'type' => 'file'],
            ['name' => 'gitup.php', 'type' => 'file'],
        ]);
        $this->queueFileContent('owner/gitup', 'gitup.php', '1.0.0', "<?php\n/*\nPlugin Name: GitUp\n*/\n");
        // some-other.php intentionally not stubbed — if it gets fetched, the test framework will queue-miss.

        $result = gitup_detect_repo_component_type('https://github.com/owner/gitup', '1.0.0');

        $this->assertFalse(is_wp_error($result));
        $this->assertSame('plugin', $result['type']);
        $this->assertSame('GitUp', $result['plugin_name']);
    }

    public function test_detect_repo_component_type_rejects_invalid_repo_url(): void
    {
        $result = gitup_detect_repo_component_type('not-a-url', '1.0.0');

        $this->assertTrue(is_wp_error($result));
        $this->assertSame('gitup_invalid_url', $result->get_error_code());
    }

    public function test_detect_repo_component_type_returns_error_when_repo_not_found(): void
    {
        $apiUrl = 'https://api.github.com/repos/owner/missing/contents?ref=1.0.0';
        gitup_test_queue_http_response($apiUrl, [
            'response' => ['code' => 404],
            'headers'  => [],
            'body'     => '{"message":"Not Found"}',
        ]);

        $result = gitup_detect_repo_component_type('https://github.com/owner/missing', '1.0.0');

        $this->assertTrue(is_wp_error($result));
        $this->assertSame('gitup_detect_not_found', $result->get_error_code());
    }

    public function test_detect_repo_component_type_returns_error_on_auth_failure(): void
    {
        $apiUrl = 'https://api.github.com/repos/owner/repo/contents?ref=1.0.0';
        gitup_test_queue_http_response($apiUrl, [
            'response' => ['code' => 401],
            'headers'  => [],
            'body'     => '{"message":"Bad credentials"}',
        ]);

        $result = gitup_detect_repo_component_type('https://github.com/owner/repo', '1.0.0');

        $this->assertTrue(is_wp_error($result));
        $this->assertSame('gitup_detect_auth', $result->get_error_code());
    }

    public function test_detect_repo_component_type_handles_tag_with_slash(): void
    {
        // Tag "release/2026-03" must be rawurlencoded → release%2F2026-03
        $this->queueContentsListing('owner/repo', 'release/2026-03', [
            ['name' => 'style.css', 'type' => 'file'],
        ]);
        $this->queueFileContent('owner/repo', 'style.css', 'release/2026-03', "/*\nTheme Name: Slash Theme\n*/\n");

        $result = gitup_detect_repo_component_type('https://github.com/owner/repo', 'release/2026-03');

        $this->assertFalse(is_wp_error($result));
        $this->assertSame('theme', $result['type']);
        $this->assertSame('Slash Theme', $result['theme_name']);
    }

    public function test_prepare_plugin_install_from_url_rejects_invalid_repo(): void
    {
        $result = gitup_prepare_plugin_install_from_url('not-a-url', '1.0.0');

        $this->assertTrue(is_wp_error($result));
        $this->assertSame('gitup_invalid_url', $result->get_error_code());
    }

    public function test_prepare_plugin_install_from_url_rejects_empty_tag(): void
    {
        $result = gitup_prepare_plugin_install_from_url('https://github.com/owner/repo', '');

        $this->assertTrue(is_wp_error($result));
        $this->assertSame('gitup_missing_tag', $result->get_error_code());
    }

    public function test_prepare_plugin_install_from_url_rejects_unverified_tag(): void
    {
        $repo = 'https://github.com/owner/repo';
        update_option('gitup_include_prereleases', '1');
        $this->queueRepoReleases($repo, [
            ['tag_name' => '1.0.0', 'name' => '1.0.0', 'prerelease' => false],
        ]);

        $result = gitup_prepare_plugin_install_from_url($repo, '9.9.9');

        $this->assertTrue(is_wp_error($result));
        $this->assertSame('gitup_install_release_not_verified', $result->get_error_code());
    }

    public function test_prepare_plugin_install_from_url_returns_package_and_auto_slug(): void
    {
        $repo = 'https://github.com/owner/my-plugin';
        update_option('gitup_include_prereleases', '1');
        $this->queueRepoReleases($repo, [
            ['tag_name' => '1.0.0', 'name' => '1.0.0', 'prerelease' => false],
        ]);

        $result = gitup_prepare_plugin_install_from_url($repo, '1.0.0');

        $this->assertFalse(is_wp_error($result));
        $this->assertSame('https://github.com/owner/my-plugin', $result['repo_url']);
        $this->assertSame('1.0.0', $result['tag']);
        $this->assertSame('my-plugin', $result['desired_slug']);
        $this->assertSame(
            'https://codeload.github.com/owner/my-plugin/zip/refs/tags/1.0.0',
            $result['package']
        );
    }

    public function test_prepare_plugin_install_from_url_honors_explicit_slug(): void
    {
        $repo = 'https://github.com/owner/my-plugin';
        update_option('gitup_include_prereleases', '1');
        $this->queueRepoReleases($repo, [
            ['tag_name' => '1.0.0', 'name' => '1.0.0', 'prerelease' => false],
        ]);

        $result = gitup_prepare_plugin_install_from_url($repo, '1.0.0', 'Custom Slug Name');

        $this->assertFalse(is_wp_error($result));
        // sanitize_key strips spaces and lowercases.
        $this->assertSame('customslugname', $result['desired_slug']);
    }

    public function test_prepare_theme_install_from_url_rejects_invalid_repo(): void
    {
        $result = gitup_prepare_theme_install_from_url('not-a-url', '1.0.0');

        $this->assertTrue(is_wp_error($result));
        $this->assertSame('gitup_invalid_url', $result->get_error_code());
    }

    public function test_prepare_theme_install_from_url_rejects_empty_tag(): void
    {
        $result = gitup_prepare_theme_install_from_url('https://github.com/owner/repo', '');

        $this->assertTrue(is_wp_error($result));
        $this->assertSame('gitup_missing_tag', $result->get_error_code());
    }

    public function test_prepare_theme_install_from_url_returns_package_and_auto_stylesheet(): void
    {
        $repo = 'https://github.com/owner/my-theme';
        update_option('gitup_include_prereleases', '1');
        $this->queueRepoReleases($repo, [
            ['tag_name' => '2.0.0', 'name' => '2.0.0', 'prerelease' => false],
        ]);

        $result = gitup_prepare_theme_install_from_url($repo, '2.0.0');

        $this->assertFalse(is_wp_error($result));
        $this->assertSame('my-theme', $result['desired_stylesheet']);
        $this->assertSame(
            'https://codeload.github.com/owner/my-theme/zip/refs/tags/2.0.0',
            $result['package']
        );
    }

    public function test_build_plugin_install_package_options_filter_sets_destination_and_hook_extra(): void
    {
        $filter = gitup_build_plugin_install_package_options_filter('my-plugin');
        $options = $filter([
            'hook_extra'      => [],
            'destination'     => '/tmp/wrong/',
            'clear_destination' => true,
            'abort_if_destination_exists' => false,
        ]);

        $this->assertSame('my-plugin/my-plugin.php', $options['hook_extra']['plugin']);
        $this->assertTrue($options['hook_extra']['gitup_install_from_url']);
        $this->assertSame(trailingslashit(WP_PLUGIN_DIR) . 'my-plugin/', $options['destination']);
        $this->assertSame('my-plugin', $options['destination_name']);
        $this->assertFalse($options['clear_destination']);
        $this->assertTrue($options['abort_if_destination_exists']);
    }

    public function test_build_theme_install_package_options_filter_sets_destination_and_hook_extra(): void
    {
        $filter = gitup_build_theme_install_package_options_filter('my-theme');
        $options = $filter([
            'hook_extra'      => [],
            'destination'     => '/tmp/wrong/',
            'destination_name' => 'leftover',
            'clear_destination' => true,
            'abort_if_destination_exists' => false,
        ]);

        $this->assertSame('my-theme', $options['hook_extra']['theme']);
        $this->assertTrue($options['hook_extra']['gitup_install_from_url']);
        $this->assertSame(get_theme_root(), $options['destination']);
        $this->assertFalse(isset($options['destination_name']));
        $this->assertFalse($options['clear_destination']);
        $this->assertTrue($options['abort_if_destination_exists']);
    }

    public function test_run_preview_returns_full_data_for_repo_with_releases(): void
    {
        update_option('gitup_include_prereleases', '1');
        $repo = 'https://github.com/owner/my-plugin';
        $this->queueRepoReleases($repo, [
            ['tag_name' => '1.0.0', 'name' => '1.0.0', 'prerelease' => false],
            ['tag_name' => '0.9.0', 'name' => '0.9.0', 'prerelease' => false],
        ]);
        $this->queueContentsListing('owner/my-plugin', '1.0.0', [
            ['name' => 'my-plugin.php', 'type' => 'file'],
        ]);
        $this->queueFileContent('owner/my-plugin', 'my-plugin.php', '1.0.0', "<?php\n/*\nPlugin Name: My Plugin\n*/\n");

        $result = gitup_run_install_from_url_preview($repo);

        $this->assertFalse(is_wp_error($result));
        $this->assertSame('https://github.com/owner/my-plugin', $result['repo_url']);
        $this->assertSame('1.0.0', $result['tag_used']);
        $this->assertSame('plugin', $result['type']);
        $this->assertSame('My Plugin', $result['plugin_name']);
        $this->assertCount(2, $result['releases']);
    }

    public function test_run_preview_uses_tag_from_url_when_provided(): void
    {
        update_option('gitup_include_prereleases', '1');
        $repo = 'https://github.com/owner/my-plugin';
        $this->queueRepoReleases($repo, [
            ['tag_name' => '1.0.0', 'name' => '1.0.0', 'prerelease' => false],
            ['tag_name' => '0.9.0', 'name' => '0.9.0', 'prerelease' => false],
        ]);
        // Stubs only for 0.9.0 — if it accidentally inspects latest, no stub will match.
        $this->queueContentsListing('owner/my-plugin', '0.9.0', [
            ['name' => 'my-plugin.php', 'type' => 'file'],
        ]);
        $this->queueFileContent('owner/my-plugin', 'my-plugin.php', '0.9.0', "<?php\n/*\nPlugin Name: Old Plugin\n*/\n");

        $result = gitup_run_install_from_url_preview('https://github.com/owner/my-plugin/tree/0.9.0');

        $this->assertFalse(is_wp_error($result));
        $this->assertSame('0.9.0', $result['tag_used']);
        $this->assertSame('Old Plugin', $result['plugin_name']);
    }

    public function test_run_preview_returns_error_for_invalid_url(): void
    {
        $result = gitup_run_install_from_url_preview('not-a-url');

        $this->assertTrue(is_wp_error($result));
        $this->assertSame('gitup_invalid_url', $result->get_error_code());
    }

    public function test_run_preview_returns_error_when_no_releases_and_no_url_tag(): void
    {
        update_option('gitup_include_prereleases', '1');
        $repo = 'https://github.com/owner/empty';
        $this->queueRepoReleases($repo, []);

        $result = gitup_run_install_from_url_preview($repo);

        $this->assertTrue(is_wp_error($result));
        $this->assertSame('gitup_install_preview_no_releases', $result->get_error_code());
    }

    public function test_render_tab_shows_form_when_no_preview(): void
    {
        ob_start();
        gitup_render_install_from_url_tab();
        $html = ob_get_clean();

        $this->assertStringContainsString('gitup_install_from_url_preview', $html);
        $this->assertStringContainsString('gitup_install_url', $html);
        $this->assertStringContainsString('admin-post.php', $html);
    }

    public function test_can_install_returns_true_for_plugin_when_capability_present(): void
    {
        $result = gitup_install_from_url_can_install('plugin');

        $this->assertTrue($result);
    }

    public function test_can_install_returns_error_for_plugin_when_install_plugins_denied(): void
    {
        $GLOBALS['gitup_test_denied_caps'] = ['install_plugins'];

        $result = gitup_install_from_url_can_install('plugin');

        $this->assertTrue(is_wp_error($result));
        $this->assertSame('gitup_install_forbidden', $result->get_error_code());
    }

    public function test_can_install_returns_error_for_theme_when_install_themes_denied(): void
    {
        $GLOBALS['gitup_test_denied_caps'] = ['install_themes'];

        $result = gitup_install_from_url_can_install('theme');

        $this->assertTrue(is_wp_error($result));
        $this->assertSame('gitup_install_forbidden', $result->get_error_code());
    }

    public function test_can_install_any_returns_true_when_either_cap_present(): void
    {
        $GLOBALS['gitup_test_denied_caps'] = ['install_themes'];

        $result = gitup_install_from_url_can_install('any');

        $this->assertTrue($result);
    }

    public function test_can_install_any_returns_error_when_both_caps_denied(): void
    {
        $GLOBALS['gitup_test_denied_caps'] = ['install_plugins', 'install_themes'];

        $result = gitup_install_from_url_can_install('any');

        $this->assertTrue(is_wp_error($result));
        $this->assertSame('gitup_install_forbidden', $result->get_error_code());
    }

    public function test_detect_repo_component_type_distinguishes_rate_limit_from_auth(): void
    {
        $apiUrl = 'https://api.github.com/repos/owner/repo/contents?ref=1.0.0';
        gitup_test_queue_http_response($apiUrl, [
            'response' => ['code' => 403],
            'headers'  => [],
            'body'     => '{"message":"API rate limit exceeded for 1.2.3.4. (But here\'s the good news: ...)"}',
        ]);

        $result = gitup_detect_repo_component_type('https://github.com/owner/repo', '1.0.0');

        $this->assertTrue(is_wp_error($result));
        $this->assertSame('gitup_detect_rate_limited', $result->get_error_code());
    }

    public function test_detect_repo_component_type_returns_auth_for_non_rate_limit_403(): void
    {
        $apiUrl = 'https://api.github.com/repos/owner/repo/contents?ref=1.0.0';
        gitup_test_queue_http_response($apiUrl, [
            'response' => ['code' => 403],
            'headers'  => [],
            'body'     => '{"message":"Resource not accessible by integration"}',
        ]);

        $result = gitup_detect_repo_component_type('https://github.com/owner/repo', '1.0.0');

        $this->assertTrue(is_wp_error($result));
        $this->assertSame('gitup_detect_auth', $result->get_error_code());
    }

    public function test_check_update_uri_header_returns_match_for_plugin_with_correct_header(): void
    {
        $GLOBALS['gitup_test_installed_plugins']['my-plugin/my-plugin.php'] = [
            'Name'      => 'My Plugin',
            'Version'   => '1.0.0',
            'UpdateURI' => 'https://github.com/owner/my-plugin',
        ];

        $result = gitup_install_from_url_check_update_uri_header(
            'plugin',
            'my-plugin',
            'https://github.com/owner/my-plugin'
        );

        $this->assertTrue($result['matches']);
        $this->assertSame('https://github.com/owner/my-plugin', $result['detected_repo']);
    }

    public function test_check_update_uri_header_returns_no_match_for_plugin_with_missing_header(): void
    {
        $GLOBALS['gitup_test_installed_plugins']['my-plugin/my-plugin.php'] = [
            'Name'    => 'My Plugin',
            'Version' => '1.0.0',
        ];

        $result = gitup_install_from_url_check_update_uri_header(
            'plugin',
            'my-plugin',
            'https://github.com/owner/my-plugin'
        );

        $this->assertFalse($result['matches']);
        $this->assertSame('', $result['detected_repo']);
    }

    public function test_check_update_uri_header_returns_no_match_for_plugin_with_wrong_header(): void
    {
        $GLOBALS['gitup_test_installed_plugins']['my-plugin/my-plugin.php'] = [
            'Name'      => 'My Plugin',
            'Version'   => '1.0.0',
            'UpdateURI' => 'https://github.com/other/repo',
        ];

        $result = gitup_install_from_url_check_update_uri_header(
            'plugin',
            'my-plugin',
            'https://github.com/owner/my-plugin'
        );

        $this->assertFalse($result['matches']);
        $this->assertSame('https://github.com/other/repo', $result['detected_repo']);
    }

    public function test_check_update_uri_header_returns_match_for_theme_with_update_uri(): void
    {
        $GLOBALS['gitup_test_installed_themes']['my-theme'] = new GitupTestTheme([
            'UpdateURI' => 'https://github.com/owner/my-theme',
            'ThemeURI'  => '',
        ]);

        $result = gitup_install_from_url_check_update_uri_header(
            'theme',
            'my-theme',
            'https://github.com/owner/my-theme'
        );

        $this->assertTrue($result['matches']);
    }

    public function test_check_update_uri_header_returns_match_for_theme_with_only_theme_uri(): void
    {
        // gitup_get_theme_repo_url falls back to ThemeURI, so this counts as covered.
        $GLOBALS['gitup_test_installed_themes']['my-theme'] = new GitupTestTheme([
            'UpdateURI' => '',
            'ThemeURI'  => 'https://github.com/owner/my-theme',
        ]);

        $result = gitup_install_from_url_check_update_uri_header(
            'theme',
            'my-theme',
            'https://github.com/owner/my-theme'
        );

        $this->assertTrue($result['matches']);
    }

    public function test_check_update_uri_header_returns_no_match_for_theme_with_no_header(): void
    {
        $GLOBALS['gitup_test_installed_themes']['my-theme'] = new GitupTestTheme([
            'UpdateURI' => '',
            'ThemeURI'  => '',
        ]);

        $result = gitup_install_from_url_check_update_uri_header(
            'theme',
            'my-theme',
            'https://github.com/owner/my-theme'
        );

        $this->assertFalse($result['matches']);
        $this->assertSame('', $result['detected_repo']);
    }

    public function test_check_update_uri_header_returns_no_match_when_component_not_found(): void
    {
        // No plugin seeded.
        $result = gitup_install_from_url_check_update_uri_header(
            'plugin',
            'missing-plugin',
            'https://github.com/owner/missing-plugin'
        );

        $this->assertFalse($result['matches']);
        $this->assertSame('', $result['detected_repo']);
    }

    public function test_resolve_confirm_request_rejects_unknown_type(): void
    {
        $result = gitup_resolve_install_from_url_confirm_request('https://github.com/owner/repo', '1.0.0', 'banana');

        $this->assertTrue(is_wp_error($result));
        $this->assertSame('gitup_invalid_install_type', $result->get_error_code());
    }

    public function test_resolve_confirm_request_rejects_invalid_url(): void
    {
        $result = gitup_resolve_install_from_url_confirm_request('not-a-url', '1.0.0', 'plugin');

        $this->assertTrue(is_wp_error($result));
        $this->assertSame('gitup_invalid_url', $result->get_error_code());
    }

    public function test_resolve_confirm_request_rejects_empty_tag(): void
    {
        $result = gitup_resolve_install_from_url_confirm_request('https://github.com/owner/repo', '', 'plugin');

        $this->assertTrue(is_wp_error($result));
        $this->assertSame('gitup_missing_tag', $result->get_error_code());
    }

    public function test_resolve_confirm_request_returns_prepared_plugin_data(): void
    {
        $repo = 'https://github.com/owner/my-plugin';
        update_option('gitup_include_prereleases', '1');
        $this->queueRepoReleases($repo, [
            ['tag_name' => '1.0.0', 'name' => '1.0.0', 'prerelease' => false],
        ]);

        $result = gitup_resolve_install_from_url_confirm_request($repo, '1.0.0', 'plugin');

        $this->assertFalse(is_wp_error($result));
        $this->assertSame('plugin', $result['type']);
        $this->assertSame('my-plugin', $result['prepared']['desired_slug']);
        $this->assertSame(
            'https://codeload.github.com/owner/my-plugin/zip/refs/tags/1.0.0',
            $result['prepared']['package']
        );
    }

    public function test_resolve_confirm_request_returns_prepared_theme_data(): void
    {
        $repo = 'https://github.com/owner/my-theme';
        update_option('gitup_include_prereleases', '1');
        $this->queueRepoReleases($repo, [
            ['tag_name' => '2.0.0', 'name' => '2.0.0', 'prerelease' => false],
        ]);

        $result = gitup_resolve_install_from_url_confirm_request($repo, '2.0.0', 'theme');

        $this->assertFalse(is_wp_error($result));
        $this->assertSame('theme', $result['type']);
        $this->assertSame('my-theme', $result['prepared']['desired_stylesheet']);
    }

    public function test_resolve_confirm_request_propagates_unverified_tag_error(): void
    {
        $repo = 'https://github.com/owner/my-plugin';
        update_option('gitup_include_prereleases', '1');
        $this->queueRepoReleases($repo, [
            ['tag_name' => '1.0.0', 'name' => '1.0.0', 'prerelease' => false],
        ]);

        $result = gitup_resolve_install_from_url_confirm_request($repo, '9.9.9', 'plugin');

        $this->assertTrue(is_wp_error($result));
        $this->assertSame('gitup_install_release_not_verified', $result->get_error_code());
    }

    public function test_render_tab_shows_install_buttons_for_plugin_preview(): void
    {
        set_transient(gitup_install_from_url_preview_transient_key(), [
            'url_input'   => 'owner/repo',
            'repo_url'    => 'https://github.com/owner/repo',
            'tag_used'    => '1.0.0',
            'releases'    => [['tag' => '1.0.0', 'name' => '1.0.0', 'prerelease' => false]],
            'type'        => 'plugin',
            'plugin_name' => 'X',
            'theme_name'  => null,
        ]);

        ob_start();
        gitup_render_install_from_url_tab();
        $html = ob_get_clean();

        $this->assertStringContainsString('gitup_install_from_url_confirm', $html);
        $this->assertStringContainsString('Install as plugin', $html);
        // No theme button when type is plugin only.
        if (strpos($html, 'Install as theme') !== false) {
            $this->fail('Did not expect theme install button when type is plugin.');
        }
    }

    public function test_render_tab_shows_both_install_buttons_for_both_preview(): void
    {
        set_transient(gitup_install_from_url_preview_transient_key(), [
            'url_input'   => 'owner/repo',
            'repo_url'    => 'https://github.com/owner/repo',
            'tag_used'    => '1.0.0',
            'releases'    => [],
            'type'        => 'both',
            'plugin_name' => 'X',
            'theme_name'  => 'Y',
        ]);

        ob_start();
        gitup_render_install_from_url_tab();
        $html = ob_get_clean();

        $this->assertStringContainsString('Install as plugin', $html);
        $this->assertStringContainsString('Install as theme', $html);
    }

    public function test_render_tab_omits_install_buttons_for_none_preview(): void
    {
        set_transient(gitup_install_from_url_preview_transient_key(), [
            'url_input'   => 'owner/repo',
            'repo_url'    => 'https://github.com/owner/repo',
            'tag_used'    => '1.0.0',
            'releases'    => [],
            'type'        => 'none',
            'plugin_name' => null,
            'theme_name'  => null,
        ]);

        ob_start();
        gitup_render_install_from_url_tab();
        $html = ob_get_clean();

        if (strpos($html, 'Install as plugin') !== false || strpos($html, 'Install as theme') !== false) {
            $this->fail('Did not expect install buttons when type is none.');
        }
    }

    public function test_render_tab_repopulates_url_field_when_preview_transient_has_url_input(): void
    {
        set_transient(gitup_install_from_url_preview_transient_key(), [
            'url_input' => 'https://github.com/owner/some-plugin',
            'repo_url'  => 'https://github.com/owner/some-plugin',
            'tag_used'  => '1.0.0',
            'releases'  => [],
            'type'      => 'plugin',
            'plugin_name' => 'Some Plugin',
            'theme_name'  => null,
        ]);

        ob_start();
        gitup_render_install_from_url_tab();
        $html = ob_get_clean();

        $this->assertStringContainsString('value="https://github.com/owner/some-plugin"', $html);
    }

    public function test_render_tab_shows_inspection_result_when_preview_transient_set(): void
    {
        set_transient(gitup_install_from_url_preview_transient_key(), [
            'repo_url'    => 'https://github.com/owner/my-plugin',
            'tag_used'    => '1.0.0',
            'releases'    => [['tag' => '1.0.0', 'name' => '1.0.0', 'prerelease' => false]],
            'type'        => 'plugin',
            'plugin_name' => 'My Plugin',
            'theme_name'  => null,
        ]);

        ob_start();
        gitup_render_install_from_url_tab();
        $html = ob_get_clean();

        $this->assertStringContainsString('https://github.com/owner/my-plugin', $html);
        $this->assertStringContainsString('1.0.0', $html);
        $this->assertStringContainsString('My Plugin', $html);
    }

    private function queueContentsListing(string $ownerRepo, string $tag, array $files): void
    {
        $url = 'https://api.github.com/repos/' . $ownerRepo . '/contents?ref=' . rawurlencode($tag);
        gitup_test_queue_http_response($url, [
            'response' => ['code' => 200],
            'headers'  => [],
            'body'     => json_encode($files),
        ]);
    }

    private function queueFileContent(string $ownerRepo, string $filePath, string $tag, string $content): void
    {
        $url = 'https://api.github.com/repos/' . $ownerRepo . '/contents/' . $filePath . '?ref=' . rawurlencode($tag);
        gitup_test_queue_http_response($url, [
            'response' => ['code' => 200],
            'headers'  => [],
            'body'     => json_encode([
                'type'     => 'file',
                'encoding' => 'base64',
                'content'  => chunk_split(base64_encode($content), 60, "\n"),
            ]),
        ]);
    }

    private function queueRepoReleases(string $repoUrl, array $releases): void
    {
        $apiUrl = 'https://api.github.com/repos/' . trim((string) parse_url($repoUrl, PHP_URL_PATH), '/') . '/releases?per_page=50';
        gitup_test_queue_http_response($apiUrl, [
            'response' => ['code' => 200],
            'headers'  => [],
            'body'     => json_encode($releases),
        ]);
    }
}
