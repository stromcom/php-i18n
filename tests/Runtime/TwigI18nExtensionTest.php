<?php

declare(strict_types=1);

namespace Stromcom\I18n\Tests\Runtime;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Stromcom\I18n\Config\I18nConfig;
use Stromcom\I18n\Runtime\LocaleContext;
use Stromcom\I18n\Runtime\MissingKeyPolicy;
use Stromcom\I18n\Runtime\Translator;
use Stromcom\I18n\Runtime\TwigI18nExtension;
use Stromcom\I18n\Tests\Support\InMemoryBundleLoader;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\TwigFunction;

#[CoversClass(TwigI18nExtension::class)]
final class TwigI18nExtensionTest extends TestCase
{
    /** @var array<mixed> */
    private array $serverBackup = [];

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
    }

    private function config(): I18nConfig
    {
        return new I18nConfig(
            projectId: 't',
            token: '',
            baseUrl: 'https://e.test',
            sourceLocale: 'en',
            targetLocales: ['cs', 'en', 'de'],
            fallbackLocale: 'en',
            bundlesDir: '/tmp',
            scanPaths: [],
            missingKeyPolicy: MissingKeyPolicy::Silent,
        );
    }

    /**
     * @param array<string, array<string, string>> $bundles
     */
    private function extension(array $bundles = [], string $locale = 'cs'): TwigI18nExtension
    {
        $config = $this->config();
        $context = new LocaleContext($config);
        $context->set($locale);
        $translator = new Translator($config, new InMemoryBundleLoader($bundles), $context, new NullLogger());

        return new TwigI18nExtension($translator, $context, $config);
    }

    /**
     * @param array<string, string> $templates
     */
    private function twig(TwigI18nExtension $extension, array $templates): Environment
    {
        $env = new Environment(new ArrayLoader($templates), ['strict_variables' => true, 'cache' => false]);
        $env->addExtension($extension);

        return $env;
    }

    // ----------------------------------------------------------- registration

    public function testRegistersTheExpectedFunctions(): void
    {
        $names = array_map(
            static fn (TwigFunction $f): string => $f->getName(),
            $this->extension()->getFunctions(),
        );

        self::assertSame(['t', 'locale_switch_url'], $names);
    }

    public function testExposesTheLocaleGlobals(): void
    {
        $globals = $this->extension(locale: 'de')->getGlobals();

        self::assertSame('de', $globals['current_locale']);
        self::assertSame(['cs', 'en', 'de'], $globals['available_locales']);
    }

    public function testCurrentLocaleGlobalFollowsTheContext(): void
    {
        $config = $this->config();
        $context = new LocaleContext($config);
        $translator = new Translator($config, new InMemoryBundleLoader([]), $context, new NullLogger());
        $extension = new TwigI18nExtension($translator, $context, $config);

        $context->set('cs');
        self::assertSame('cs', $extension->getGlobals()['current_locale']);

        $context->set('en');
        self::assertSame('en', $extension->getGlobals()['current_locale']);
    }

    // ------------------------------------------------------------------ trans

    public function testTransReturnsTheTranslation(): void
    {
        $extension = $this->extension(['cs' => ['login.submit' => 'Přihlásit']]);

        self::assertSame('Přihlásit', $extension->trans('login.submit', 'Sign in'));
    }

    public function testTransFallsBackToTheDefault(): void
    {
        self::assertSame('Sign in', $this->extension(['cs' => []])->trans('login.submit', 'Sign in'));
    }

    public function testTransSubstitutesParameters(): void
    {
        $extension = $this->extension(['cs' => ['greet' => 'Ahoj {name}']]);

        self::assertSame('Ahoj Petr', $extension->trans('greet', 'Hello {name}', ['name' => 'Petr']));
    }

    // -------------------------------------------------------- Twig rendering

    public function testTFunctionRendersInsideATemplate(): void
    {
        $twig = $this->twig(
            $this->extension(['cs' => ['page.title' => 'Vítejte']]),
            ['page' => '{{ t("page.title", "Welcome") }}'],
        );

        self::assertSame('Vítejte', $twig->render('page'));
    }

    public function testTFunctionWithParametersRendersInsideATemplate(): void
    {
        $twig = $this->twig(
            $this->extension(['cs' => ['greet' => 'Ahoj {name}']]),
            ['page' => '{{ t("greet", "Hello {name}", { name: user }) }}'],
        );

        self::assertSame('Ahoj Jana', $twig->render('page', ['user' => 'Jana']));
    }

    public function testGlobalsAreAvailableInATemplate(): void
    {
        $twig = $this->twig(
            $this->extension(locale: 'de'),
            ['page' => '{{ current_locale }}:{{ available_locales|join(",") }}'],
        );

        self::assertSame('de:cs,en,de', $twig->render('page'));
    }

    // -------------------------------------------------------- localeSwitchUrl

    public function testLocaleSwitchUrlAddsTheLocaleToABarePath(): void
    {
        $_SERVER['REQUEST_URI'] = '/products';

        self::assertSame('/products?locale=de', $this->extension()->localeSwitchUrl('de'));
    }

    public function testLocaleSwitchUrlPreservesExistingQueryParameters(): void
    {
        $_SERVER['REQUEST_URI'] = '/products?page=2&sort=name';

        self::assertSame('/products?page=2&sort=name&locale=de', $this->extension()->localeSwitchUrl('de'));
    }

    public function testLocaleSwitchUrlReplacesAnExistingLocaleParameter(): void
    {
        $_SERVER['REQUEST_URI'] = '/products?locale=cs&page=2';

        self::assertSame('/products?locale=de&page=2', $this->extension()->localeSwitchUrl('de'));
    }

    public function testLocaleSwitchUrlDefaultsToTheRootPath(): void
    {
        unset($_SERVER['REQUEST_URI']);

        self::assertSame('/?locale=de', $this->extension()->localeSwitchUrl('de'));
    }

    public function testLocaleSwitchUrlHandlesANonStringRequestUri(): void
    {
        $_SERVER['REQUEST_URI'] = ['unexpected'];

        self::assertSame('/?locale=de', $this->extension()->localeSwitchUrl('de'));
    }

    public function testLocaleSwitchUrlHandlesAnEmptyQueryString(): void
    {
        $_SERVER['REQUEST_URI'] = '/products?';

        self::assertSame('/products?locale=de', $this->extension()->localeSwitchUrl('de'));
    }

    public function testLocaleSwitchUrlEncodesTheValue(): void
    {
        $_SERVER['REQUEST_URI'] = '/p';

        self::assertSame('/p?locale=pt%2FBR', $this->extension()->localeSwitchUrl('pt/BR'));
    }

    /**
     * Twig's HTML autoescaping turns the `&` separator into `&amp;`, which is what an
     * `href` attribute actually needs — the raw string is asserted separately above.
     */
    public function testLocaleSwitchUrlRendersInATemplate(): void
    {
        $_SERVER['REQUEST_URI'] = '/products?page=2';
        $twig = $this->twig($this->extension(), ['page' => '{{ locale_switch_url("en") }}']);

        self::assertSame('/products?page=2&amp;locale=en', $twig->render('page'));
    }
}
