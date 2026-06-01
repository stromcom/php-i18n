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
}
