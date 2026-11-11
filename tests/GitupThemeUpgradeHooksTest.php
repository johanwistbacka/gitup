<?php

declare(strict_types=1);

final class GitupThemeUpgradeHooksTest extends GitupTestCase
{
    private array $tempPaths = [];

    public function setUp(): void
    {
        parent::setUp();
        $GLOBALS['gitup_test_theme_root'] = $this->makeTempDir('themes-root');
    }

    public function tearDown(): void
    {
        foreach (array_reverse($this->tempPaths) as $path) {
            $this->removeDirectory($path);
        }
        $this->tempPaths = [];
    }

    public function test_find_theme_root_directory_prefers_expected_stylesheet(): void
    {
        $source = $this->makeTempDir('source');
        $this->writeTheme($source . '/repo-1.1');
        $this->writeTheme($source . '/nested/expected-theme');

        $result = gitup_find_theme_root_directory($source, 'expected-theme');

        $this->assertSame(trailingslashit($source . '/nested/expected-theme'), $result);
    }

    public function test_source_selection_renames_extracted_theme_directory_to_stylesheet(): void
    {
        $sourceBase = $this->makeTempDir('upgrade');
        $source = $sourceBase . '/uppdateringstest-1.1';
        $this->writeTheme($source);

        $result = apply_filters(
            'upgrader_source_selection',
            trailingslashit($source),
            $sourceBase,
            new stdClass(),
            ['theme' => 'uppdateringstest']
        );

        $expected = trailingslashit($sourceBase . '/uppdateringstest');
        $this->assertSame($expected, $result);
        $this->assertFileExists($sourceBase . '/uppdateringstest');
        $this->assertFileDoesNotExist($sourceBase . '/uppdateringstest-1.1');
    }

    public function test_theme_package_options_use_theme_root_without_trailing_slash(): void
    {
        $options = apply_filters('upgrader_package_options', [
            'destination' => '/placeholder',
            'hook_extra' => ['theme' => 'uppdateringstest'],
            'clear_destination' => false,
            'abort_if_destination_exists' => true,
        ]);

        $this->assertSame($GLOBALS['gitup_test_theme_root'], $options['destination']);
        $this->assertFalse(substr($options['destination'], -1) === '/');
        $this->assertFalse(isset($options['destination_name']));
        $this->assertTrue($options['clear_destination']);
        $this->assertFalse($options['abort_if_destination_exists']);
    }

    public function test_source_selection_preserves_case_only_rename_and_zero_stylesheet(): void
    {
        foreach (['expected', '0', 0, 123] as $stylesheet) {
            $base = $this->makeTempDir('canonical-name');
            $source = $base . '/' . ((string) $stylesheet !== 'expected' ? 'repo-2.0' : 'Expected');
            $this->writeTheme($source);
            $result = apply_filters('upgrader_source_selection', $source, $base, new stdClass(), ['theme' => $stylesheet]);
            $this->assertSame($base . '/' . $stylesheet . '/', $result);
            $this->assertFileExists($result . 'style.css');
        }
    }

    public function test_install_package_result_rejects_missing_or_mismatched_destination_name(): void
    {
        $result = apply_filters('upgrader_install_package_result', [
            'destination' => $GLOBALS['gitup_test_theme_root'] . '/uppdateringstest/',
            'destination_name' => '',
        ], ['theme' => 'uppdateringstest']);

        $this->assertTrue(is_wp_error($result));
        $this->assertSame('gitup_theme_destination_mismatch', $result->get_error_code());
    }

    public function test_source_selection_rejects_blocked_rename_without_changing_either_source(): void
    {
        foreach (['nonempty-directory', 'empty-directory', 'file', 'symlink'] as $blocker) {
            $source = $this->makeTempDir('collision');
            $this->writeTheme($source . '/victim');
            if ($blocker === 'file') {
                file_put_contents($source . '/expected', 'blocker');
            } elseif ($blocker === 'symlink') {
                symlink($source . '/missing', $source . '/expected');
            } else {
                mkdir($source . '/expected');
                if ($blocker === 'nonempty-directory') {
                    file_put_contents($source . '/expected/blocker', 'blocker');
                }
            }

            $result = apply_filters('upgrader_source_selection', $source, $source, new stdClass(), ['theme' => 'expected']);
            $this->assertTrue(is_wp_error($result), $blocker);
            $this->assertSame('gitup_theme_source_collision', $result->get_error_code());
            $this->assertFileExists($source . '/victim/style.css');
        }
    }

    public function test_source_selection_rejects_ambiguous_and_invalid_packages(): void
    {
        $source = $this->makeTempDir('ambiguous');
        $this->writeTheme($source . '/first');
        $this->writeTheme($source . '/second');
        $result = apply_filters('upgrader_source_selection', $source, $source, new stdClass(), ['theme' => 'expected']);
        $this->assertTrue(is_wp_error($result));

        $this->writeTheme($source . '/one/expected');
        $this->writeTheme($source . '/two/expected');
        $result = gitup_find_theme_root_directory($source, 'expected');
        $this->assertSame('gitup_ambiguous_theme_source', $result->get_error_code());

        $empty = $this->makeTempDir('invalid');
        $result = apply_filters('upgrader_source_selection', $empty, $empty, new stdClass(), ['theme' => 'expected']);
        $this->assertTrue(is_wp_error($result));
    }

    public function test_manual_and_global_options_preserve_the_installed_theme_root(): void
    {
        $custom = $this->makeTempDir('custom-theme-root');
        $GLOBALS['gitup_test_theme_roots']['expected'] = $custom;
        $options = ['destination' => '/placeholder', 'hook_extra' => ['theme' => 'expected']];
        $this->assertSame($custom, apply_filters('upgrader_package_options', $options)['destination']);
        $filter = gitup_build_theme_package_options_filter('expected');
        $this->assertSame($custom, $filter($options)['destination']);
        $result = ['destination' => $custom . '/expected/', 'destination_name' => 'expected'];
        $this->assertSame($result, gitup_validate_theme_install_result($result, ['theme' => 'expected']));
    }

    public function test_source_selection_preserves_errors_and_rejects_unsafe_identities(): void
    {
        $error = new WP_Error('earlier_error', 'Earlier failure');
        $this->assertSame($error, apply_filters('upgrader_source_selection', $error, '', new stdClass(), ['theme' => 'expected']));
        foreach (['../victim', '/victim', 'victim/', 'dir/victim', 'dir\\victim', '.', '..', ' expected', "expected\0"] as $stylesheet) {
            $result = apply_filters('upgrader_source_selection', '/unused', '/unused', new stdClass(), ['theme' => $stylesheet]);
            $this->assertTrue(is_wp_error($result));
            $this->assertSame('gitup_invalid_theme_stylesheet', $result->get_error_code());
        }
    }

    public function test_source_selection_rejects_symlinked_theme_outside_package(): void
    {
        $source = $this->makeTempDir('symlink-source');
        $outside = $this->makeTempDir('outside');
        $this->writeTheme($outside . '/expected');
        symlink($outside . '/expected', $source . '/expected');
        $result = apply_filters('upgrader_source_selection', $source, $source, new stdClass(), ['theme' => 'expected']);
        $this->assertTrue(is_wp_error($result));
        $this->assertFileExists($outside . '/expected/style.css');
    }

    public function test_destination_checks_run_before_core_theme_switch_and_do_not_mask_mismatch(): void
    {
        $valid = [
            'destination' => $GLOBALS['gitup_test_theme_root'] . '/expected/',
            'destination_name' => 'expected',
        ];
        $this->assertTrue(apply_filters('upgrader_post_install', true, ['theme' => 'expected'], $valid));
        $this->assertSame($valid, apply_filters('upgrader_install_package_result', $valid, ['theme' => 'expected']));
        foreach ([
            ['destination' => $GLOBALS['gitup_test_theme_root'] . '/victim/', 'destination_name' => 'expected'],
            ['destination' => $valid['destination'], 'destination_name' => 'victim'],
        ] as $invalid) {
            $this->assertTrue(is_wp_error(apply_filters('upgrader_post_install', true, ['theme' => 'expected'], $invalid)));
            $this->assertTrue(is_wp_error(apply_filters('upgrader_install_package_result', $invalid, ['theme' => 'expected'])));
        }
        $error = new WP_Error('earlier_error', 'Earlier failure');
        $this->assertSame($error, apply_filters('upgrader_post_install', $error, ['theme' => 'expected'], $valid));
    }

    private function makeTempDir(string $suffix): string
    {
        $path = sys_get_temp_dir() . '/gitup-tests-' . $suffix . '-' . uniqid('', true);
        mkdir($path, 0777, true);
        $this->tempPaths[] = $path;
        return $path;
    }

    private function writeTheme(string $path): void
    {
        mkdir($path, 0777, true);
        file_put_contents(
            $path . '/style.css',
            "/*\nTheme Name: Test Theme\nVersion: 1.0.0\n*/\n"
        );
        file_put_contents($path . '/functions.php', "<?php\n");
    }
}
