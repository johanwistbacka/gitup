<?php

declare(strict_types=1);

final class GitupUpdateChecksTest extends GitupTestCase
{
    public function test_plugin_update_check_adds_response_for_newer_github_release(): void
    {
        $repo = 'https://github.com/Ratt-Grafiska/test-plugin';
        $latestUrl = 'https://api.github.com/repos/Ratt-Grafiska/test-plugin/releases/latest';
        $GLOBALS['gitup_test_installed_plugins']['test-plugin/test-plugin.php'] = [
            'Name' => 'Test Plugin',
            'Version' => '1.0.0',
            'Author' => 'Ratt Grafiska',
            'Description' => 'Plugin description',
            'UpdateURI' => $repo,
        ];

        gitup_test_queue_http_response($latestUrl, [
            'response' => ['code' => 200],
            'headers' => [],
            'body' => json_encode([
                'tag_name' => '1.2.0',
                'name' => '1.2.0',
                'body' => 'Changelog',
                'html_url' => $repo . '/releases/tag/1.2.0',
            ]),
        ]);

        $transient = (object) ['checked' => ['test-plugin/test-plugin.php' => '1.0.0']];
        $result = GitUpUpdater::get_instance()->check_for_update($transient);

        $this->assertSame(
            'https://codeload.github.com/Ratt-Grafiska/test-plugin/zip/refs/tags/1.2.0',
            $result->response['test-plugin/test-plugin.php']->package
        );
        $this->assertSame('1.2.0', $result->response['test-plugin/test-plugin.php']->new_version);
    }

    public function test_plugin_info_returns_download_link_for_matching_slug(): void
    {
        $repo = 'https://github.com/Ratt-Grafiska/test-plugin';
        $latestUrl = 'https://api.github.com/repos/Ratt-Grafiska/test-plugin/releases/latest';
        $GLOBALS['gitup_test_installed_plugins']['test-plugin/test-plugin.php'] = [
            'Name' => 'Test Plugin',
            'Version' => '1.0.0',
            'Author' => 'Ratt Grafiska',
            'Description' => 'Plugin description',
            'UpdateURI' => $repo,
        ];

        gitup_test_queue_http_response($latestUrl, [
            'response' => ['code' => 200],
            'headers' => [],
            'body' => json_encode([
                'tag_name' => '1.2.0',
                'name' => '1.2.0',
                'body' => 'Important fixes',
                'html_url' => $repo . '/releases/tag/1.2.0',
            ]),
        ]);

        $args = (object) ['slug' => 'test-plugin'];
        $info = GitUpUpdater::get_instance()->plugin_info(null, 'plugin_information', $args);

        $this->assertSame('1.2.0', $info->version);
        $this->assertSame('https://codeload.github.com/Ratt-Grafiska/test-plugin/zip/refs/tags/1.2.0', $info->download_link);
        $this->assertSame('Plugin description', $info->sections['description']);
    }

    public function test_theme_update_check_adds_response_and_release_cache(): void
    {
        $repo = 'https://github.com/Ratt-Grafiska/test-theme';
        $latestUrl = 'https://api.github.com/repos/Ratt-Grafiska/test-theme/releases/latest';
        $GLOBALS['gitup_test_installed_themes']['test-theme'] = new GitupTestTheme([
            'Name' => 'Test Theme',
            'Version' => '1.0.0',
            'Author' => 'Ratt Grafiska',
            'Description' => 'Theme description',
            'UpdateURI' => $repo,
            'ThemeURI' => $repo,
        ]);

        gitup_test_queue_http_response($latestUrl, [
            'response' => ['code' => 200],
            'headers' => [],
            'body' => json_encode([
                'tag_name' => '2.0.0',
                'name' => '2.0.0',
                'body' => 'Theme changes',
                'html_url' => $repo . '/releases/tag/2.0.0',
            ]),
        ]);

        $transient = (object) ['checked' => ['test-theme' => '1.0.0']];
        $result = GitUpUpdater::get_instance()->check_for_theme_update($transient);

        $this->assertSame('2.0.0', $result->response['test-theme']['new_version']);
        $this->assertSame('https://codeload.github.com/Ratt-Grafiska/test-theme/zip/refs/tags/2.0.0', $result->response['test-theme']['package']);
        $this->assertSame('2.0.0', $result->rg_releases['test-theme'][0]['tag_name']);
    }

    public function test_theme_info_builds_changelog_from_releases(): void
    {
        $repo = 'https://github.com/Ratt-Grafiska/test-theme';
        $latestUrl = 'https://api.github.com/repos/Ratt-Grafiska/test-theme/releases/latest';
        $GLOBALS['gitup_test_installed_themes']['test-theme'] = new GitupTestTheme([
            'Name' => 'Test Theme',
            'Version' => '1.0.0',
            'Author' => 'Ratt Grafiska',
            'Description' => 'Theme description',
            'UpdateURI' => $repo,
            'ThemeURI' => 'https://example.test/theme',
        ]);

        gitup_test_queue_http_response($latestUrl, [
            'response' => ['code' => 200],
            'headers' => [],
            'body' => json_encode([
                'tag_name' => '2.0.0',
                'name' => '2.0.0',
                'body' => 'Theme changes',
                'html_url' => $repo . '/releases/tag/2.0.0',
            ]),
        ]);

        $args = (object) ['slug' => 'test-theme'];
        $info = GitUpUpdater::get_instance()->theme_info(null, 'theme_information', $args);

        $this->assertSame('2.0.0', $info->version);
        $this->assertSame('https://codeload.github.com/Ratt-Grafiska/test-theme/zip/refs/tags/2.0.0', $info->download_link);
        $this->assertStringContainsString('<h4>2.0.0</h4>', $info->sections['changelog']);
        $this->assertSame('Theme description', $info->sections['description']);
    }
}
