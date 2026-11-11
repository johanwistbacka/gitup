<?php

declare(strict_types=1);

final class GitupUpdatesAuthorizationTest extends GitupTestCase
{
    private const CAPS = ['update_core', 'update_plugins', 'update_themes', 'manage_options', 'install_plugins', 'install_themes'];

    private function allowOnly(array $caps): void
    {
        $GLOBALS['gitup_test_denied_caps'] = array_values(array_diff(self::CAPS, $caps));
    }

    private function seedComponents(bool $cached): void
    {
        update_option('gitup_github_token', 'test-private-token');
        foreach (['plugin', 'theme'] as $type) {
            $repo = 'https://github.com/owner/private-' . $type;
            $release = [
                'tag_name' => '2.0.0',
                'body' => 'Confidential ' . $type . ' release notes',
                'html_url' => $repo . '/releases/tag/2.0.0',
                'published_at' => '2026-10-01T12:00:00Z',
                'prerelease' => false,
            ];
            $component = [
                'name' => 'Private ' . $type,
                'version' => '1.0.0',
                'author' => 'Example',
                'github' => $repo,
                'latest_release' => '2.0.0',
                'file' => 'private-plugin/index.php',
                'stylesheet' => 'private-theme',
                'releases' => [[
                    'tag' => '2.0.0', 'body' => $release['body'],
                    'url' => $release['html_url'], 'published' => $release['published_at'],
                ]],
            ];
            $GLOBALS['gitup_test_github_' . $type . 's'] = [$component];
            // Keep visibility local so HTTP assertions isolate release reads.
            set_transient('github_repo_visibility_' . md5($repo), 'private');
            if ($cached) {
                set_transient(gitup_releases_cache_key($repo, false, 20), [$release]);
            } else {
                gitup_test_queue_http_response('https://api.github.com/repos/owner/private-' . $type . '/releases/latest', [
                    'response' => ['code' => 200], 'body' => json_encode($release),
                ]);
            }
        }
    }

    private function renderPage(): array
    {
        $denied = false;
        ob_start();
        try {
            gitup_settings_page();
        } catch (GitupTestPermissionDenied $e) {
            $denied = true;
        } finally {
            $html = (string) ob_get_clean();
        }
        return [$html, $denied];
    }

    public function test_core_only_user_cannot_read_fresh_or_cached_metadata_even_with_refresh_parameters(): void
    {
        foreach ([false, true] as $cached) {
            foreach ([[], ['tab' => 'updates'], ['tab' => 'updates', 'gitup_refresh' => '1', 'gitup_refreshed' => '1']] as $query) {
                gitup_test_reset_state();
                $this->allowOnly(['update_core']);
                $this->seedComponents($cached);
                $_GET = $query;
                [$html, $denied] = $this->renderPage();
                $this->assertTrue($denied, 'Core-only request must be denied.');
                $this->assertSame('', $html);
                $this->assertSame([], $GLOBALS['gitup_test_component_reads']);
                $this->assertSame([], $GLOBALS['gitup_test_http_calls']);
            }
        }
    }

    public function test_split_managers_only_read_their_authorized_component_class(): void
    {
        foreach (['plugin', 'theme'] as $allowedType) {
            foreach ([false, true] as $cached) {
                gitup_test_reset_state();
                $this->allowOnly(['update_core', 'update_' . $allowedType . 's']);
                $this->seedComponents($cached);
                [$html, $denied] = $this->renderPage();
                $otherType = $allowedType === 'plugin' ? 'theme' : 'plugin';
                $this->assertFalse($denied);
                $this->assertSame([$allowedType . 's'], $GLOBALS['gitup_test_component_reads']);
                $this->assertStringContainsString('Confidential ' . $allowedType . ' release notes', $html);
                $this->assertStringContainsString('https://github.com/owner/private-' . $allowedType, $html);
                $this->assertFalse(str_contains($html, 'private-' . $otherType));
                $this->assertFalse(str_contains($html, 'Confidential ' . $otherType));
                $this->assertFalse(str_contains($html, 'test-nonce-' . md5('gitup_' . ($otherType === 'plugin' ? 'install_release_private-plugin/index.php' : 'themes_install_release_private-theme'))));
                $this->assertFalse(str_contains($html, 'value="gitup_refresh"'));
                $this->assertFalse(str_contains($html, 'value="gitup_clear_cache"'));
                $this->assertCount($cached ? 0 : 1, $GLOBALS['gitup_test_http_calls']);
                if (!$cached) {
                    $call = $GLOBALS['gitup_test_http_calls'][0];
                    $this->assertStringContainsString('/private-' . $allowedType . '/releases/latest', $call['url']);
                    $this->assertSame('Bearer test-private-token', $call['args']['headers']['Authorization']);
                }
            }
        }
    }

    public function test_administrator_keeps_both_sections_and_cache_controls(): void
    {
        foreach ([false, true] as $cached) {
            gitup_test_reset_state();
            $this->seedComponents($cached);
            [$html, $denied] = $this->renderPage();
            $this->assertFalse($denied);
            $this->assertSame(['plugins', 'themes'], $GLOBALS['gitup_test_component_reads']);
            $this->assertStringContainsString('Confidential plugin release notes', $html);
            $this->assertStringContainsString('Confidential theme release notes', $html);
            $this->assertStringContainsString('value="gitup_refresh"', $html);
            $this->assertStringContainsString('value="gitup_clear_cache"', $html);
            $this->assertCount($cached ? 0 : 2, $GLOBALS['gitup_test_http_calls']);
        }
    }

    public function test_combined_updater_can_read_both_classes_without_managing_credentials(): void
    {
        $this->allowOnly(['update_core', 'update_plugins', 'update_themes']);
        $this->seedComponents(true);
        [$html, $denied] = $this->renderPage();
        $this->assertFalse($denied);
        $this->assertSame(['plugins', 'themes'], $GLOBALS['gitup_test_component_reads']);
        $this->assertStringContainsString('Confidential plugin release notes', $html);
        $this->assertStringContainsString('Confidential theme release notes', $html);
        $this->assertFalse(str_contains($html, 'value="gitup_refresh"'));
        $_GET['tab'] = 'settings';
        [$html, $denied] = $this->renderPage();
        $this->assertTrue($denied);
        $this->assertSame('', $html);
    }

    public function test_credential_manager_defaults_to_settings_without_update_metadata_or_navigation(): void
    {
        $this->allowOnly(['update_core', 'manage_options']);
        $this->seedComponents(true);
        [$html, $denied] = $this->renderPage();
        $this->assertFalse($denied);
        $this->assertStringContainsString('Test GitHub connection', $html);
        $this->assertFalse(str_contains($html, '>Updates</a>'));
        $this->assertFalse(str_contains($html, 'Confidential'));
        $this->assertSame([], $GLOBALS['gitup_test_component_reads']);
        $this->assertSame([], $GLOBALS['gitup_test_http_calls']);
        $_GET['tab'] = 'updates';
        [$html, $denied] = $this->renderPage();
        $this->assertTrue($denied);
        $this->assertSame('', $html);
    }

    public function test_global_cache_actions_reject_non_settings_managers_before_any_side_effect(): void
    {
        foreach (['gitup_refresh', 'gitup_clear_cache'] as $action) {
            foreach ([['update_core'], ['update_core', 'update_plugins'], ['update_core', 'update_themes'], ['update_core', 'update_plugins', 'update_themes']] as $caps) {
                gitup_test_reset_state();
                $this->allowOnly($caps);
                $denied = false;
                try {
                    do_action('admin_post_' . $action);
                } catch (GitupTestPermissionDenied $e) {
                    $denied = true;
                }
                $this->assertTrue($denied);
                $this->assertSame([], $GLOBALS['gitup_test_checked_nonces']);
                $this->assertSame(null, $GLOBALS['gitup_test_last_redirect']);
                $this->assertSame([], $GLOBALS['gitup_test_http_calls']);
            }
        }
    }

    public function test_administrator_can_still_refresh_and_clear_the_shared_cache(): void
    {
        $previousWpdb = $GLOBALS['wpdb'] ?? null;
        try {
            foreach (['gitup_refresh', 'gitup_clear_cache'] as $action) {
                gitup_test_reset_state();
                $GLOBALS['wpdb'] = new class {
                    public string $options = 'wp_options';
                    public array $queries = [];
                    public function prepare($query, $pattern) { return $query . ' ' . $pattern; }
                    public function query($query) { $this->queries[] = $query; }
                };
                $GLOBALS['gitup_test_throw_on_redirect'] = true;
                try {
                    do_action('admin_post_' . $action);
                    $this->fail('Expected redirect after cache purge.');
                } catch (GitupTestRedirect $e) {
                    $this->assertStringContainsString('tab=updates', $e->getMessage());
                }
                $this->assertSame([$action], $GLOBALS['gitup_test_checked_nonces']);
                $this->assertCount(6, $GLOBALS['wpdb']->queries);
            }
        } finally {
            $GLOBALS['wpdb'] = $previousWpdb;
        }
    }
}
