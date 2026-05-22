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
            'body'     => $content,
        ]);
    }
}
