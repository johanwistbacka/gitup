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

    public function test_install_package_result_fills_missing_destination_name_for_themes(): void
    {
        $result = apply_filters('upgrader_install_package_result', [
            'destination' => $GLOBALS['gitup_test_theme_root'] . '/uppdateringstest/',
            'destination_name' => '',
        ], ['theme' => 'uppdateringstest']);

        $this->assertSame('uppdateringstest', $result['destination_name']);
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
