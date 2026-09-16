<?php

declare(strict_types=1);

final class GitupSecurityRemediationTest extends GitupTestCase
{
    private array $tempFiles = [];

    public function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->tempFiles = [];
    }

    public function test_github_request_classifier_accepts_only_owned_api_and_package_urls(): void
    {
        $valid = [
            'https://api.github.com/user' => 'api',
            'https://api.github.com/repos/owner/repo/releases/latest' => 'api',
            'https://API.GITHUB.COM/repos/owner/repo/tags?per_page=10' => 'api',
            'https://codeload.github.com/owner/repo/zip/refs/tags/1.0.0' => 'package',
        ];

        foreach ($valid as $url => $expected) {
            $this->assertSame($expected, gitup_classify_github_request_url($url), $url);
        }
    }

    public function test_github_request_classifier_rejects_lookalikes_and_unsafe_urls(): void
    {
        $invalid = [
            'http://api.github.com/repos/owner/repo/releases',
            'https://api.github.com.evil.test/repos/owner/repo/releases',
            'https://evil.test/?next=https://api.github.com/repos/owner/repo',
            'https://api.github.com@evil.test/repos/owner/repo/releases',
            'https://user@api.github.com/repos/owner/repo/releases',
            'https://api.github.com:8443/repos/owner/repo/releases',
            'https://github.com/owner/repo/archive.zip',
            'https://codeload.github.com/owner/repo/releases/latest',
            'https://codeload.github.com/owner/repo/tar.gz/refs/tags/1.0.0',
            'https://codeload.github.com/owner/repo/zip/../tar.gz/refs/tags/1.0.0',
            'https://codeload.github.com/owner/repo/zip/refs/tags/1.0.0/extra',
            'https://codeload.github.com/owner%2Frepo/name/zip/refs/tags/1.0.0',
            'https://codeload.github.com/owner/repo/zip/refs/tags/..',
            'https://codeload.github.com/owner/repo/zip/refs/tags/%2E%2E%2Ftar.gz',
            'not a url',
        ];

        foreach ($invalid as $url) {
            $this->assertSame('', gitup_classify_github_request_url($url), $url);
        }
    }

    public function test_token_is_not_added_to_attacker_controlled_download_url(): void
    {
        update_option('gitup_github_token', 'test-token');
        $url = 'https://evil.test/download?next=codeload.github.com/owner/repo/zip/refs/tags/1.0.0';

        $args = apply_filters('http_request_args', ['headers' => ['X-Test' => '1']], $url);
        $reply = apply_filters('upgrader_pre_download', 'continue-default', $url, new stdClass());

        $this->assertSame(['X-Test' => '1'], $args['headers']);
        $this->assertSame('continue-default', $reply);
        $this->assertSame(0, gitup_test_http_call_count($url));
    }

    public function test_approved_codeload_zip_uses_explicit_headers_without_redirects(): void
    {
        update_option('gitup_github_token', 'test-token');
        $url = gitup_build_github_package_url('https://github.com/owner/repo', '1.0.0');
        gitup_test_queue_http_response($url, [
            'response' => ['code' => 200],
            'headers' => ['content-type' => 'application/zip'],
            'body' => "PK\x03\x04mock-zip",
        ]);

        $result = apply_filters('upgrader_pre_download', false, $url, new stdClass());
        if (is_string($result)) {
            $this->tempFiles[] = $result;
        }

        $this->assertTrue(is_string($result));
        $this->assertFileExists($result);
        $call = $GLOBALS['gitup_test_http_calls'][0];
        $this->assertSame('Bearer test-token', $call['args']['headers']['Authorization']);
        $this->assertSame('application/octet-stream', $call['args']['headers']['Accept']);
        $this->assertSame(0, $call['args']['redirection']);
        $this->assertTrue($call['args']['gitup_request']);
    }

    public function test_invalid_package_payload_is_rejected_and_deleted(): void
    {
        $url = gitup_build_github_package_url('https://github.com/owner/repo', 'broken');
        gitup_test_queue_http_response($url, [
            'response' => ['code' => 200],
            'headers' => ['content-type' => 'text/html'],
            'body' => '<html>not a zip</html>',
        ]);

        $result = apply_filters('upgrader_pre_download', false, $url, new stdClass());

        $this->assertTrue(is_wp_error($result));
        $this->assertSame('download_failed', $result->get_error_code());
    }

    public function test_unregistered_codeload_package_for_unrelated_component_is_ignored(): void
    {
        update_option('gitup_github_token', 'test-token');
        $url = 'https://codeload.github.com/unrelated/plugin/zip/refs/tags/1.0.0';

        $result = apply_filters('upgrader_pre_download', 'continue-default', $url, new stdClass());

        $this->assertSame('continue-default', $result);
        $this->assertSame(0, gitup_test_http_call_count($url));
    }

    public function test_existing_pre_download_reply_is_preserved(): void
    {
        $url = gitup_build_github_package_url('https://github.com/owner/repo', '1.0.0');

        $result = apply_filters('upgrader_pre_download', '/tmp/cached-package.zip', $url, new stdClass());

        $this->assertSame('/tmp/cached-package.zip', $result);
        $this->assertSame(0, gitup_test_http_call_count($url));
    }

    public function test_cached_update_package_for_managed_plugin_is_authorized(): void
    {
        $GLOBALS['gitup_test_installed_plugins']['managed/managed.php'] = [
            'Name' => 'Managed Plugin',
            'Version' => '1.0.0',
            'UpdateURI' => 'https://github.com/owner/managed',
        ];
        $url = 'https://codeload.github.com/owner/managed/zip/refs/tags/1.1.0';
        $GLOBALS['gitup_test_site_transients']['update_plugins'] = (object) [
            'response' => [
                'managed/managed.php' => (object) ['package' => $url],
            ],
        ];

        $this->assertTrue(gitup_is_authorized_package_url($url));
    }

    public function test_same_repo_package_not_present_in_current_update_is_ignored(): void
    {
        $GLOBALS['gitup_test_installed_plugins']['managed/managed.php'] = [
            'Name' => 'Managed Plugin',
            'Version' => '1.0.0',
            'UpdateURI' => 'https://github.com/owner/managed',
        ];
        $url = 'https://codeload.github.com/owner/managed/zip/refs/tags/unrelated';

        $this->assertFalse(gitup_is_authorized_package_url($url));
    }

    public function test_plugin_directory_rejects_root_and_traversal_layouts(): void
    {
        $this->assertSame('gitup', gitup_get_plugin_directory('gitup/index.php'));
        $this->assertSame('vendor/gitup', gitup_get_plugin_directory('vendor/gitup/index.php'));
        $this->assertSame('', gitup_get_plugin_directory('single.php'));
        $this->assertSame('', gitup_get_plugin_directory('./single.php'));
        $this->assertSame('', gitup_get_plugin_directory('../single.php'));
        $this->assertSame('', gitup_get_plugin_directory('gitup/../single.php'));
        $this->assertSame('', gitup_get_plugin_directory('/gitup/index.php'));
        $this->assertSame('', gitup_get_plugin_directory('gitup//index.php'));
        $this->assertSame('', gitup_get_plugin_directory('gitup\\..\\single.php'));
    }

    public function test_package_options_never_target_plugins_root_for_single_file_plugin(): void
    {
        $original = [
            'destination' => '/safe/original/',
            'clear_destination' => false,
            'abort_if_destination_exists' => true,
        ];

        $manual = gitup_build_plugin_package_options_filter('single.php');
        $manualResult = $manual($original);
        $globalResult = apply_filters(
            'upgrader_package_options',
            $original + ['hook_extra' => ['plugin' => 'single.php']]
        );

        $this->assertSame('/safe/original/', $manualResult['destination']);
        $this->assertFalse($manualResult['clear_destination']);
        $this->assertSame('/safe/original/', $globalResult['destination']);
        $this->assertFalse($globalResult['clear_destination']);
    }

    public function test_manual_update_rejects_single_file_plugin_before_network_access(): void
    {
        $GLOBALS['gitup_test_installed_plugins']['single.php'] = [
            'Name' => 'Single File Plugin',
            'Version' => '1.0.0',
            'UpdateURI' => 'https://github.com/owner/repo',
        ];

        $result = gitup_prepare_plugin_release_install('single.php', '1.1.0');

        $this->assertTrue(is_wp_error($result));
        $this->assertSame('gitup_plugin_layout_unsupported', $result->get_error_code());
        $this->assertSame([], $GLOBALS['gitup_test_http_calls']);
    }

    public function test_theme_uri_alone_is_not_an_update_source(): void
    {
        $theme = new GitupTestTheme([
            'UpdateURI' => '',
            'ThemeURI' => 'https://github.com/owner/demo-page',
        ]);

        $this->assertSame('', gitup_get_theme_repo_url($theme));
    }

    public function test_blank_token_submission_preserves_existing_secret_and_clear_is_explicit(): void
    {
        update_option('gitup_github_token', 'existing-test-token');

        $this->assertSame('existing-test-token', gitup_sanitize_github_token(''));
        $this->assertSame('replacement-token', gitup_sanitize_github_token(' replacement-token '));

        $_POST['gitup_remove_github_token'] = '1';
        $this->assertSame('', gitup_sanitize_github_token(''));
    }
}
