<?php

declare(strict_types=1);

namespace Stromcom\I18n\Runtime;

use Psr\Log\LoggerInterface;
use Stromcom\I18n\Config\I18nConfig;

/**
 * Core runtime — bundle lookup + ICU formatting via MessageFormatter.
 *
 * - The 3rd call position (`note:`) in code is metadata for the scanner; the runtime ignores it.
 * - Without `ext-intl` it degrades to plain `{var}` placeholder substitution; ICU
 *   plurals and number formatting do not work in that case, but the application
 *   does not die.
 * - Missing key → `MissingKeyPolicy` decides (throw in dev / log + fallback / silent).
 */
final readonly class Translator implements TranslatorInterface
{
    public function __construct(
        private I18nConfig $config,
        private BundleLoaderInterface $loader,
        private LocaleContext $context,
        private LoggerInterface $logger,
    ) {}

    public function trans(string $key, string $default, array $params = [], ?string $locale = null): string
    {
        $locale = $locale ?? $this->context->get();
        $bundle = $this->loader->load($locale);

        if (array_key_exists($key, $bundle) && $bundle[$key] !== '') {
            return $this->format($bundle[$key], $params, $locale);
        }

        // Fallback: try the source locale if the active one is not the source
        if ($locale !== $this->config->sourceLocale) {
            $source = $this->loader->load($this->config->sourceLocale);
            if (array_key_exists($key, $source) && $source[$key] !== '') {
                return $this->format($source[$key], $params, $locale);
            }
        }

        $this->handleMissing($key, $locale);
        return $this->format($default, $params, $locale);
    }

    /**
     * @param array<string, scalar|\Stringable> $params
     */
    private function format(string $message, array $params, string $locale): string
    {
        if ($params === []) {
            return $message;
        }
        if (extension_loaded('intl') && class_exists(\MessageFormatter::class)) {
            $fmt = \MessageFormatter::create($locale, $message);
            if ($fmt !== null) {
                $out = $fmt->format($params);
                if ($out !== false) {
                    return $out;
                }
                $this->logger->warning('[i18n] MessageFormatter::format failed', [
                    'locale'  => $locale,
                    'message' => $message,
                    'error'   => $fmt->getErrorMessage(),
                ]);
            } else {
                $this->logger->warning('[i18n] MessageFormatter::create returned null (invalid ICU pattern)', [
                    'locale'  => $locale,
                    'message' => $message,
                ]);
            }
        }
        // Fallback substitution — does not cover plurals or formatting, just plain `{var}`.
        // Pure `strtr()` with a pre-built map — no regex.
        $replacements = [];
        foreach ($params as $name => $value) {
            if (is_scalar($value) || $value instanceof \Stringable) {
                $replacements['{' . $name . '}'] = (string) $value;
            }
        }
        return $replacements === [] ? $message : strtr($message, $replacements);
    }

    private function handleMissing(string $key, string $locale): void
    {
        switch ($this->config->missingKeyPolicy) {
            case MissingKeyPolicy::ThrowInDev:
                if ($this->config->isDevelop) {
                    throw new MissingTranslationException($key, $locale);
                }
                $this->logger->warning('[i18n] Missing translation', ['key' => $key, 'locale' => $locale]);
                break;
            case MissingKeyPolicy::LogAndFallback:
                $this->logger->warning('[i18n] Missing translation', ['key' => $key, 'locale' => $locale]);
                break;
            case MissingKeyPolicy::Silent:
                break;
        }
    }
}
