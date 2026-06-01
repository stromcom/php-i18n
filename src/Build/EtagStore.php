<?php

declare(strict_types=1);

namespace Stromcom\I18n\Build;

/**
 * Persists ETag values per-locale so that `BundleFetcher` can send
 * `If-None-Match` and save transfer when the bundle is unchanged. Storage is a
 * simple JSON file (`.i18n-etags.json` in the repo root).
 *
 * No atomic write — there is no risk of collisions between parallel CI runs
 * (each job has its own workspace).
 */
final class EtagStore
{
    /** @var array<string, string>|null */
    private ?array $cache = null;

    public function __construct(private readonly string $filePath) {}

    public function get(string $locale): ?string
    {
        $data = $this->load();
        return $data[$locale] ?? null;
    }

    public function set(string $locale, string $etag): void
    {
        $data = $this->load();
        $data[$locale] = $etag;
        $this->cache = $data;
        $this->persist();
    }

    /**
     * @return array<string, string>
     */
    private function load(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        if (!is_file($this->filePath)) {
            return $this->cache = [];
        }
        $raw = @file_get_contents($this->filePath);
        if ($raw === false) {
            return $this->cache = [];
        }
        try {
            $decoded = json_decode($raw, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->cache = [];
        }
        if (!is_array($decoded)) {
            return $this->cache = [];
        }
        $clean = [];
        foreach ($decoded as $k => $v) {
            if (is_string($k) && is_string($v)) {
                $clean[$k] = $v;
            }
        }
        return $this->cache = $clean;
    }

    private function persist(): void
    {
        $dir = dirname($this->filePath);
        if (!is_dir($dir)) {
            return;
        }
        $json = json_encode($this->cache ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return;
        }
        @file_put_contents($this->filePath, $json . "\n");
    }
}
