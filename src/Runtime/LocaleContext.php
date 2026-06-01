<?php

declare(strict_types=1);

namespace Stromcom\I18n\Runtime;

use Stromcom\I18n\Config\I18nConfig;

/**
 * Per-request mutable holder for the currently resolved locale. In PHP-DI it is
 * a singleton — `LocaleMiddleware` overwrites the value at the start of every
 * request (a warm Lambda container keeps the object between requests, hence the
 * explicit set).
 */
final class LocaleContext
{
    private ?string $locale = null;

    public function __construct(private readonly I18nConfig $config) {}

    public function set(string $locale): void
    {
        $this->locale = $locale;
    }

    public function get(): string
    {
        return $this->locale ?? $this->config->fallbackLocale;
    }

    /** Reset between requests — safe no-op if the middleware did not run. */
    public function reset(): void
    {
        $this->locale = null;
    }
}
