<?php

declare(strict_types=1);

final class GitupPluginUpgradeHooksTest extends GitupTestCase
{
    private array $tempPaths = [];

    public function test_source_selection_keeps_top_level_plugin_directory_when_main_file_exists(): void
    {
        $source = $this->makeTempDir('plugin-source-top');
        $this->writePlugin($source, 'gitup.php');

        $result = apply_filters(
            'upgrader_source_selection',
            trailingslashit($source),
            dirname($source),
            new stdClass(),
            ['plugin' => 'gitup/gitup.php']
        );

        $this->assertSame(trailingslashit($source), $result);
    }

    public function test_source_selection_finds_plugin_directory_when_main_file_is_nested(): void
    {
        $source = $this->makeTempDir('plugin-source-nested');
        $nested = $source . '/repo-1.2.0/plugin-dir';
        $this->writePlugin($nested, 'gitup.php');

        $result = apply_filters(
            'upgrader_source_selection',
            trailingslashit($source),
            dirname($source),
            new stdClass(),
            ['plugin' => 'gitup/gitup.php']
        );

        $this->assertSame($nested, $result);
    }

    public function test_source_selection_falls_back_to_plugin_header_in_subdirectory(): void
    {
        $source = $this->makeTempDir('plugin-source-fallback');
        $fallbackDir = $source . '/unexpected-dir';
        $this->writePlugin($fallbackDir, 'bootstrap.php');

        $result = apply_filters(
            'upgrader_source_selection',
            trailingslashit($source),
            dirname($source),
            new stdClass(),
            ['plugin' => 'gitup/gitup.php']
        );

        $this->assertSame($fallbackDir, $result);
    }

    private function makeTempDir(string $suffix): string
    {
        $path = sys_get_temp_dir() . '/gitup-tests-' . $suffix . '-' . uniqid('', true);
        mkdir($path, 0777, true);
        $this->tempPaths[] = $path;
        return $path;
    }

    private function writePlugin(string $path, string $mainFile): void
    {
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
        file_put_contents(
            $path . '/' . $mainFile,
            "<?php\n/*\nPlugin Name: Test Plugin\nVersion: 1.0.0\n*/\n"
        );
    }
}
