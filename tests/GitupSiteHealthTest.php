<?php

declare(strict_types=1);

final class GitupSiteHealthTest extends GitupTestCase
{
    public function test_token_summary_reports_missing_token_as_recommended(): void
    {
        $summary = gitup_get_site_health_token_summary();

        $this->assertSame('missing', $summary['token_state']);
        $this->assertSame('recommended', $summary['site_status']);
        $this->assertSame('blue', $summary['badge_color']);
    }

    public function test_token_summary_reports_recent_valid_token_as_good(): void
    {
        update_option('gitup_github_token', 'secret');
        update_option('gitup_token_last_verified', time() - HOUR_IN_SECONDS);
        update_option('gitup_token_status', [
            'status' => 'valid',
            'last_checked' => time(),
        ]);

        $summary = gitup_get_site_health_token_summary();

        $this->assertSame('valid', $summary['token_state']);
        $this->assertSame('good', $summary['site_status']);
        $this->assertTrue($summary['token_ok']);
    }

    public function test_release_summary_reads_cached_release_status(): void
    {
        $repo = 'https://github.com/Ratt-Grafiska/gitup';
        $cacheKey = 'github_release_' . md5($repo . '|stable');

        $GLOBALS['gitup_test_github_plugins'] = [
            ['name' => 'GitUp', 'github' => $repo],
        ];
        set_transient($cacheKey, '2026.03.23.01', HOUR_IN_SECONDS);

        $summary = gitup_get_site_health_release_summary();

        $this->assertSame('2026.03.23.01', $summary['status']);
        $this->assertStringContainsString('GitUp', $summary['label']);
    }

    public function test_health_test_marks_rate_limit_as_recommended_when_token_is_otherwise_good(): void
    {
        $repo = 'https://github.com/Ratt-Grafiska/gitup';
        $cacheKey = 'github_release_' . md5($repo . '|stable');

        update_option('gitup_github_token', 'secret');
        update_option('gitup_token_last_verified', time() - HOUR_IN_SECONDS);
        update_option('gitup_token_status', [
            'status' => 'valid',
            'last_checked' => time(),
        ]);
        $GLOBALS['gitup_test_github_plugins'] = [
            ['name' => 'GitUp', 'github' => $repo],
        ];
        set_transient($cacheKey, [['error' => 'rate_limit']], 5 * MINUTE_IN_SECONDS);

        $result = gitup_health_test();

        $this->assertSame('recommended', $result['status']);
        $this->assertStringContainsString('rate limit', strtolower($result['description']));
    }
}
