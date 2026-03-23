<?php

declare(strict_types=1);

final class GitupHelpersTest extends GitupTestCase
{
    public function test_normalize_version_tag_removes_leading_v(): void
    {
        $this->assertSame('1.2.3', gitup_normalize_version_tag(' v1.2.3 '));
        $this->assertSame('2.0.0', gitup_normalize_version_tag('V2.0.0'));
    }

    public function test_compare_version_tags_ignores_leading_v(): void
    {
        $this->assertSame(0, gitup_compare_version_tags('v1.2.3', '1.2.3'));
        $this->assertSame(1, gitup_compare_version_tags('v2.0.0', '1.9.9'));
    }

    public function test_normalize_github_repo_url_accepts_shorthand_and_strips_trailing_slash(): void
    {
        $normalized = gitup_normalize_github_repo_url('Ratt-Grafiska/gitup/');
        $this->assertSame('https://github.com/Ratt-Grafiska/gitup', $normalized);
    }

    public function test_normalize_github_repo_url_rejects_non_github_hosts(): void
    {
        $this->assertSame('', gitup_normalize_github_repo_url('https://notgithub.com/Ratt-Grafiska/gitup'));
    }

    public function test_build_github_package_url_encodes_special_characters_in_tag(): void
    {
        $url = gitup_build_github_package_url('https://github.com/Ratt-Grafiska/gitup', 'release/2026 03');
        $this->assertSame(
            'https://codeload.github.com/Ratt-Grafiska/gitup/zip/refs/tags/release%2F2026%2003',
            $url
        );
    }

    public function test_get_plugin_repo_url_uses_update_uri(): void
    {
        $plugin = ['UpdateURI' => 'https://github.com/Ratt-Grafiska/gitup'];
        $this->assertSame('https://github.com/Ratt-Grafiska/gitup', gitup_get_plugin_repo_url($plugin));
    }
}
