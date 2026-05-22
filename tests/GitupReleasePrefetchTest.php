<?php

declare(strict_types=1);

final class GitupReleasePrefetchTest extends GitupTestCase
{
    public function test_cache_key_is_stable_for_same_inputs(): void
    {
        $a = gitup_releases_cache_key('https://github.com/owner/repo', false, 20);
        $b = gitup_releases_cache_key('https://github.com/owner/repo', false, 20);

        $this->assertSame($a, $b);
    }

    public function test_cache_key_distinguishes_prerelease_mode(): void
    {
        $stable = gitup_releases_cache_key('https://github.com/owner/repo', false, 20);
        $pre    = gitup_releases_cache_key('https://github.com/owner/repo', true, 20);

        $this->assertFalse($stable === $pre);
    }

    public function test_cache_key_distinguishes_limit(): void
    {
        $small = gitup_releases_cache_key('https://github.com/owner/repo', false, 10);
        $large = gitup_releases_cache_key('https://github.com/owner/repo', false, 50);

        $this->assertFalse($small === $large);
    }

    public function test_cache_key_matches_existing_releases_data_formula(): void
    {
        // The cache key must match the one in gitup_get_github_releases_data so that
        // entries written by the prefetch worker are visible to the existing readers.
        $expected = 'github_releases_' . md5('https://github.com/owner/repo' . '|stable|20');
        $actual   = gitup_releases_cache_key('https://github.com/owner/repo', false, 20);

        $this->assertSame($expected, $actual);
    }

    public function test_get_cached_releases_returns_null_on_miss(): void
    {
        $result = gitup_get_cached_releases('https://github.com/owner/missing', false, 20);

        $this->assertSame(null, $result);
    }

    public function test_get_cached_releases_returns_array_on_hit(): void
    {
        $releases = [
            ['tag' => '1.0.0', 'name' => '1.0.0', 'prerelease' => false],
        ];
        set_transient(
            gitup_releases_cache_key('https://github.com/owner/repo', false, 20),
            $releases
        );

        $result = gitup_get_cached_releases('https://github.com/owner/repo', false, 20);

        $this->assertSame($releases, $result);
    }

    public function test_get_tracked_repos_lists_plugins_with_update_uri(): void
    {
        $GLOBALS['gitup_test_installed_plugins']['gitup/index.php'] = [
            'Name'      => 'GitUp',
            'Version'   => '2026.05.22.03-beta',
            'Author'    => 'Johan',
            'UpdateURI' => 'https://github.com/johanwistbacka/gitup',
        ];

        $result = gitup_get_tracked_repos();

        $this->assertCount(1, $result);
        $this->assertSame('plugin', $result[0]['type']);
        $this->assertSame('https://github.com/johanwistbacka/gitup', $result[0]['github']);
        $this->assertSame('gitup/index.php', $result[0]['slug']);
        $this->assertSame('GitUp', $result[0]['name']);
    }

    public function test_get_tracked_repos_lists_themes_with_update_uri(): void
    {
        $GLOBALS['gitup_test_installed_themes']['my-theme'] = new GitupTestTheme([
            'Name'      => 'My Theme',
            'Version'   => '2.0.0',
            'Author'    => 'Johan',
            'UpdateURI' => 'https://github.com/johan/my-theme',
            'ThemeURI'  => '',
        ]);

        $result = gitup_get_tracked_repos();

        $this->assertCount(1, $result);
        $this->assertSame('theme', $result[0]['type']);
        $this->assertSame('https://github.com/johan/my-theme', $result[0]['github']);
        $this->assertSame('my-theme', $result[0]['slug']);
    }

    public function test_get_tracked_repos_skips_components_without_update_uri(): void
    {
        $GLOBALS['gitup_test_installed_plugins']['no-uri/no-uri.php'] = [
            'Name'    => 'No URI Plugin',
            'Version' => '1.0.0',
        ];
        $GLOBALS['gitup_test_installed_themes']['no-uri-theme'] = new GitupTestTheme([
            'Name'      => 'No URI Theme',
            'UpdateURI' => '',
            'ThemeURI'  => '',
        ]);

        $result = gitup_get_tracked_repos();

        $this->assertCount(0, $result);
    }

    public function test_get_tracked_repos_makes_no_http_calls(): void
    {
        $GLOBALS['gitup_test_installed_plugins']['gitup/index.php'] = [
            'Name'      => 'GitUp',
            'Version'   => '2026.05.22.03-beta',
            'UpdateURI' => 'https://github.com/johanwistbacka/gitup',
        ];

        gitup_get_tracked_repos();

        $this->assertCount(0, $GLOBALS['gitup_test_http_calls']);
    }
}
