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
}
