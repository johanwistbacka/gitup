<?php

declare(strict_types=1);

final class GitupManualInstallPreparationTest extends GitupTestCase
{
    public function test_prepare_plugin_release_install_rejects_unknown_plugin(): void
    {
        $result = gitup_prepare_plugin_release_install('missing/plugin.php', '1.2.0');

        $this->assertTrue(is_wp_error($result));
        $this->assertSame('Plugin not found.', $result->get_error_message());
    }

    public function test_prepare_plugin_release_install_rejects_unverified_tag(): void
    {
        $repo = 'https://github.com/Ratt-Grafiska/test-plugin';
        $this->seedPlugin('test-plugin/test-plugin.php', $repo);
        update_option('gitup_include_prereleases', '1');
        $this->queueRepoReleases($repo, [
            ['tag_name' => '1.2.0', 'name' => '1.2.0', 'prerelease' => false],
        ]);

        $result = gitup_prepare_plugin_release_install('test-plugin/test-plugin.php', '9.9.9');

        $this->assertTrue(is_wp_error($result));
        $this->assertSame('Selected plugin release could not be verified.', $result->get_error_message());
    }

    public function test_prepare_plugin_release_install_builds_package_url_for_verified_tag(): void
    {
        $repo = 'https://github.com/Ratt-Grafiska/test-plugin';
        $this->seedPlugin('test-plugin/test-plugin.php', $repo);
        $this->queueRepoReleases($repo, [
            ['tag_name' => 'release/2026-03-beta', 'name' => 'Beta', 'prerelease' => true],
        ]);
        update_option('gitup_include_prereleases', '1');

        $result = gitup_prepare_plugin_release_install('test-plugin/test-plugin.php', 'release/2026-03-beta');

        $this->assertFalse(is_wp_error($result));
        $this->assertSame(
            'https://codeload.github.com/Ratt-Grafiska/test-plugin/zip/refs/tags/release%2F2026-03-beta',
            $result['package']
        );
        $this->assertSame(['release/2026-03-beta'], $result['valid_tags']);
    }

    public function test_prepare_theme_release_install_rejects_unknown_theme(): void
    {
        $result = gitup_prepare_theme_release_install('missing-theme', '1.2.0');

        $this->assertTrue(is_wp_error($result));
        $this->assertSame('Theme not found.', $result->get_error_message());
    }

    public function test_prepare_theme_release_install_builds_package_url_for_verified_tag(): void
    {
        $repo = 'https://github.com/Ratt-Grafiska/test-theme';
        $this->seedTheme('test-theme', $repo);
        update_option('gitup_include_prereleases', '1');
        $this->queueRepoReleases($repo, [
            ['tag_name' => '2.0.0', 'name' => '2.0.0', 'prerelease' => false],
        ]);

        $result = gitup_prepare_theme_release_install('test-theme', '2.0.0');

        $this->assertFalse(is_wp_error($result));
        $this->assertSame(
            'https://codeload.github.com/Ratt-Grafiska/test-theme/zip/refs/tags/2.0.0',
            $result['package']
        );
        $this->assertSame(['2.0.0'], $result['valid_tags']);
    }

    private function seedPlugin(string $pluginFile, string $repoUrl): void
    {
        $GLOBALS['gitup_test_installed_plugins'][$pluginFile] = [
            'Name' => 'Test Plugin',
            'Version' => '1.0.0',
            'UpdateURI' => $repoUrl,
        ];
    }

    private function seedTheme(string $stylesheet, string $repoUrl): void
    {
        $GLOBALS['gitup_test_installed_themes'][$stylesheet] = new GitupTestTheme([
            'UpdateURI' => $repoUrl,
            'ThemeURI' => $repoUrl,
        ]);
    }

    private function queueRepoReleases(string $repoUrl, array $releases): void
    {
        $apiUrl = 'https://api.github.com/repos/' . trim((string) parse_url($repoUrl, PHP_URL_PATH), '/') . '/releases?per_page=50';
        gitup_test_queue_http_response($apiUrl, [
            'response' => ['code' => 200],
            'headers' => [],
            'body' => json_encode($releases),
        ]);
    }
}
