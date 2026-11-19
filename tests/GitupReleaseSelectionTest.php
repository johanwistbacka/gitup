<?php

declare(strict_types=1);

final class GitupReleaseSelectionTest extends GitupTestCase
{
    private const REPO = 'https://github.com/Studio-Wistbacka/vc-theme-2026';
    private const API = 'https://api.github.com/repos/Studio-Wistbacka/vc-theme-2026';

    private function releases(string $prefix = ''): array
    {
        // Actual GitHub API order observed on 2026-11-19.
        return array_map(static function (int $number) use ($prefix): array {
            $tag = $prefix . '0.1.1-beta.' . $number;
            return [
                'tag_name' => $tag,
                'name' => 'Release ' . $number,
                'body' => 'Notes for ' . $tag,
                'html_url' => self::REPO . '/releases/tag/' . $tag,
                'prerelease' => true,
                'draft' => false,
            ];
        }, [9, 8, 7, 12, 11, 10]);
    }

    private function queue(string $path, array $data, int $code = 200): void
    {
        gitup_test_queue_http_response(self::API . $path, [
            'response' => ['code' => $code],
            'headers' => [],
            'body' => json_encode($data),
        ]);
    }

    private function installComponents(): void
    {
        $GLOBALS['gitup_test_installed_themes']['vc-theme-2026'] = new GitupTestTheme([
            'Name' => 'VC Theme',
            'Version' => '0.1.1-beta.9',
            'Author' => 'Studio Wistbacka',
            'UpdateURI' => self::REPO,
        ]);
        $GLOBALS['gitup_test_installed_plugins']['test-plugin/index.php'] = [
            'Name' => 'Test Plugin',
            'Version' => '0.1.1-beta.9',
            'UpdateURI' => self::REPO,
        ];
        set_transient('github_repo_visibility_' . md5(self::REPO), 'public');
    }

    private function render(): string
    {
        ob_start();
        try {
            gitup_settings_page();
            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }

    public function test_api_order_is_sorted_before_limits_one_and_twenty_with_original_tags(): void
    {
        foreach (['', 'v'] as $prefix) {
            foreach ([1, 20] as $limit) {
                gitup_test_reset_state();
                $data = $this->releases($prefix);
                array_unshift($data, ['tag_name' => '9.0.0', 'draft' => true]);
                $this->queue('/releases?per_page=' . max(10, $limit), $data);

                $result = gitup_get_github_releases_data(self::REPO, true, $limit);
                $expected = array_map(static fn(int $n): string => $prefix . '0.1.1-beta.' . $n, [12, 11, 10, 9, 8, 7]);
                $this->assertSame(array_slice($expected, 0, $limit), array_column($result, 'tag_name'));
                $this->assertSame('Notes for ' . $expected[0], $result[0]['body']);
                $this->assertSame($result, gitup_get_github_releases_data(self::REPO, true, $limit));
                $this->assertCount(1, $GLOBALS['gitup_test_http_calls']);
            }
        }
    }

    public function test_tag_fallback_sorts_before_limit_and_preserves_v_prefix_in_both_channels(): void
    {
        foreach ([false, true] as $includePre) {
            foreach ([1, 20] as $limit) {
                gitup_test_reset_state();
                $this->queue($includePre ? '/releases?per_page=' . max(10, $limit) : '/releases/latest', [], 404);
                $tags = array_map(static fn(array $rel): array => ['name' => $rel['tag_name']], $this->releases('v'));
                $tags[] = ['name' => 'v0.1.0'];
                $tags[] = ['name' => 'v0.0.9'];
                $this->queue('/tags?per_page=' . max(10, $limit), $tags);

                $result = gitup_get_github_releases_data(self::REPO, $includePre, $limit);
                $this->assertSame($includePre ? 'v0.1.1-beta.12' : 'v0.1.0', $result[0]['tag_name']);
                $this->assertCount($limit === 1 ? 1 : ($includePre ? 8 : 2), $result);
                $this->assertSame($includePre, $result[0]['prerelease']);
                $this->assertSame(
                    'https://codeload.github.com/Studio-Wistbacka/vc-theme-2026/zip/refs/tags/' . $result[0]['tag_name'],
                    gitup_build_github_package_url(self::REPO, $result[0]['tag_name'])
                );
            }
        }
    }

    public function test_theme_details_dropdown_popup_and_update_engines_follow_channel_changes(): void
    {
        $this->installComponents();
        $this->queue('/releases?per_page=20', $this->releases('v'));
        // Limits 1 and 10 share an API URL but have separate caches.
        $this->queue('/releases?per_page=10', $this->releases('v'));
        $this->queue('/releases?per_page=10', $this->releases('v'));
        $stable = ['tag_name' => 'v0.1.0', 'body' => 'Stable notes', 'prerelease' => false];
        for ($i = 0; $i < 3; $i++) {
            $this->queue('/releases/latest', $stable);
        }

        foreach (['1', '0', '1'] as $setting) {
            update_option('gitup_include_prereleases', $setting);
            $expected = $setting === '1' ? 'v0.1.1-beta.12' : 'v0.1.0';
            $themes = get_github_themes(); // Exercise production code, not a component-list stub.
            $this->assertSame($expected, $themes[0]['latest_release']);
            $this->assertSame($expected, $themes[0]['releases'][0]['tag']);
            $html = $this->render();
            $this->assertTrue((bool) preg_match('/Latest release:<\/strong>\s*' . preg_quote($expected, '/') . '/', $html));
            $this->assertTrue((bool) preg_match('/name="tag"><option value="' . preg_quote($expected, '/') . '">/', $html));
            $this->assertStringContainsString($setting === '1' ? 'Notes for ' . $expected : 'Stable notes', $html);
            $this->assertStringContainsString($setting === '1' ? 'value="Update"' : 'value="Downgrade"', $html);
            $this->assertSame($setting === '1', str_contains($html, 'class="has-update'));
            if ($setting === '0') {
                $this->assertFalse(str_contains($html, '<option value="v0.1.1-beta.'));
            }

            $updater = GitUpUpdater::get_instance();
            $themeUpdate = $updater->check_for_theme_update((object) ['checked' => ['vc-theme-2026' => '0.1.1-beta.9']]);
            $pluginUpdate = $updater->check_for_update((object) ['checked' => ['test-plugin/index.php' => '0.1.1-beta.9']]);
            $this->assertSame($expected, $themeUpdate->rg_releases['vc-theme-2026'][0]['tag_name']);
            $info = $updater->theme_info(null, 'theme_information', (object) ['slug' => 'vc-theme-2026']);
            $this->assertSame($expected, $info->version);
            if ($setting === '1') {
                $package = 'https://codeload.github.com/Studio-Wistbacka/vc-theme-2026/zip/refs/tags/' . $expected;
                $this->assertSame($expected, $themeUpdate->response['vc-theme-2026']['new_version']);
                $this->assertSame($package, $themeUpdate->response['vc-theme-2026']['package']);
                $this->assertSame($expected, $pluginUpdate->response['test-plugin/index.php']->new_version);
                $this->assertSame($package, $pluginUpdate->response['test-plugin/index.php']->package);
                $this->assertSame($package, $info->download_link);
            } else {
                $this->assertFalse(isset($themeUpdate->response['vc-theme-2026']));
                $this->assertFalse(isset($pluginUpdate->response['test-plugin/index.php']));
            }
        }
        $this->assertCount(6, $GLOBALS['gitup_test_http_calls']);
    }

    public function test_beta_only_theme_has_no_release_or_update_in_stable_channel(): void
    {
        $this->installComponents();
        update_option('gitup_include_prereleases', '0');
        $tags = array_map(static fn(array $rel): array => ['name' => $rel['tag_name']], $this->releases());
        foreach ([10, 20] as $limit) {
            $this->queue('/releases/latest', [], 404);
            $this->queue('/tags?per_page=' . $limit, $tags);
        }

        $themes = get_github_themes();
        $this->assertSame('N/A', $themes[0]['latest_release']);
        $this->assertSame([], $themes[0]['releases']);
        $html = $this->render();
        $this->assertStringContainsString('No releases found', $html);
        $this->assertTrue((bool) preg_match('/Latest release:<\/strong>\s*N\/A/', $html));
        $this->assertFalse(str_contains($html, 'name="tag"'));
        $this->assertFalse(str_contains($html, 'class="has-update'));
        $result = GitUpUpdater::get_instance()->check_for_theme_update((object) ['checked' => ['vc-theme-2026' => '0.1.1-beta.9']]);
        $this->assertFalse(isset($result->response['vc-theme-2026']));
        $this->assertSame(0, gitup_test_http_call_count(self::API . '/releases?per_page=20'));
    }

    public function test_legacy_truncated_and_unsorted_caches_are_refetched_for_all_consumers(): void
    {
        $this->installComponents();
        update_option('gitup_include_prereleases', '1');
        $old = $this->releases();
        foreach ([1, 10, 20] as $limit) {
            set_transient('github_releases_' . md5(self::REPO . '|pre|' . $limit), array_slice($old, 0, $limit));
            $this->queue('/releases?per_page=' . max(10, $limit), $old);
        }
        set_transient('github_release_' . md5(self::REPO . '|pre'), '0.1.1-beta.9');

        $this->assertSame(null, gitup_get_cached_releases(self::REPO, true, 20));
        $this->assertSame('0.1.1-beta.12', gitup_get_latest_github_release_tag(self::REPO));
        $this->assertSame('0.1.1-beta.12', get_github_themes()[0]['latest_release']);
        $this->assertStringContainsString('<option value="0.1.1-beta.12">', $this->render());
        $result = GitUpUpdater::get_instance()->check_for_theme_update((object) ['checked' => ['vc-theme-2026' => '0.1.1-beta.9']]);
        $this->assertSame('0.1.1-beta.12', $result->response['vc-theme-2026']['new_version']);
        $this->assertSame('0.1.1-beta.12', gitup_get_cached_releases(self::REPO, true, 20)[0]['tag_name']);
        $this->assertSame('0.1.1-beta.12', gitup_get_latest_github_release_tag(self::REPO));
        $this->assertCount(3, $GLOBALS['gitup_test_http_calls']);
    }

    public function test_stable_tag_fallback_replaces_legacy_cache_and_updates_with_original_tag(): void
    {
        $this->installComponents();
        update_option('gitup_include_prereleases', '0');
        set_transient('github_releases_' . md5(self::REPO . '|stable|1'), [['tag_name' => '0.1.0']]);
        set_transient('github_release_' . md5(self::REPO . '|stable'), '0.1.0');
        $this->queue('/releases/latest', [], 404);
        $this->queue('/tags?per_page=10', [
            ['name' => 'v0.1.0'],
            ['name' => 'v0.2.0-beta.12'],
            ['name' => 'v0.1.1'],
        ]);

        $this->assertSame('v0.1.1', gitup_get_latest_github_release_tag(self::REPO));
        $result = GitUpUpdater::get_instance()->check_for_update((object) ['checked' => ['test-plugin/index.php' => '0.1.1-beta.9']]);
        $this->assertSame('v0.1.1', $result->response['test-plugin/index.php']->new_version);
        $this->assertSame(
            'https://codeload.github.com/Studio-Wistbacka/vc-theme-2026/zip/refs/tags/v0.1.1',
            $result->response['test-plugin/index.php']->package
        );
        $this->assertSame('v0.1.1', gitup_get_cached_releases(self::REPO, false, 1)[0]['tag_name']);
        $this->assertCount(2, $GLOBALS['gitup_test_http_calls']);
    }
}
