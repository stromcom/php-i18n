<?php

declare(strict_types=1);

namespace Stromcom\I18n\Tests\Runtime;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Stromcom\I18n\Config\I18nConfig;
use Stromcom\I18n\Runtime\LocaleContext;
use Stromcom\I18n\Runtime\MissingKeyPolicy;
use Stromcom\I18n\Runtime\MissingTranslationException;
use Stromcom\I18n\Runtime\Translator;
use Stromcom\I18n\Tests\Support\CollectingLogger;
use Stromcom\I18n\Tests\Support\InMemoryBundleLoader;

#[CoversClass(Translator::class)]
#[CoversClass(MissingTranslationException::class)]
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

    /**
     * @param array<string, array<string, string>> $bundles
     */
    private function translator(
        array $bundles,
        string $locale = 'cs',
        MissingKeyPolicy $policy = MissingKeyPolicy::LogAndFallback,
        bool $dev = false,
        ?CollectingLogger $logger = null,
    ): Translator {
        $config = $this->makeConfig($policy, $dev);
        $context = new LocaleContext($config);
        $context->set($locale);

        return new Translator($config, new InMemoryBundleLoader($bundles), $context, $logger ?? new CollectingLogger());
    }

    // ------------------------------------------------------------ lookup rules

    public function testExplicitLocaleArgumentOverridesTheContext(): void
    {
        $t = $this->translator(['cs' => ['k' => 'Česky'], 'en' => ['k' => 'English']], 'cs');

        self::assertSame('English', $t->trans('k', 'D', [], 'en'));
    }

    public function testEmptyStringInTheBundleIsTreatedAsMissing(): void
    {
        // An untranslated key exported as "" must not blank out the UI.
        $t = $this->translator(['cs' => ['k' => ''], 'en' => ['k' => 'Source']], 'cs');

        self::assertSame('Source', $t->trans('k', 'Default'));
    }

    public function testEmptyStringInBothBundlesFallsBackToTheDefault(): void
    {
        $t = $this->translator(['cs' => ['k' => ''], 'en' => ['k' => '']], 'cs');

        self::assertSame('Default', $t->trans('k', 'Default'));
    }

    public function testNoSourceLookupWhenAlreadyInTheSourceLocale(): void
    {
        $t = $this->translator(['en' => []], 'en');

        self::assertSame('Default', $t->trans('k', 'Default'));
    }

    public function testUnknownLocaleFallsBackToTheSourceBundle(): void
    {
        $t = $this->translator(['en' => ['k' => 'Source']], 'de');

        self::assertSame('Source', $t->trans('k', 'Default'));
    }

    // ------------------------------------------------------- missing-key policy

    public function testSilentPolicyLogsNothing(): void
    {
        $logger = new CollectingLogger();
        $this->translator(['cs' => [], 'en' => []], policy: MissingKeyPolicy::Silent, logger: $logger)
            ->trans('missing.key', 'D');

        self::assertSame([], $logger->records);
    }

    public function testLogAndFallbackPolicyWarns(): void
    {
        $logger = new CollectingLogger();
        $result = $this->translator(['cs' => [], 'en' => []], policy: MissingKeyPolicy::LogAndFallback, logger: $logger)
            ->trans('missing.key', 'D');

        self::assertSame('D', $result);
        self::assertTrue($logger->hasRecordContaining('warning', 'Missing translation'));
        self::assertSame(
            ['key' => 'missing.key', 'locale' => 'cs'],
            $logger->contextOfFirstContaining('Missing translation'),
        );
    }

    public function testThrowInDevPolicyOnlyWarnsOutsideDevelopment(): void
    {
        $logger = new CollectingLogger();
        $result = $this->translator(
            ['cs' => [], 'en' => []],
            policy: MissingKeyPolicy::ThrowInDev,
            dev: false,
            logger: $logger,
        )->trans('missing.key', 'Production default');

        self::assertSame('Production default', $result);
        self::assertTrue($logger->hasRecordContaining('warning', 'Missing translation'));
    }

    public function testMissingTranslationExceptionCarriesKeyAndLocale(): void
    {
        $t = $this->translator(['cs' => [], 'en' => []], policy: MissingKeyPolicy::ThrowInDev, dev: true);

        try {
            $t->trans('some.key', 'D');
            self::fail('Expected MissingTranslationException');
        } catch (MissingTranslationException $e) {
            self::assertStringContainsString('some.key', $e->getMessage());
            self::assertStringContainsString('cs', $e->getMessage());
        }
    }

    public function testAFoundKeyNeverTriggersTheMissingPolicy(): void
    {
        $t = $this->translator(['cs' => ['k' => 'V']], policy: MissingKeyPolicy::ThrowInDev, dev: true);

        self::assertSame('V', $t->trans('k', 'D'));
    }

    // ------------------------------------------------------------- formatting

    public function testDefaultIsAlsoFormattedWithParameters(): void
    {
        $t = $this->translator(['cs' => [], 'en' => []], policy: MissingKeyPolicy::Silent);

        self::assertSame('Hello Petr', $t->trans('missing', 'Hello {name}', ['name' => 'Petr']));
    }

    public function testMultipleParametersAreSubstituted(): void
    {
        $t = $this->translator(['cs' => ['k' => '{greeting}, {name}!']]);

        self::assertSame('Ahoj, Petr!', $t->trans('k', 'D', ['greeting' => 'Ahoj', 'name' => 'Petr']));
    }

    public function testNumericParametersAreStringified(): void
    {
        $t = $this->translator(['cs' => ['k' => 'Count: {n}']]);

        self::assertSame('Count: 42', $t->trans('k', 'D', ['n' => 42]));
    }

    public function testStringableParametersAreAccepted(): void
    {
        $stringable = new class implements \Stringable {
            public function __toString(): string
            {
                return 'stringable value';
            }
        };
        $t = $this->translator(['cs' => ['k' => 'X: {v}']]);

        self::assertSame('X: stringable value', $t->trans('k', 'D', ['v' => $stringable]));
    }

    public function testUnusedParametersLeaveTheMessageIntact(): void
    {
        $t = $this->translator(['cs' => ['k' => 'No placeholders here']]);

        self::assertSame('No placeholders here', $t->trans('k', 'D', ['unused' => 'x']));
    }

    #[RequiresPhpExtension('intl')]
    public function testIcuPluralSelection(): void
    {
        $t = $this->translator(
            ['en' => ['k' => '{n, plural, one {# item} other {# items}}']],
            locale: 'en',
        );

        self::assertSame('1 item', $t->trans('k', 'D', ['n' => 1]));
        self::assertSame('5 items', $t->trans('k', 'D', ['n' => 5]));
    }

    /**
     * A broken ICU pattern must not take the page down — it degrades to plain `{var}`
     * substitution and leaves a warning behind.
     */
    #[RequiresPhpExtension('intl')]
    public function testInvalidIcuPatternWarnsAndFallsBackToPlainSubstitution(): void
    {
        $logger = new CollectingLogger();
        $t = $this->translator(
            ['en' => ['k' => 'Broken {n, plural, one {# item']],
            locale: 'en',
            logger: $logger,
        );

        $result = $t->trans('k', 'D', ['n' => 1]);

        self::assertStringContainsString('Broken', $result);
        self::assertTrue(
            $logger->hasRecordContaining('warning', 'MessageFormatter')
            || $logger->hasRecordContaining('warning', 'invalid ICU pattern'),
        );
    }
}
