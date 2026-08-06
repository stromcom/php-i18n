<?php

declare(strict_types=1);

namespace Stromcom\I18n\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Stromcom\I18n\Config\I18nConfig;
use Stromcom\I18n\Runtime\LocaleContext;
use Stromcom\I18n\Runtime\MissingKeyPolicy;
use Stromcom\I18n\Runtime\Translator;
use Stromcom\I18n\Runtime\XsltRenderer;
use Stromcom\I18n\Scan\XsltScanner;
use Stromcom\I18n\Tests\Support\CollectingLogger;
use Stromcom\I18n\Tests\Support\InMemoryBundleLoader;
use Stromcom\I18n\Tests\Support\TmpDir;

/**
 * Scanner and renderer share one contract: whatever `XsltScanner` uploads as
 * `source_text` is what `XsltRenderer` will format at runtime. These tests drive both
 * halves over the *same* stylesheet, so a change that breaks the agreement — such as
 * leaving XSLT's AVT brace escaping in the synced string — fails here even when both
 * unit suites still pass on their own.
 */
#[CoversClass(XsltScanner::class)]
#[CoversClass(XsltRenderer::class)]
#[CoversClass(Translator::class)]
final class XsltRoundTripTest extends TestCase
{
    private TmpDir $tmp;

    protected function setUp(): void
    {
        $this->tmp = new TmpDir('stromcom-i18n-xslt-roundtrip');
    }

    protected function tearDown(): void
    {
        $this->tmp->cleanup();
    }

    /**
     * The plural counter, written the way an XSLT author has to write it: doubled braces
     * for the literal ICU syntax, a single-brace AVT for the runtime value.
     */
    private function pluralStylesheet(): string
    {
        return <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <xsl:stylesheet version="1.0"
                        xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
                        xmlns:i18n="https://stromcom.cz/i18n"
                        exclude-result-prefixes="i18n">
            <xsl:output method="xml" indent="no" omit-xml-declaration="yes" encoding="UTF-8"/>
            <xsl:template match="/">
                <span><i18n:t key="cart.itemCount"
                              default="{{count, plural, one {{# item}} other {{# items}}}}"
                              count="{/cart/@items}"
                              note="Counter in the sidebar"/></span>
            </xsl:template>
        </xsl:stylesheet>
        XML;
    }

    /**
     * Strips the XML declaration so a test can assert on the payload alone.
     *
     * Pass 2 re-serialises the post-processed DOM with `saveXML()`, which always emits a
     * declaration — the stylesheet's `omit-xml-declaration="yes"` applies to XSLT's own
     * serialiser and never reaches the output. See
     * {@see testOmitXmlDeclarationIsNotHonouredForXmlOutput}.
     */
    private function body(string $output): string
    {
        return trim(preg_replace('#^<\?xml[^?]*\?>#', '', $output) ?? $output);
    }

    /**
     * @param array<string, array<string, string>> $bundles
     */
    private function makeRenderer(array $bundles, string $locale): XsltRenderer
    {
        $config = new I18nConfig(
            projectId: 't',
            token: '',
            baseUrl: 'https://e.test',
            sourceLocale: 'en',
            targetLocales: ['cs', 'en'],
            fallbackLocale: 'en',
            bundlesDir: '/tmp',
            scanPaths: [],
            missingKeyPolicy: MissingKeyPolicy::Silent,
        );
        $context = new LocaleContext($config);
        $context->set($locale);
        $translator = new Translator($config, new InMemoryBundleLoader($bundles), $context, new NullLogger());

        return new XsltRenderer($translator, $context);
    }

    /**
     * The scanned `source_text` must be a pattern MessageFormatter can compile. Before the
     * AVT unescaping this was `{{count, plural, …}}`, which ICU rejects outright.
     */
    #[RequiresPhpExtension('intl')]
    public function testScannedSourceTextIsAValidIcuPattern(): void
    {
        $path = $this->tmp->write('cart.xsl', $this->pluralStylesheet());
        $keys = (new XsltScanner(new NullLogger()))->scanFile($path, 'tpl/cart.xsl');

        self::assertCount(1, $keys);
        self::assertSame('{count, plural, one {# item} other {# items}}', $keys[0]->sourceText);
        self::assertSame('Counter in the sidebar', $keys[0]->description);
        self::assertNotNull(
            \MessageFormatter::create('en', $keys[0]->sourceText),
            'source_text synced to the platform must compile as an ICU pattern',
        );
    }

    /**
     * The full loop: scan the stylesheet, treat the scanned `source_text` as the value the
     * platform stores for the source locale, then render the same stylesheet against it.
     *
     * @param positive-int $items
     */
    #[RequiresPhpExtension('intl')]
    #[DataProvider('englishPluralProvider')]
    public function testScannedSourceTextFormatsCorrectlyWhenRendered(int $items, string $expected): void
    {
        $path = $this->tmp->write('cart.xsl', $this->pluralStylesheet());
        $keys = (new XsltScanner(new NullLogger()))->scanFile($path, 'tpl/cart.xsl');
        $bundle = ['en' => [$keys[0]->name => $keys[0]->sourceText]];

        $output = $this->makeRenderer($bundle, 'en')->render(
            $path,
            '<?xml version="1.0"?><cart items="' . $items . '"/>',
        );

        self::assertSame('<span>' . $expected . '</span>', $this->body($output));
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function englishPluralProvider(): iterable
    {
        yield 'one'  => [1, '1 item'];
        yield 'two'  => [2, '2 items'];
        yield 'many' => [42, '42 items'];
        yield 'zero' => [0, '0 items'];
    }

    /**
     * Czech has an extra `few` category, so a translated bundle exercises plural
     * selection that English alone would not catch.
     *
     * @param int<0, max> $items
     */
    #[RequiresPhpExtension('intl')]
    #[DataProvider('czechPluralProvider')]
    public function testTranslatedPluralBundleSelectsTheRightCategory(int $items, string $expected): void
    {
        $path = $this->tmp->write('cart.xsl', $this->pluralStylesheet());
        $bundle = [
            'cs' => ['cart.itemCount' => '{count, plural, one {# položka} few {# položky} other {# položek}}'],
        ];

        $output = $this->makeRenderer($bundle, 'cs')->render(
            $path,
            '<?xml version="1.0"?><cart items="' . $items . '"/>',
        );

        self::assertSame('<span>' . $expected . '</span>', $this->body($output));
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function czechPluralProvider(): iterable
    {
        yield 'one'   => [1, '1 položka'];
        yield 'few'   => [3, '3 položky'];
        yield 'other' => [10, '10 položek'];
    }

    /**
     * Pass 2 rebuilds the output with `saveXML()`, so XSLT's own serialisation options are
     * bypassed: `omit-xml-declaration="yes"` has no effect on XML output. Callers that
     * need a bare fragment must strip the declaration themselves or use `method="html"`.
     */
    public function testOmitXmlDeclarationIsNotHonouredForXmlOutput(): void
    {
        $path = $this->tmp->write('cart.xsl', $this->pluralStylesheet());

        $output = $this->makeRenderer(['en' => []], 'en')->render(
            $path,
            '<?xml version="1.0"?><cart items="1"/>',
        );

        self::assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $output);
    }

    /**
     * Without ext-intl the runtime degrades to plain `{var}` substitution — the ICU
     * pattern is not understood, but the render must not blow up.
     */
    public function testRenderSurvivesWithoutIcuFormatting(): void
    {
        $path = $this->tmp->write('cart.xsl', $this->pluralStylesheet());
        $bundle = ['en' => ['cart.itemCount' => 'You have {count} items']];

        $output = $this->makeRenderer($bundle, 'en')->render(
            $path,
            '<?xml version="1.0"?><cart items="5"/>',
        );

        self::assertSame('<span>You have 5 items</span>', $this->body($output));
    }

    /**
     * Every key the scanner reports must actually resolve when the same stylesheet is
     * rendered — no leftover `<i18n:t>` and no untranslated default.
     */
    public function testEveryScannedKeyResolvesAtRenderTime(): void
    {
        $xsl = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <xsl:stylesheet version="1.0"
                        xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
                        xmlns:i18n="https://stromcom.cz/i18n"
                        exclude-result-prefixes="i18n">
            <xsl:output method="xml" indent="no" omit-xml-declaration="yes" encoding="UTF-8"/>
            <xsl:template match="/">
                <page>
                    <h1><i18n:t key="page.title" default="Dashboard"/></h1>
                    <xsl:if test="/data/@admin">
                        <p><i18n:t key="page.adminHint" default="You are an admin"/></p>
                    </xsl:if>
                    <ul>
                        <xsl:for-each select="/data/item">
                            <li><i18n:t key="item.label" default="Item {{n}}" n="{@n}"/></li>
                        </xsl:for-each>
                    </ul>
                    <footer><i18n:t key="page.footer" default="Tom &amp; Jerry Ltd."/></footer>
                </page>
            </xsl:template>
        </xsl:stylesheet>
        XML;
        $path = $this->tmp->write('page.xsl', $xsl);

        $keys = (new XsltScanner(new NullLogger()))->scanFile($path, 'tpl/page.xsl');
        self::assertCount(4, $keys);

        // Simulate the platform: every scanned key comes back with a cs translation.
        $bundle = ['cs' => []];
        foreach ($keys as $key) {
            $bundle['cs'][$key->name] = 'CS:' . $key->sourceText;
        }

        $output = $this->makeRenderer($bundle, 'cs')->render(
            $path,
            '<?xml version="1.0"?><data admin="1"><item n="1"/><item n="2"/></data>',
        );

        self::assertStringNotContainsString('i18n:t', $output);
        self::assertStringContainsString('<h1>CS:Dashboard</h1>', $output);
        self::assertStringContainsString('<p>CS:You are an admin</p>', $output);
        self::assertStringContainsString('<li>CS:Item 1</li>', $output);
        self::assertStringContainsString('<li>CS:Item 2</li>', $output);
        self::assertStringContainsString('<footer>CS:Tom &amp; Jerry Ltd.</footer>', $output);
    }

    /**
     * Documents a known asymmetry: `<xsl:attribute>` renders fine but is invisible to the
     * scanner, so the key never reaches the platform. The scanner warns rather than
     * dropping it silently — if this ever starts syncing, the warning can go.
     */
    public function testXslAttributeFormRendersButIsNotScanned(): void
    {
        $xsl = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <xsl:stylesheet version="1.0"
                        xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
                        xmlns:i18n="https://stromcom.cz/i18n"
                        exclude-result-prefixes="i18n">
            <xsl:output method="xml" indent="no" omit-xml-declaration="yes" encoding="UTF-8"/>
            <xsl:param name="user_name" select="''"/>
            <xsl:template match="/">
                <p><i18n:t key="greet">
                    <xsl:attribute name="default">Hello {name}</xsl:attribute>
                    <xsl:attribute name="name"><xsl:value-of select="$user_name"/></xsl:attribute>
                </i18n:t></p>
            </xsl:template>
        </xsl:stylesheet>
        XML;
        $path = $this->tmp->write('greet.xsl', $xsl);

        $logger = new CollectingLogger();
        $keys = (new XsltScanner($logger))->scanFile($path, 'tpl/greet.xsl');

        self::assertSame([], $keys, 'the <xsl:attribute> form cannot be scanned');
        self::assertTrue($logger->hasRecordContaining('warning', 'sets @default via <xsl:attribute>'));

        $output = $this->makeRenderer(['cs' => []], 'cs')->render(
            $path,
            '<?xml version="1.0"?><data/>',
            xsltParams: ['user_name' => 'Petr'],
        );

        self::assertSame('<p>Hello Petr</p>', $this->body($output));
    }
}
