<?php

declare(strict_types=1);

namespace Stromcom\I18n\Config;

use Stromcom\I18n\Runtime\MissingKeyPolicy;

/**
 * Immutable package configuration. The consumer passes this instance via DI;
 * all other classes inject it. No static reading of env from the package —
 * mapping env → configuration belongs to the consumer.
 */
final readonly class I18nConfig
{
    /**
     * @param list<string>      $targetLocales   Locales that are fetched and that are valid values for the `?locale=` query / cookie.
     * @param list<string>      $scanPaths       Absolute paths to directories the scanner walks through (.php + .twig).
     * @param list<string>      $scanExcludes    Substring matching — a path containing any of these strings is skipped (e.g. `/vendor/`, `/tests/`).
     */
    public function __construct(
        public string $projectId,
        public string $token,
        public string $baseUrl,
        public string $sourceLocale,
        public array $targetLocales,
        public string $fallbackLocale,
        public string $bundlesDir,
        public array $scanPaths,
        public array $scanExcludes = ['/vendor/', '/node_modules/', '/build/', '/var/', '/.git/'],
        public string $cookieName = 'locale',
        public int $cookieTtl = 31_536_000,
        public MissingKeyPolicy $missingKeyPolicy = MissingKeyPolicy::LogAndFallback,
        public bool $isDevelop = false,
        public string $etagStorePath = '.i18n-etags.json',
    ) {
        if (!in_array($fallbackLocale, $targetLocales, true)) {
            throw new \InvalidArgumentException(sprintf(
                'fallbackLocale "%s" must be in targetLocales [%s].',
                $fallbackLocale,
                implode(', ', $targetLocales),
            ));
        }
    }

    public function bundlePath(string $locale): string
    {
        return $this->bundlesDir . DIRECTORY_SEPARATOR . $locale . '.json';
    }

    /**
     * OPcache-friendly variant of the bundle: `<?php return [...];`.
     * `BundleLoader` prefers it if its mtime ≥ the `.json` mtime (otherwise it
     * falls back to JSON — someone manually edited the JSON and the PHP cache
     * would be stale).
     *
     * The `.cache.php` naming signals that this is a derived artifact —
     * `composer i18n:fetch` regenerates it from the JSON.
     */
    public function bundlePhpCachePath(string $locale): string
    {
        return $this->bundlesDir . DIRECTORY_SEPARATOR . $locale . '.cache.php';
    }

    public function isLocaleSupported(string $locale): bool
    {
        return in_array($locale, $this->targetLocales, true);
    }
}
