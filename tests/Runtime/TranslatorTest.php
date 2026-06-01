<?php

declare(strict_types=1);

namespace Stromcom\I18n\Tests\Runtime;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Stromcom\I18n\Config\I18nConfig;
use Stromcom\I18n\Runtime\LocaleContext;
use Stromcom\I18n\Runtime\MissingKeyPolicy;
use Stromcom\I18n\Runtime\MissingTranslationException;
use Stromcom\I18n\Runtime\Translator;
use Stromcom\I18n\Tests\Support\InMemoryBundleLoader;

#[CoversClass(Translator::class)]
final class TranslatorTest extends TestCase
{
    private function makeConfig(MissingKeyPolicy $policy = MissingKeyPolicy::LogAndFallback, bool $dev = false): I18nConfig
    {
        return new I18nConfig(
            projectId: 'test',
            token: '',
            baseUrl: 'https://example.test',
            sourceLocale: 'en',
            targetLocales: ['en', 'cs'],
            fallbackLocale: 'en',
            bundlesDir: '/tmp',
            scanPaths: [],
            missingKeyPolicy: $policy,
            isDevelop: $dev,
        );
    }

    public function testReturnsBundleValueForActiveLocale(): void
    {
        $config = $this->makeConfig();
        $context = new LocaleContext($config);
        $context->set('cs');
        $loader = new InMemoryBundleLoader([
            'cs' => ['login.submit' => 'Přihlásit'],
            'en' => ['login.submit' => 'Sign in'],
        ]);
        $t = new Translator($config, $loader, $context, new NullLogger());

        self::assertSame('Přihlásit', $t->trans('login.submit', 'Sign in'));
    }

    public function testFallsBackToSourceLocaleWhenKeyMissingInActive(): void
    {
        $config = $this->makeConfig();
        $context = new LocaleContext($config);
        $context->set('cs');
        $loader = new InMemoryBundleLoader([
            'cs' => [],
            'en' => ['login.submit' => 'Sign in (source)'],
        ]);
        $t = new Translator($config, $loader, $context, new NullLogger());

        self::assertSame('Sign in (source)', $t->trans('login.submit', 'Sign in (default)'));
    }

    public function testFallsBackToDefaultWhenKeyMissingEverywhere(): void
    {
        $config = $this->makeConfig();
        $context = new LocaleContext($config);
        $context->set('cs');
        $loader = new InMemoryBundleLoader(['cs' => [], 'en' => []]);
        $t = new Translator($config, $loader, $context, new NullLogger());

        self::assertSame('Sign in', $t->trans('login.submit', 'Sign in'));
    }

    public function testThrowsInDevWhenPolicyIsThrowInDev(): void
    {
        $config = $this->makeConfig(MissingKeyPolicy::ThrowInDev, dev: true);
        $context = new LocaleContext($config);
        $context->set('cs');
        $loader = new InMemoryBundleLoader(['cs' => [], 'en' => []]);
        $t = new Translator($config, $loader, $context, new NullLogger());

        $this->expectException(MissingTranslationException::class);
        $t->trans('missing.key', 'fallback');
    }

    public function testSubstitutesPlaceholdersWithStrtrFallback(): void
    {
        // If ext-intl is missing, the fallback is strtr(). If it's present, MessageFormatter
        // handles plain {var} too. Both must return the substituted result.
        $config = $this->makeConfig();
        $context = new LocaleContext($config);
        $context->set('en');
        $loader = new InMemoryBundleLoader(['en' => ['greet' => 'Hello {name}!']]);
        $t = new Translator($config, $loader, $context, new NullLogger());

        self::assertSame('Hello Petr!', $t->trans('greet', 'Hello {name}!', ['name' => 'Petr']));
    }

    public function testReturnsRawMessageWhenNoParams(): void
    {
        $config = $this->makeConfig();
        $context = new LocaleContext($config);
        $context->set('en');
        $loader = new InMemoryBundleLoader(['en' => ['plain' => 'Just text']]);
        $t = new Translator($config, $loader, $context, new NullLogger());

        self::assertSame('Just text', $t->trans('plain', 'Just text'));
    }
}
