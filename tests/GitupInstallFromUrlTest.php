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
}
