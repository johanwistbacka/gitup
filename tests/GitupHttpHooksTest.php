<?php

declare(strict_types=1);

final class GitupHttpHooksTest extends GitupTestCase
{
    public function test_http_request_args_adds_github_headers_for_api_requests(): void
    {
        update_option('gitup_github_token', 'secret-token');

        $args = apply_filters('http_request_args', ['headers' => []], 'https://api.github.com/repos/Ratt-Grafiska/gitup/releases/latest');

        $this->assertSame('WordPress Plugin', $args['headers']['User-Agent']);
        $this->assertSame('Bearer secret-token', $args['headers']['Authorization']);
        $this->assertSame('application/vnd.github+json', $args['headers']['Accept']);
    }

    public function test_http_request_args_leaves_non_github_requests_unchanged(): void
    {
        $args = apply_filters('http_request_args', ['headers' => ['X-Test' => '1']], 'https://example.test/archive.zip');

        $this->assertSame(['X-Test' => '1'], $args['headers']);
    }

    public function test_http_response_marks_token_as_valid_on_200(): void
    {
        update_option('gitup_github_token', 'secret-token');

        apply_filters('http_response', [
            'response' => ['code' => 200],
            'headers' => [],
            'body' => '{}',
        ], [], 'https://api.github.com/repos/Ratt-Grafiska/gitup/releases/latest');

        $status = get_option('gitup_token_status');
        $this->assertSame('valid', $status['status']);
        $this->assertSame('https://api.github.com/repos/Ratt-Grafiska/gitup/releases/latest', $status['url']);
        $this->assertTrue((int) get_option('gitup_token_last_verified') > 0);
    }

    public function test_http_response_marks_token_as_invalid_on_401_without_sending_mail_when_no_admin_email(): void
    {
        update_option('gitup_github_token', 'secret-token');

        apply_filters('http_response', [
            'response' => ['code' => 401],
            'headers' => [],
            'body' => '{}',
        ], [], 'https://api.github.com/repos/Ratt-Grafiska/gitup/releases/latest');

        $status = get_option('gitup_token_status');
        $this->assertSame('invalid', $status['status']);
        $this->assertSame([], $GLOBALS['gitup_test_sent_mail']);
    }
}
