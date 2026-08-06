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

    /**
     * Writes a file, creating intermediate directories so `$name` may contain slashes
     * (`src/templates/page.xsl`).
     */
    public function write(string $name, string $content): string
    {
        $abs = $this->path . '/' . $name;
        $dir = dirname($abs);
        if (!is_dir($dir) && !mkdir($dir, 0o700, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create tmp subdir: ' . $dir);
        }
        if (file_put_contents($abs, $content) === false) {
            throw new \RuntimeException('Cannot write tmp file: ' . $abs);
        }
        return $abs;
    }

    public function mkdir(string $name): string
    {
        $abs = $this->path . '/' . $name;
        if (!is_dir($abs) && !mkdir($abs, 0o700, true) && !is_dir($abs)) {
            throw new \RuntimeException('Cannot create tmp subdir: ' . $abs);
        }
        return $abs;
    }

    public function writeJson(string $name, mixed $payload): string
    {
        return $this->write($name, json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function cleanup(): void
    {
        $this->removeTree($this->path);
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $entries = glob($dir . '/*');
        if ($entries !== false) {
            foreach ($entries as $entry) {
                if (is_dir($entry)) {
                    $this->removeTree($entry);
                    continue;
                }
                @chmod($entry, 0o600);
                @unlink($entry);
            }
        }
        @rmdir($dir);
    }
}
