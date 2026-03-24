<?php

declare(strict_types=1);

final class GitupReleaseFetchingTest extends GitupTestCase
{
    public function test_release_fetching_caches_latest_release_tag(): void
    {
        $repo = 'https://github.com/Ratt-Grafiska/gitup';
        $latestUrl = 'https://api.github.com/repos/Ratt-Grafiska/gitup/releases/latest';

        gitup_test_queue_http_response($latestUrl, [
            'response' => ['code' => 200],
            'headers' => [],
            'body' => json_encode([
                'tag_name' => 'v1.2.3',
                'name' => 'v1.2.3',
                'body' => 'Release',
            ]),
        ]);

        $first = gitup_get_latest_github_release_tag($repo, true);
        $second = gitup_get_latest_github_release_tag($repo, false);

        $this->assertSame('v1.2.3', $first);
        $this->assertSame('v1.2.3', $second);
        $this->assertSame(1, gitup_test_http_call_count($latestUrl));
    }

    public function test_release_fetching_returns_rate_limit_payload_on_403(): void
    {
        $repo = 'https://github.com/Ratt-Grafiska/gitup';
        $latestUrl = 'https://api.github.com/repos/Ratt-Grafiska/gitup/releases/latest';

        gitup_test_queue_http_response($latestUrl, [
            'response' => ['code' => 403],
            'headers' => [],
            'body' => json_encode(['message' => 'rate limited']),
        ]);

        $releases = gitup_get_github_releases_data($repo, false, 1);

        $this->assertCount(1, $releases);
        $this->assertSame('rate_limit', $releases[0]['error'] ?? '');
    }

    public function test_release_fetching_falls_back_to_tags_and_skips_prerelease_like_tags_when_disabled(): void
    {
        $repo = 'https://github.com/Ratt-Grafiska/gitup';
        $latestUrl = 'https://api.github.com/repos/Ratt-Grafiska/gitup/releases/latest';
        $tagsUrl = 'https://api.github.com/repos/Ratt-Grafiska/gitup/tags?per_page=10';

        gitup_test_queue_http_response($latestUrl, [
            'response' => ['code' => 404],
            'headers' => [],
            'body' => json_encode(['message' => 'not found']),
        ]);

        gitup_test_queue_http_response($tagsUrl, [
            'response' => ['code' => 200],
            'headers' => [],
            'body' => json_encode([
                ['name' => 'v1.0.0', 'commit' => ['sha' => 'aaa111']],
                ['name' => 'v2.0.0-beta1', 'commit' => ['sha' => 'bbb222']],
                ['name' => 'v1.5.0', 'commit' => ['sha' => 'ccc333']],
            ]),
        ]);

        $releases = gitup_get_github_releases_data($repo, false, 10);

        $this->assertCount(2, $releases);
        $this->assertSame('1.5.0', $releases[0]['tag_name']);
        $this->assertSame('1.0.0', $releases[1]['tag_name']);
        $this->assertStringContainsString('/commit/ccc333', $releases[0]['html_url']);
    }

    public function test_release_fetching_with_prereleases_skips_drafts_and_respects_limit(): void
    {
        $repo = 'https://github.com/Ratt-Grafiska/gitup';
        $releasesUrl = 'https://api.github.com/repos/Ratt-Grafiska/gitup/releases?per_page=10';

        gitup_test_queue_http_response($releasesUrl, [
            'response' => ['code' => 200],
            'headers' => [],
            'body' => json_encode([
                ['tag_name' => 'v3.0.0-beta1', 'name' => 'Beta 1', 'prerelease' => true, 'draft' => false],
                ['tag_name' => 'v3.0.0-rc1', 'name' => 'RC 1', 'prerelease' => true, 'draft' => true],
                ['tag_name' => 'v2.5.0', 'name' => '2.5.0', 'prerelease' => false, 'draft' => false],
            ]),
        ]);

        $releases = gitup_get_github_releases_data($repo, true, 2);

        $this->assertCount(2, $releases);
        $this->assertSame('v3.0.0-beta1', $releases[0]['tag_name']);
        $this->assertTrue($releases[0]['prerelease']);
        $this->assertSame('v2.5.0', $releases[1]['tag_name']);
    }

    public function test_release_fetching_falls_back_to_tags_when_latest_payload_lacks_tag_name(): void
    {
        $repo = 'https://github.com/Ratt-Grafiska/gitup';
        $latestUrl = 'https://api.github.com/repos/Ratt-Grafiska/gitup/releases/latest';
        $tagsUrl = 'https://api.github.com/repos/Ratt-Grafiska/gitup/tags?per_page=10';

        gitup_test_queue_http_response($latestUrl, [
            'response' => ['code' => 200],
            'headers' => [],
            'body' => json_encode([
                'name' => 'Broken latest payload',
            ]),
        ]);

        gitup_test_queue_http_response($tagsUrl, [
            'response' => ['code' => 200],
            'headers' => [],
            'body' => json_encode([
                ['name' => 'v1.4.0', 'commit' => ['sha' => 'ddd444']],
            ]),
        ]);

        $first = gitup_get_github_releases_data($repo, false, 10);
        $second = gitup_get_github_releases_data($repo, false, 10);

        $this->assertCount(1, $first);
        $this->assertSame('1.4.0', $first[0]['tag_name']);
        $this->assertSame('1.4.0', $second[0]['tag_name']);
        $this->assertSame(1, gitup_test_http_call_count($latestUrl));
        $this->assertSame(1, gitup_test_http_call_count($tagsUrl));
    }

    public function test_release_fetching_caches_empty_array_after_http_error(): void
    {
        $repo = 'https://github.com/Ratt-Grafiska/gitup';
        $latestUrl = 'https://api.github.com/repos/Ratt-Grafiska/gitup/releases/latest';

        gitup_test_queue_http_response($latestUrl, new WP_Error('http_failure', 'Connection failed'));

        $first = gitup_get_github_releases_data($repo, false, 10);
        $second = gitup_get_github_releases_data($repo, false, 10);

        $this->assertSame([], $first);
        $this->assertSame([], $second);
        $this->assertSame(1, gitup_test_http_call_count($latestUrl));
    }
}
