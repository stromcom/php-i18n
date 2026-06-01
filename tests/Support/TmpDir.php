<?php

declare(strict_types=1);

namespace Stromcom\I18n\Tests\Support;

/**
 * Tiny test helper — creates a tmp directory in setUp and wipes its contents in tearDown.
 * Centralizes the logic so tests don't have to keep wrestling with `glob() ?: []`
 * and `json_encode() ?: '{}'` patterns that don't pass the strict rules.
 */
final class TmpDir
{
    private string $path;

    public function __construct(string $prefix)
    {
        $base = sys_get_temp_dir() . '/' . $prefix . '-' . bin2hex(random_bytes(4));
        if (!mkdir($base, 0o700, true) && !is_dir($base)) {
            throw new \RuntimeException('Cannot create tmp dir: ' . $base);
        }
        $this->path = $base;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function write(string $name, string $content): string
    {
        $abs = $this->path . '/' . $name;
        if (file_put_contents($abs, $content) === false) {
            throw new \RuntimeException('Cannot write tmp file: ' . $abs);
        }
        return $abs;
    }

    public function writeJson(string $name, mixed $payload): string
    {
        return $this->write($name, json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function cleanup(): void
    {
        if (!is_dir($this->path)) {
            return;
        }
        $entries = glob($this->path . '/*');
        if ($entries !== false) {
            foreach ($entries as $entry) {
                @unlink($entry);
            }
        }
        @rmdir($this->path);
    }
}
