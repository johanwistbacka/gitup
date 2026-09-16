<?php

declare(strict_types=1);

final class GitupHttpHooksTest extends GitupTestCase
{
    public function test_github_headers_include_saved_token_for_explicit_gitup_requests(): void
    {
        update_option('gitup_github_token', 'secret-token');

        $headers = gitup_github_headers();

        $this->assertSame('WordPress Plugin', $headers['User-Agent']);
        $this->assertSame('Bearer secret-token', $headers['Authorization']);
        $this->assertSame('application/vnd.github+json', $headers['Accept']);
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
    ], [
        'gitup_request' => true,
        'headers' => ['authorization' => 'Bearer secret-token'],
    ], 'https://api.github.com/repos/Ratt-Grafiska/gitup/releases/latest');

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
    ], [
        'gitup_request' => true,
        'headers' => ['Authorization' => 'Bearer secret-token'],
    ], 'https://api.github.com/repos/Ratt-Grafiska/gitup/releases/latest');

        $status = get_option('gitup_token_status');
    $this->assertSame('invalid', $status['status']);
    $this->assertSame([], $GLOBALS['gitup_test_sent_mail']);
}

public function test_http_response_does_not_validate_token_from_unauthenticated_200(): void
{
    update_option('gitup_github_token', 'secret-token');
    $existing_status = [
        'status' => 'invalid',
        'last_checked' => 123,
        'url' => 'https://api.github.com/repos/Ratt-Grafiska/gitup/releases/latest',
    ];
    update_option('gitup_token_status', $existing_status);
    update_option('gitup_token_last_verified', 123);

    apply_filters('http_response', [
        'response' => ['code' => 200],
        'headers' => [],
        'body' => '{}',
    ], ['gitup_request' => true], 'https://api.github.com/repos/Ratt-Grafiska/gitup/releases/latest');

    $this->assertSame($existing_status, get_option('gitup_token_status'));
    $this->assertSame(123, get_option('gitup_token_last_verified'));
}

    public function test_http_response_ignores_other_components_github_requests(): void
    {
        update_option('gitup_github_token', 'secret-token');

        apply_filters('http_response', [
            'response' => ['code' => 401],
            'headers' => [],
            'body' => '{}',
        ], [], 'https://api.github.com/repos/other/plugin/releases/latest');

        $this->assertFalse(get_option('gitup_token_status'));
    }
}
