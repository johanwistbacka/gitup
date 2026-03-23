<?php

declare(strict_types=1);

abstract class GitupTestCase
{
    public function setUp(): void
    {
        gitup_test_reset_state();
    }

    protected function assertSame($expected, $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            $this->fail($message !== '' ? $message : 'Expected values to be identical.');
        }
    }

    protected function assertTrue($condition, string $message = ''): void
    {
        if ($condition !== true) {
            $this->fail($message !== '' ? $message : 'Expected condition to be true.');
        }
    }

    protected function assertFalse($condition, string $message = ''): void
    {
        if ($condition !== false) {
            $this->fail($message !== '' ? $message : 'Expected condition to be false.');
        }
    }

    protected function assertCount(int $expectedCount, array $items, string $message = ''): void
    {
        if (count($items) !== $expectedCount) {
            $this->fail($message !== '' ? $message : 'Unexpected array count.');
        }
    }

    protected function assertStringContainsString(string $needle, string $haystack, string $message = ''): void
    {
        if (strpos($haystack, $needle) === false) {
            $this->fail($message !== '' ? $message : 'Expected string was not found.');
        }
    }

    protected function assertFileExists(string $path, string $message = ''): void
    {
        if (!file_exists($path)) {
            $this->fail($message !== '' ? $message : 'Expected file or directory to exist.');
        }
    }

    protected function assertFileDoesNotExist(string $path, string $message = ''): void
    {
        if (file_exists($path)) {
            $this->fail($message !== '' ? $message : 'Expected file or directory not to exist.');
        }
    }

    protected function fail(string $message): void
    {
        throw new RuntimeException($message);
    }
}
