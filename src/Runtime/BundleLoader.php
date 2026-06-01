<?php

declare(strict_types=1);

namespace Stromcom\I18n\Runtime;

use Psr\Log\LoggerInterface;
use Stromcom\I18n\Config\I18nConfig;

/**
 * Lazy-loads the bundle from disk. PHP-DI keeps it as a singleton, so a warm
 * Lambda container has a cache between requests — typically ~50-200 KB per locale.
 *
 * Resolution order:
 *   1. `<locale>.cache.php` (OPcache hot path) — if it exists and has mtime ≥ JSON
 *   2. `<locale>.json` (source of truth)
 *
 * The mtime check guards against a stale PHP cache when someone manually edits the
 * JSON (e.g. hand-adds a translation for local debugging). `composer i18n:fetch`
 * synchronizes the mtime via `touch()`, so a fresh fetch always activates the PHP path.
 *
 * If no bundle file exists, it returns an empty array — `t()` falls back to
 * `MissingKeyPolicy` (default / log / throw in dev).
 */
final class BundleLoader implements BundleLoaderInterface
{
    /** @var array<string, array<string, string>> */
    private array $cache = [];

    public function __construct(
        private readonly I18nConfig $config,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return array<string, string>
     */
    public function load(string $locale): array
    {
        if (isset($this->cache[$locale])) {
            return $this->cache[$locale];
        }

        $phpPath = $this->config->bundlePhpCachePath($locale);
        $jsonPath = $this->config->bundlePath($locale);

        $loaded = $this->tryLoadPhpCache($locale, $phpPath, $jsonPath);
        if ($loaded !== null) {
            return $this->cache[$locale] = $loaded;
        }

        $loaded = $this->tryLoadJson($locale, $jsonPath);
        if ($loaded !== null) {
            return $this->cache[$locale] = $loaded;
        }

        return $this->cache[$locale] = [];
    }

    /**
     * @return array<string, string>|null  null = do not use (stale/missing/invalid)
     */
    private function tryLoadPhpCache(string $locale, string $phpPath, string $jsonPath): ?array
    {
        if (!is_file($phpPath)) {
            return null;
        }

        // Mtime check: if the JSON also exists and has a newer mtime, the PHP cache is stale.
        if (is_file($jsonPath)) {
            $phpMtime = @filemtime($phpPath);
            $jsonMtime = @filemtime($jsonPath);
            if ($phpMtime !== false && $jsonMtime !== false && $phpMtime < $jsonMtime) {
                $this->logger->info('[i18n] BundleLoader: PHP cache stale (JSON newer), using JSON', [
                    'locale' => $locale,
                ]);
                return null;
            }
        }

        /** @var mixed $data */
        $data = require $phpPath;
        if (!is_array($data)) {
            $this->logger->warning('[i18n] BundleLoader: PHP cache did not return an array', [
                'locale' => $locale,
                'path'   => $phpPath,
            ]);
            return null;
        }
        return $this->normalize($data);
    }

    /**
     * @return array<string, string>|null
     */
    private function tryLoadJson(string $locale, string $path): ?array
    {
        if (!is_file($path)) {
            $this->logger->warning('[i18n] Bundle file missing — runtime will fall back to defaults', [
                'locale' => $locale,
                'path'   => $path,
            ]);
            return null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            $this->logger->error('[i18n] Bundle file unreadable', ['locale' => $locale, 'path' => $path]);
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->logger->error('[i18n] Bundle JSON decode failed', [
                'locale' => $locale,
                'path'   => $path,
                'error'  => $e->getMessage(),
            ]);
            return null;
        }

        if (!is_array($decoded)) {
            $this->logger->error('[i18n] Bundle root is not an object', ['locale' => $locale]);
            return null;
        }

        return $this->normalize($decoded);
    }

    /**
     * The Translator returns the bundle wrapped in metadata:
     *   { "version": ..., "locale": ..., "generated_at": ..., "translations": {...} }
     *
     * If the root contains a `translations` field, we use its contents. Otherwise
     * we assume the legacy flat-map format (`{ "key": "text", ... }`) for backward
     * compatibility and easier manual edits in tests.
     *
     * @param array<int|string, mixed> $raw
     *
     * @return array<string, string>
     */
    private function normalize(array $raw): array
    {
        if (isset($raw['translations']) && is_array($raw['translations'])) {
            /** @var array<int|string, mixed> $source */
            $source = $raw['translations'];
        } else {
            $source = $raw;
        }

        $flat = [];
        foreach ($source as $k => $v) {
            if (is_string($k) && is_string($v)) {
                $flat[$k] = $v;
            }
        }
        return $flat;
    }
}
