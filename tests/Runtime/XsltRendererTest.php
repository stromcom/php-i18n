<?php

declare(strict_types=1);

namespace Stromcom\I18n\Tests\Runtime;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Stromcom\I18n\Config\I18nConfig;
use Stromcom\I18n\Runtime\LocaleContext;
use Stromcom\I18n\Runtime\MissingKeyPolicy;
use Stromcom\I18n\Runtime\Translator;
use Stromcom\I18n\Runtime\XsltRenderer;
use Stromcom\I18n\Runtime\XsltRendererException;
use Stromcom\I18n\Tests\Support\InMemoryBundleLoader;
use Stromcom\I18n\Tests\Support\TmpDir;

#[CoversClass(XsltRenderer::class)]
#[CoversClass(XsltRendererException::class)]
final class XsltRendererTest extends TestCase
{
    private TmpDir $tmp;

    protected function setUp(): void
    {
        $this->tmp = new TmpDir('stromcom-i18n-xslt-render');
    }

    protected function tearDown(): void
    {
        $this->tmp->cleanup();
    }

    /**
     * @param array<string, array<string, string>> $bundles
     */
    private function makeRenderer(array $bundles, string $activeLocale = 'cs'): XsltRenderer
    {
        $config = new I18nConfig(
            projectId: 't',
            token: '',
            baseUrl: 'https://e.test',
            sourceLocale: 'en',
            targetLocales: ['cs', 'en', 'de', 'sk'],
            fallbackLocale: 'en',
            bundlesDir: '/tmp',
            scanPaths: [],
            missingKeyPolicy: MissingKeyPolicy::Silent,
        );
        $context = new LocaleContext($config);
        $context->set($activeLocale);
        $loader = new InMemoryBundleLoader($bundles);
        $translator = new Translator($config, $loader, $context, new NullLogger());

        return new XsltRenderer($translator, $context);
    }

    /**
     * Helper: builds an XSL stylesheet with the i18n namespace and `exclude-result-prefixes="i18n"`
     * (so the `xmlns:i18n=` declaration doesn't linger in the output XML).
     */
    private function buildStylesheet(string $bodyTemplate, string $outputMethod = 'xml'): string
    {
        return <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <xsl:stylesheet version="1.0"
                        xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
                        xmlns:i18n="https://stromcom.cz/i18n"
                        exclude-result-prefixes="i18n">
            <xsl:output method="{$outputMethod}" indent="no" omit-xml-declaration="yes" encoding="UTF-8"/>
            <xsl:template match="/">
                {$bodyTemplate}
            </xsl:template>
        </xsl:stylesheet>
        XML;
    }

    public function testReplacesI18nTElementWithTranslation(): void
    {
        $xsl = $this->buildStylesheet('<root><h1><i18n:t key="page.title" default="Welcome"/></h1></root>');
        $xslPath = $this->tmp->write('stylesheet.xsl', $xsl);

        $renderer = $this->makeRenderer(['cs' => ['page.title' => 'Vítejte']]);
        $output = $renderer->render($xslPath, '<?xml version="1.0"?><data/>');

        self::assertStringContainsString('<h1>Vítejte</h1>', $output);
    }

    public function testFallsBackToDefaultWhenKeyMissing(): void
    {
        $xsl = $this->buildStylesheet('<p><i18n:t key="missing.key" default="Fallback text"/></p>');
        $xslPath = $this->tmp->write('stylesheet.xsl', $xsl);

        $renderer = $this->makeRenderer(['cs' => []]);
        $output = $renderer->render($xslPath, '<?xml version="1.0"?><data/>');

        self::assertStringContainsString('<p>Fallback text</p>', $output);
    }

    public function testIcuParamsFromAttributesWithAvt(): void
    {
        if (!extension_loaded('intl')) {
            self::markTestSkipped('ext-intl required for ICU plural test');
        }

        // The ICU template in `default` must be `{{count, plural, ...}}` (XSLT 1.0
        // AVT escape — a single `{` in an attribute would be interpreted as an XPath
        // expression). We fill the dynamic `count` value via AVT from the data: `count="{...}"`.
        $body = '<span><i18n:t key="cart.count" '
              . 'default="{{count, plural, one {{# item}} other {{# items}}}}" '
              . 'count="{data/@items}"/></span>';
        $xsl = $this->buildStylesheet($body);
        $xslPath = $this->tmp->write('stylesheet.xsl', $xsl);

        $renderer = $this->makeRenderer(['en' => []], 'en');
        $output = $renderer->render($xslPath, '<?xml version="1.0"?><data items="5"/>');

        self::assertStringContainsString('<span>5 items</span>', $output);
    }

    public function testNoteAttributeIsIgnoredAsParam(): void
    {
        // `{{name}}` is the XSLT 1.0 AVT escape for the literal `{name}` → ICU placeholder.
        $body = '<p><i18n:t key="x" default="Hello {{name}}" name="World" note="dev note"/></p>';
        $xsl = $this->buildStylesheet($body);
        $xslPath = $this->tmp->write('stylesheet.xsl', $xsl);

        $renderer = $this->makeRenderer(['cs' => []]);
        $output = $renderer->render($xslPath, '<?xml version="1.0"?><data/>');

        self::assertStringContainsString('<p>Hello World</p>', $output);
        self::assertStringNotContainsString('dev note', $output);
    }

    public function testMultipleElementsInOneDocument(): void
    {
        $body = '<root>'
              . '<h1><i18n:t key="a" default="A"/></h1>'
              . '<p><i18n:t key="b" default="B"/></p>'
              . '<small><i18n:t key="c" default="C"/></small>'
              . '</root>';
        $xsl = $this->buildStylesheet($body);
        $xslPath = $this->tmp->write('stylesheet.xsl', $xsl);

        $renderer = $this->makeRenderer(['cs' => ['a' => 'Á', 'b' => 'Bé', 'c' => 'Cé']]);
        $output = $renderer->render($xslPath, '<?xml version="1.0"?><data/>');

        self::assertStringContainsString('<h1>Á</h1>', $output);
        self::assertStringContainsString('<p>Bé</p>', $output);
        self::assertStringContainsString('<small>Cé</small>', $output);
    }

    public function testIncompleteI18nTElementIsRemoved(): void
    {
        $body = '<p>before<i18n:t key="ok"/>after</p>';
        $xsl = $this->buildStylesheet($body);
        $xslPath = $this->tmp->write('stylesheet.xsl', $xsl);

        $renderer = $this->makeRenderer(['cs' => []]);
        $output = $renderer->render($xslPath, '<?xml version="1.0"?><data/>');

        self::assertStringContainsString('<p>beforeafter</p>', $output);
        self::assertStringNotContainsString('i18n:t', $output);
    }

    public function testElementWithDefaultButNoKeyIsRemoved(): void
    {
        $body = '<p>before<i18n:t default="orphan default"/>after</p>';
        $xslPath = $this->tmp->write('stylesheet.xsl', $this->buildStylesheet($body));

        $output = $this->makeRenderer(['cs' => []])->render($xslPath, '<?xml version="1.0"?><data/>');

        self::assertStringContainsString('<p>beforeafter</p>', $output);
        self::assertStringNotContainsString('orphan default', $output);
    }

    public function testElementWithEmptyKeyAndDefaultIsRemoved(): void
    {
        $body = '<p>before<i18n:t key="" default=""/>after</p>';
        $xslPath = $this->tmp->write('stylesheet.xsl', $this->buildStylesheet($body));

        $output = $this->makeRenderer(['cs' => []])->render($xslPath, '<?xml version="1.0"?><data/>');

        self::assertStringContainsString('<p>beforeafter</p>', $output);
    }

    public function testCompleteElementsSurviveAlongsideAnIncompleteOne(): void
    {
        $body = '<root><p><i18n:t key="ok" default="Fine"/></p><p><i18n:t key="bad"/></p></root>';
        $xslPath = $this->tmp->write('stylesheet.xsl', $this->buildStylesheet($body));

        $output = $this->makeRenderer(['cs' => ['ok' => 'Dobré']])->render(
            $xslPath,
            '<?xml version="1.0"?><data/>',
        );

        self::assertStringContainsString('<p>Dobré</p>', $output);
        self::assertStringContainsString('<p/>', $output);
    }

    public function testLocaleParameterOverridesContext(): void
    {
        $body = '<p><i18n:t key="hi" default="Hi"/></p>';
        $xsl = $this->buildStylesheet($body);
        $xslPath = $this->tmp->write('stylesheet.xsl', $xsl);

        $renderer = $this->makeRenderer(
            ['cs' => ['hi' => 'Ahoj'], 'de' => ['hi' => 'Hallo']],
            activeLocale: 'cs',
        );

        $cs = $renderer->render($xslPath, '<?xml version="1.0"?><data/>');
        $de = $renderer->render($xslPath, '<?xml version="1.0"?><data/>', locale: 'de');

        self::assertStringContainsString('Ahoj', $cs);
        self::assertStringContainsString('Hallo', $de);
    }

    public function testXsltParametersArePropagated(): void
    {
        // <xsl:attribute> bypasses AVT — the text content inside the attribute can have
        // `{name}` as a literal (an ICU placeholder for the translator). The other way
        // is `{{name}}` in an inline attribute, but here we demonstrate the safer syntax.
        $body = '<xsl:param name="user_name" select="\'\'"/>'
              . '<p><i18n:t key="greet">'
              . '<xsl:attribute name="default">Hello {name}</xsl:attribute>'
              . '<xsl:attribute name="name"><xsl:value-of select="$user_name"/></xsl:attribute>'
              . '</i18n:t></p>';
        // Note — xsl:param must be at the top level, not inside xsl:template
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
        $xslPath = $this->tmp->write('stylesheet.xsl', $xsl);

        $renderer = $this->makeRenderer(['cs' => []], 'en');
        $output = $renderer->render(
            $xslPath,
            '<?xml version="1.0"?><data/>',
            xsltParams: ['user_name' => 'Petr'],
        );

        self::assertStringContainsString('<p>Hello Petr</p>', $output);
    }

    public function testThrowsOnMissingStylesheet(): void
    {
        $renderer = $this->makeRenderer([]);
        $this->expectException(XsltRendererException::class);
        $renderer->render('/nonexistent/stylesheet.xsl', '<?xml version="1.0"?><data/>');
    }

    public function testThrowsOnInvalidXmlInput(): void
    {
        $xsl = $this->buildStylesheet('<root/>');
        $xslPath = $this->tmp->write('stylesheet.xsl', $xsl);

        $renderer = $this->makeRenderer([]);
        $this->expectException(XsltRendererException::class);
        $renderer->render($xslPath, '<not><well-formed</not>');
    }

    public function testHtmlOutputMethodDetectedFromStylesheet(): void
    {
        $body = '<html><body><h1><i18n:t key="t" default="Hello"/></h1></body></html>';
        $xsl = $this->buildStylesheet($body, outputMethod: 'html');
        $xslPath = $this->tmp->write('stylesheet.xsl', $xsl);

        $renderer = $this->makeRenderer(['cs' => []]);
        $output = $renderer->render($xslPath, '<?xml version="1.0"?><data/>');

        // saveHTML output: no XML declaration, doctype need not be present
        self::assertStringContainsString('<h1>Hello</h1>', $output);
        self::assertStringNotContainsString('<?xml', $output);
    }

    // ------------------------------------------------------------ output format

    public function testTextOutputMethodDetectedFromStylesheet(): void
    {
        $body = '<root><h1><i18n:t key="a" default="Alpha"/></h1><p><i18n:t key="b" default="Beta"/></p></root>';
        $xsl = $this->buildStylesheet($body, outputMethod: 'text');
        $xslPath = $this->tmp->write('stylesheet.xsl', $xsl);

        $renderer = $this->makeRenderer(['cs' => ['a' => 'Alfa']]);
        $output = $renderer->render($xslPath, '<?xml version="1.0"?><data/>');

        self::assertSame('AlfaBeta', $output);
    }

    public function testExplicitOutputFormatOverridesStylesheetDeclaration(): void
    {
        $body = '<root><p><i18n:t key="a" default="Alpha"/></p></root>';
        $xsl = $this->buildStylesheet($body, outputMethod: 'xml');
        $xslPath = $this->tmp->write('stylesheet.xsl', $xsl);
        $renderer = $this->makeRenderer(['cs' => []]);

        $asText = $renderer->render($xslPath, '<?xml version="1.0"?><data/>', outputFormat: 'text');
        $asHtml = $renderer->render($xslPath, '<?xml version="1.0"?><data/>', outputFormat: 'html');
        $asXml = $renderer->render($xslPath, '<?xml version="1.0"?><data/>');

        self::assertSame('Alpha', $asText);
        self::assertStringNotContainsString('<?xml', $asHtml);
        self::assertStringContainsString('<?xml', $asXml);
    }

    /**
     * `detectOutputFormat` only recognises html and text; everything else — including
     * an absent declaration or `method="xhtml"` — is XSLT 1.0's default, XML.
     */
    #[DataProvider('nonHtmlOutputDeclarationProvider')]
    public function testUnrecognisedOutputMethodFallsBackToXml(string $outputDeclaration): void
    {
        $xsl = <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <xsl:stylesheet version="1.0"
                        xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
                        xmlns:i18n="https://stromcom.cz/i18n"
                        exclude-result-prefixes="i18n">
            {$outputDeclaration}
            <xsl:template match="/"><root><i18n:t key="a" default="Alpha"/></root></xsl:template>
        </xsl:stylesheet>
        XML;
        $xslPath = $this->tmp->write('stylesheet.xsl', $xsl);

        $output = $this->makeRenderer(['cs' => []])->render($xslPath, '<?xml version="1.0"?><data/>');

        self::assertStringContainsString('<?xml', $output);
        self::assertStringContainsString('<root>Alpha</root>', $output);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonHtmlOutputDeclarationProvider(): iterable
    {
        yield 'no xsl:output'      => [''];
        yield 'method="xml"'       => ['<xsl:output method="xml" encoding="UTF-8"/>'];
        yield 'method="xhtml"'     => ['<xsl:output method="xhtml" encoding="UTF-8"/>'];
        yield 'output without method' => ['<xsl:output encoding="UTF-8" indent="no"/>'];
        yield 'uppercase HTML is matched case-insensitively, so use XML' => [
            '<xsl:output method="XML" encoding="UTF-8"/>',
        ];
    }

    public function testOutputMethodMatchIsCaseInsensitive(): void
    {
        $body = '<html><body><h1><i18n:t key="t" default="Hello"/></h1></body></html>';
        $xsl = $this->buildStylesheet($body, outputMethod: 'HTML');
        $xslPath = $this->tmp->write('stylesheet.xsl', $xsl);

        $output = $this->makeRenderer(['cs' => []])->render($xslPath, '<?xml version="1.0"?><data/>');

        self::assertStringNotContainsString('<?xml', $output);
    }

    // -------------------------------------------------------------- data input

    public function testAcceptsDomDocumentAsData(): void
    {
        $body = '<p><i18n:t key="a" default="A"/>:<xsl:value-of select="/data/@v"/></p>';
        $xslPath = $this->tmp->write('stylesheet.xsl', $this->buildStylesheet($body));

        $data = new \DOMDocument();
        self::assertTrue($data->loadXML('<?xml version="1.0"?><data v="42"/>'));

        $output = $this->makeRenderer(['cs' => ['a' => 'Á']])->render($xslPath, $data);

        self::assertStringContainsString('<p>Á:42</p>', $output);
    }

    // ---------------------------------------------------- variables and params

    /**
     * The `<xsl:variable>` → AVT → ICU parameter path: the variable is resolved during
     * pass 1, so pass 2 receives a concrete string.
     */
    public function testXslVariableFeedsIcuParameterThroughAvt(): void
    {
        $body = '<xsl:variable name="who" select="\'Petr\'"/>'
              . '<p><i18n:t key="greet" default="Hello {{name}}" name="{$who}"/></p>';
        $xslPath = $this->tmp->write('stylesheet.xsl', $this->buildStylesheet($body));

        $output = $this->makeRenderer(['cs' => []])->render($xslPath, '<?xml version="1.0"?><data/>');

        self::assertStringContainsString('<p>Hello Petr</p>', $output);
    }

    public function testAvtParameterFromXmlNodeValue(): void
    {
        $body = '<p><i18n:t key="greet" default="Hello {{name}}" name="{/data/customer/@firstName}"/></p>';
        $xslPath = $this->tmp->write('stylesheet.xsl', $this->buildStylesheet($body));

        $output = $this->makeRenderer(['cs' => []])->render(
            $xslPath,
            '<?xml version="1.0"?><data><customer firstName="Jana"/></data>',
        );

        self::assertStringContainsString('<p>Hello Jana</p>', $output);
    }

    public function testAvtParameterFromMissingNodeBecomesEmptyString(): void
    {
        $body = '<p><i18n:t key="greet" default="Hello {{name}}!" name="{/data/@nope}"/></p>';
        $xslPath = $this->tmp->write('stylesheet.xsl', $this->buildStylesheet($body));

        $output = $this->makeRenderer(['cs' => []])->render($xslPath, '<?xml version="1.0"?><data/>');

        self::assertStringContainsString('<p>Hello !</p>', $output);
    }

    public function testNonStringXsltParametersAreCastToString(): void
    {
        $xsl = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <xsl:stylesheet version="1.0"
                        xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
                        xmlns:i18n="https://stromcom.cz/i18n"
                        exclude-result-prefixes="i18n">
            <xsl:output method="xml" indent="no" omit-xml-declaration="yes" encoding="UTF-8"/>
            <xsl:param name="count"/>
            <xsl:param name="ratio"/>
            <xsl:param name="flag"/>
            <xsl:template match="/">
                <p><xsl:value-of select="$count"/>|<xsl:value-of select="$ratio"/>|<xsl:value-of select="$flag"/></p>
            </xsl:template>
        </xsl:stylesheet>
        XML;
        $xslPath = $this->tmp->write('stylesheet.xsl', $xsl);

        $output = $this->makeRenderer(['cs' => []])->render(
            $xslPath,
            '<?xml version="1.0"?><data/>',
            xsltParams: ['count' => 7, 'ratio' => 1.5, 'flag' => true],
        );

        self::assertStringContainsString('<p>7|1.5|1</p>', $output);
    }

    public function testSameKeyRenderedTwiceWithDifferentParameters(): void
    {
        $body = '<root>'
              . '<p><i18n:t key="greet" default="Hi {{name}}" name="{/data/a/@n}"/></p>'
              . '<p><i18n:t key="greet" default="Hi {{name}}" name="{/data/b/@n}"/></p>'
              . '</root>';
        $xslPath = $this->tmp->write('stylesheet.xsl', $this->buildStylesheet($body));

        $output = $this->makeRenderer(['cs' => ['greet' => 'Ahoj {name}']])->render(
            $xslPath,
            '<?xml version="1.0"?><data><a n="Jana"/><b n="Petr"/></data>',
        );

        self::assertStringContainsString('<p>Ahoj Jana</p>', $output);
        self::assertStringContainsString('<p>Ahoj Petr</p>', $output);
    }

    public function testTranslatesEveryIterationOfForEach(): void
    {
        $body = '<ul><xsl:for-each select="/data/row">'
              . '<li><i18n:t key="row.label" default="Row {{n}}" n="{@n}"/></li>'
              . '</xsl:for-each></ul>';
        $xslPath = $this->tmp->write('stylesheet.xsl', $this->buildStylesheet($body));

        $output = $this->makeRenderer(['cs' => ['row.label' => 'Řádek {n}']])->render(
            $xslPath,
            '<?xml version="1.0"?><data><row n="1"/><row n="2"/><row n="3"/></data>',
        );

        self::assertStringContainsString('<li>Řádek 1</li>', $output);
        self::assertStringContainsString('<li>Řádek 2</li>', $output);
        self::assertStringContainsString('<li>Řádek 3</li>', $output);
    }

    // ------------------------------------------------------------- escaping

    /**
     * Translations become text nodes, never markup — a translated string containing
     * `<b>` must not turn into an element.
     */
    public function testTranslationContainingMarkupIsEscaped(): void
    {
        $body = '<p><i18n:t key="m" default="plain"/></p>';
        $xslPath = $this->tmp->write('stylesheet.xsl', $this->buildStylesheet($body));

        $output = $this->makeRenderer(['cs' => ['m' => '<b>bold</b> & "quoted"']])->render(
            $xslPath,
            '<?xml version="1.0"?><data/>',
        );

        self::assertStringContainsString('&lt;b&gt;bold&lt;/b&gt; &amp;', $output);
        self::assertStringNotContainsString('<b>', $output);
    }

    public function testNonAsciiTranslationIsNotEscapedToNumericEntities(): void
    {
        $body = '<p><i18n:t key="cz" default="d"/></p>';
        $xslPath = $this->tmp->write('stylesheet.xsl', $this->buildStylesheet($body));

        $output = $this->makeRenderer(['cs' => ['cz' => 'Příliš žluťoučký kůň']])->render(
            $xslPath,
            '<?xml version="1.0"?><data/>',
        );

        self::assertStringContainsString('Příliš žluťoučký kůň', $output);
        self::assertStringNotContainsString('&#', $output);
    }

    public function testI18nTAsResultTreeRootIsReplaced(): void
    {
        $xslPath = $this->tmp->write(
            'stylesheet.xsl',
            $this->buildStylesheet('<i18n:t key="root" default="RootText"/>'),
        );

        $output = $this->makeRenderer(['cs' => ['root' => 'Koren']])->render(
            $xslPath,
            '<?xml version="1.0"?><data/>',
        );

        self::assertStringContainsString('Koren', $output);
        self::assertStringNotContainsString('i18n:t', $output);
    }

    // ------------------------------------------------------------ error paths

    public function testThrowsWhenStylesheetIsWellFormedXmlButNotAStylesheet(): void
    {
        $xslPath = $this->tmp->write('stylesheet.xsl', '<?xml version="1.0"?><notxsl/>');
        $renderer = $this->makeRenderer([]);

        $this->expectException(XsltRendererException::class);
        $this->expectExceptionMessage('XSL stylesheet could not be imported');
        $renderer->render($xslPath, '<?xml version="1.0"?><data/>');
    }

    public function testThrowsOnMalformedStylesheetXml(): void
    {
        $xslPath = $this->tmp->write('stylesheet.xsl', '<xsl:stylesheet><broken');
        $renderer = $this->makeRenderer([]);

        $this->expectException(XsltRendererException::class);
        $this->expectExceptionMessage('XSL stylesheet parse error');
        $renderer->render($xslPath, '<?xml version="1.0"?><data/>');
    }

    public function testThrowsOnUnreadableStylesheet(): void
    {
        $xslPath = $this->tmp->write('stylesheet.xsl', $this->buildStylesheet('<root/>'));
        self::assertTrue(chmod($xslPath, 0o000));
        $renderer = $this->makeRenderer([]);

        try {
            $this->expectException(XsltRendererException::class);
            $this->expectExceptionMessage('XSL stylesheet not readable');
            $renderer->render($xslPath, '<?xml version="1.0"?><data/>');
        } finally {
            chmod($xslPath, 0o600);
        }
    }

    public function testThrowsOnDirectoryPassedAsStylesheet(): void
    {
        $renderer = $this->makeRenderer([]);

        $this->expectException(XsltRendererException::class);
        $this->expectExceptionMessage('XSL stylesheet not readable');
        $renderer->render($this->tmp->path(), '<?xml version="1.0"?><data/>');
    }

    /**
     * A mistyped `xmlns:i18n` used to leave a raw `<i18n:t .../>` tag in the output and
     * silently drop the text. It must fail loudly instead.
     */
    public function testThrowsWhenElementNamespaceDoesNotMatch(): void
    {
        $xsl = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <xsl:stylesheet version="1.0"
                        xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
                        xmlns:i18n="http://stromcom.cz/i18n"
                        exclude-result-prefixes="i18n">
            <xsl:output method="xml" omit-xml-declaration="yes" encoding="UTF-8"/>
            <xsl:template match="/"><p><i18n:t key="typo.ns" default="WrongNs"/></p></xsl:template>
        </xsl:stylesheet>
        XML;
        $xslPath = $this->tmp->write('stylesheet.xsl', $xsl);
        $renderer = $this->makeRenderer(['cs' => []]);

        $this->expectException(XsltRendererException::class);
        $this->expectExceptionMessage('Unresolved <t> element');
        $this->expectExceptionMessage('typo.ns');
        $renderer->render($xslPath, '<?xml version="1.0"?><data/>');
    }

    /**
     * The renderer resolves by namespace URI, so a result root that binds the `i18n`
     * prefix to some other URI must not hijack resolution.
     */
    public function testResolutionFollowsNamespaceUriNotPrefix(): void
    {
        $xsl = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <xsl:stylesheet version="1.0"
                        xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
                        xmlns:tr="https://stromcom.cz/i18n"
                        exclude-result-prefixes="tr">
            <xsl:output method="xml" omit-xml-declaration="yes" encoding="UTF-8"/>
            <xsl:template match="/"><p><tr:t key="hi" default="Hi"/></p></xsl:template>
        </xsl:stylesheet>
        XML;
        $xslPath = $this->tmp->write('stylesheet.xsl', $xsl);

        $output = $this->makeRenderer(['cs' => ['hi' => 'Ahoj']])->render(
            $xslPath,
            '<?xml version="1.0"?><data/>',
        );

        self::assertStringContainsString('<p>Ahoj</p>', $output);
    }

    public function testForeignTElementWithoutKeyAndDefaultIsLeftAlone(): void
    {
        $body = '<root><t>not ours</t><p><i18n:t key="a" default="A"/></p></root>';
        $xslPath = $this->tmp->write('stylesheet.xsl', $this->buildStylesheet($body));

        $output = $this->makeRenderer(['cs' => []])->render($xslPath, '<?xml version="1.0"?><data/>');

        self::assertStringContainsString('<t>not ours</t>', $output);
        self::assertStringContainsString('<p>A</p>', $output);
    }

    public function testLibxmlErrorStateIsRestoredAfterRender(): void
    {
        $xslPath = $this->tmp->write('stylesheet.xsl', $this->buildStylesheet('<root/>'));
        $renderer = $this->makeRenderer(['cs' => []]);

        $before = libxml_use_internal_errors(false);
        try {
            $renderer->render($xslPath, '<?xml version="1.0"?><data/>');
            self::assertFalse(libxml_use_internal_errors(), 'render() must restore the previous libxml mode');
        } finally {
            libxml_use_internal_errors($before);
        }
    }

    public function testLibxmlErrorStateIsRestoredAfterFailedRender(): void
    {
        $renderer = $this->makeRenderer([]);

        $before = libxml_use_internal_errors(false);
        try {
            try {
                $renderer->render('/nonexistent/stylesheet.xsl', '<?xml version="1.0"?><data/>');
                self::fail('Expected XsltRendererException');
            } catch (XsltRendererException) {
                self::assertFalse(libxml_use_internal_errors());
            }
        } finally {
            libxml_use_internal_errors($before);
        }
    }
}
