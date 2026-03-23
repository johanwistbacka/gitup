<?php

declare(strict_types=1);

final class GitupAdminStatusTest extends GitupTestCase
{
    public function test_repo_visibility_marks_401_as_private(): void
    {
        $repo = 'https://github.com/Ratt-Grafiska/private-repo';
        $apiUrl = 'https://api.github.com/repos/Ratt-Grafiska/private-repo';

        gitup_test_queue_http_response($apiUrl, [
            'response' => ['code' => 401],
            'headers' => [],
            'body' => json_encode(['message' => 'Requires authentication']),
        ]);

        $visibility = gitup_repo_visibility($repo);

        $this->assertSame('private', $visibility);
    }

    public function test_release_empty_state_shows_private_repo_message_for_invalid_token(): void
    {
        $apiUrl = 'https://api.github.com/repos/Ratt-Grafiska/private-repo';

        update_option('gitup_github_token', 'secret');
        update_option('gitup_token_status', [
            'status' => 'invalid',
            'last_checked' => time(),
        ]);
        update_option('gitup_token_last_updated', time());

        gitup_test_queue_http_response($apiUrl, [
            'response' => ['code' => 401],
            'headers' => [],
            'body' => json_encode(['message' => 'Requires authentication']),
        ]);

        $message = gitup_get_release_empty_state_message(
            'https://github.com/Ratt-Grafiska/private-repo',
            []
        );

        $this->assertSame('Private repo / 404. Update token.', $message);
    }

    public function test_release_empty_state_shows_verify_token_message_for_unknown_private_repo(): void
    {
        $apiUrl = 'https://api.github.com/repos/Ratt-Grafiska/private-repo';

        update_option('gitup_github_token', 'secret');

        gitup_test_queue_http_response($apiUrl, [
            'response' => ['code' => 404],
            'headers' => [],
            'body' => json_encode(['message' => 'Not Found']),
        ]);

        $message = gitup_get_release_empty_state_message(
            'https://github.com/Ratt-Grafiska/private-repo',
            []
        );

        $this->assertSame('Private repo. Verify token.', $message);
    }

    public function test_release_empty_state_prefers_rate_limit_message(): void
    {
        $message = gitup_get_release_empty_state_message(
            'https://github.com/Ratt-Grafiska/gitup',
            [['error' => 'rate_limit']]
        );

        $this->assertSame('GitHub API rate limit exceeded. Add a token.', $message);
    }

    public function test_fetch_releases_preserves_401_error_payload(): void
    {
        $repo = 'https://github.com/Ratt-Grafiska/private-repo';
        $latestUrl = 'https://api.github.com/repos/Ratt-Grafiska/private-repo/releases/latest';
        $tagsUrl = 'https://api.github.com/repos/Ratt-Grafiska/private-repo/tags?per_page=10';

        gitup_test_queue_http_response($latestUrl, [
            'response' => ['code' => 401],
            'headers' => [],
            'body' => json_encode(['message' => 'Requires authentication']),
        ]);

        gitup_test_queue_http_response($tagsUrl, [
            'response' => ['code' => 401],
            'headers' => [],
            'body' => json_encode(['message' => 'Requires authentication']),
        ]);

        $releases = gitup_fetch_releases($repo, false, 10);

        $this->assertCount(0, $releases);
    }
}
