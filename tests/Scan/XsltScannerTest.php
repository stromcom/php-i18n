<?php

declare(strict_types=1);

namespace Stromcom\I18n\Tests\Scan;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Stromcom\I18n\Scan\ScannedKey;
use Stromcom\I18n\Scan\XsltScanner;
use Stromcom\I18n\Tests\Support\CollectingLogger;
use Stromcom\I18n\Tests\Support\TmpDir;

#[CoversClass(XsltScanner::class)]
#[CoversClass(ScannedKey::class)]
final class XsltScannerTest extends TestCase
{
    private TmpDir $tmp;
    private CollectingLogger $logger;

    protected function setUp(): void
    {
        $this->tmp = new TmpDir('stromcom-i18n-xslt-scanner');
        $this->logger = new CollectingLogger();
    }

    protected function tearDown(): void
    {
        $this->tmp->cleanup();
    }

    private function writeXslt(string $name, string $code): string
    {
        return $this->tmp->write($name, $code);
    }

    /**
     * Wraps a template body in a stylesheet declaring both the xsl and i18n namespaces.
     * The header spans lines 1-4, so the body starts on line 5 — the line-number
     * assertions rely on that.
     */
    private function stylesheet(string $body): string
    {
        return <<<XML
        <?xml version="1.0"?>
        <xsl:stylesheet version="1.0"
                        xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
                        xmlns:i18n="https://stromcom.cz/i18n">
        {$body}
        </xsl:stylesheet>
        XML;
    }

    /**
     * @return list<ScannedKey>
     */
    private function scan(string $code, string $name = 'tpl.xsl', string $relative = 'tpl/tpl.xsl'): array
    {
        $abs = $this->writeXslt($name, $code);

        return (new XsltScanner($this->logger))->scanFile($abs, $relative);
    }

    // ---------------------------------------------------------------- basics

    public function testSupportedExtensions(): void
    {
        $scanner = new XsltScanner(new NullLogger());

        self::assertSame(['xsl', 'xslt'], $scanner->supportedExtensions());
    }

    public function testExtractsElementBasedT(): void
    {
        $xml = <<<XML
        <?xml version="1.0"?>
        <xsl:stylesheet version="1.0"
                        xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
                        xmlns:i18n="https://stromcom.cz/i18n">
            <xsl:template match="/">
                <p><i18n:t key="login.submit" default="Sign in" note="Login form"/></p>
                <p><i18n:t key="login.email" default="Email"/></p>
            </xsl:template>
        </xsl:stylesheet>
        XML;
        $abs = $this->writeXslt('login.xsl', $xml);
        $scanner = new XsltScanner(new NullLogger());

        $keys = $scanner->scanFile($abs, 'tpl/login.xsl');

        self::assertCount(2, $keys);
        self::assertSame('login.submit', $keys[0]->name);
        self::assertSame('Sign in', $keys[0]->sourceText);
        self::assertSame('Login form', $keys[0]->description);
        self::assertSame('login.email', $keys[1]->name);
        self::assertNull($keys[1]->description);
    }

    public function testScansXsltExtensionToo(): void
    {
        $keys = $this->scan(
            $this->stylesheet('<i18n:t key="a" default="A"/>'),
            name: 'tpl.xslt',
            relative: 'tpl/tpl.xslt',
        );

        self::assertCount(1, $keys);
        self::assertSame('tpl/tpl.xslt:5', $keys[0]->occurrences[0]);
    }

    public function testRecordsRelativePathAndLineNumber(): void
    {
        // preserveWhiteSpace = false must not shift the reported line numbers.
        $keys = $this->scan($this->stylesheet(
            "<xsl:template match=\"/\">\n\n"
            . "    <i18n:t key=\"deep\" default=\"Deep\"/>\n"
            . '</xsl:template>',
        ));

        self::assertCount(1, $keys);
        self::assertSame(['tpl/tpl.xsl:7'], $keys[0]->occurrences);
    }

    public function testSkipsElementsMissingKeyOrDefault(): void
    {
        $xml = <<<XML
        <?xml version="1.0"?>
        <xsl:stylesheet version="1.0"
                        xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
                        xmlns:i18n="https://stromcom.cz/i18n">
            <i18n:t key="incomplete"/>
            <i18n:t default="lone default"/>
            <i18n:t key="ok" default="OK"/>
        </xsl:stylesheet>
        XML;
        $abs = $this->writeXslt('partial.xsl', $xml);
        $scanner = new XsltScanner(new NullLogger());

        $keys = $scanner->scanFile($abs, 'tpl/partial.xsl');

        self::assertCount(1, $keys);
        self::assertSame('ok', $keys[0]->name);
    }

    public function testSkipsEmptyStringAttributes(): void
    {
        $keys = $this->scan($this->stylesheet('<i18n:t key="empty" default=""/>'));

        self::assertSame([], $keys);
    }

    public function testReturnsEmptyOnMalformedXml(): void
    {
        $keys = $this->scan('<not><well</not>', name: 'broken.xsl', relative: 'tpl/broken.xsl');

        self::assertSame([], $keys);
        self::assertTrue($this->logger->hasRecordContaining('warning', 'libxml error'));
    }

    public function testReturnsEmptyForUnreadableFile(): void
    {
        $scanner = new XsltScanner($this->logger);

        $keys = $scanner->scanFile($this->tmp->path() . '/does-not-exist.xsl', 'tpl/gone.xsl');

        self::assertSame([], $keys);
        self::assertTrue($this->logger->hasRecordContaining('warning', 'file unreadable'));
    }

    public function testTextContentInsideElementIsIgnored(): void
    {
        $keys = $this->scan($this->stylesheet(
            '<i18n:t key="k" default="From attribute">this text is not the source</i18n:t>',
        ));

        self::assertCount(1, $keys);
        self::assertSame('From attribute', $keys[0]->sourceText);
    }

    public function testXmlEntitiesAreDecoded(): void
    {
        $keys = $this->scan($this->stylesheet('<i18n:t key="e" default="Tom &amp; Jerry &lt;3"/>'));

        self::assertCount(1, $keys);
        self::assertSame('Tom & Jerry <3', $keys[0]->sourceText);
    }

    public function testSameKeyTwiceInOneFileYieldsTwoRecords(): void
    {
        // Deduplication is ScannerPipeline's job — the scanner reports every occurrence.
        $keys = $this->scan($this->stylesheet(
            '<p><i18n:t key="dup" default="D"/></p><span><i18n:t key="dup" default="D"/></span>',
        ));

        self::assertCount(2, $keys);
        self::assertSame('dup', $keys[0]->name);
        self::assertSame('dup', $keys[1]->name);
    }

    public function testFindsElementsNestedInXslControlFlow(): void
    {
        $keys = $this->scan($this->stylesheet(
            '<xsl:template match="/">'
            . '<xsl:if test="@x"><i18n:t key="in.if" default="If"/></xsl:if>'
            . '<xsl:for-each select="rows/row"><i18n:t key="in.foreach" default="Each"/></xsl:for-each>'
            . '<xsl:choose><xsl:when test="@y"><i18n:t key="in.when" default="When"/></xsl:when>'
            . '<xsl:otherwise><i18n:t key="in.otherwise" default="Otherwise"/></xsl:otherwise></xsl:choose>'
            . '</xsl:template>',
        ));

        self::assertSame(
            ['in.if', 'in.foreach', 'in.when', 'in.otherwise'],
            array_map(static fn (ScannedKey $k): string => $k->name, $keys),
        );
    }

    // ------------------------------------------------- AVT (variable) handling

    /**
     * The regression that matters most: `<i18n:t>` is a literal result element, so XSLT
     * reads every attribute as an AVT and collapses `{{` → `{`. The scanner must report
     * the *runtime* ICU pattern, otherwise the platform receives a doubled-brace string
     * that MessageFormatter cannot parse.
     */
    public function testUnescapesAvtBracesInIcuPluralDefault(): void
    {
        $keys = $this->scan($this->stylesheet(
            '<span><i18n:t key="cart.count" '
            . 'default="{{count, plural, one {{# item}} other {{# items}}}}" '
            . 'count="{data/@items}"/></span>',
        ));

        self::assertCount(1, $keys);
        self::assertSame('{count, plural, one {# item} other {# items}}', $keys[0]->sourceText);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function avtEscapeProvider(): iterable
    {
        yield 'no braces'            => ['Sign in', 'Sign in'];
        yield 'simple placeholder'   => ['Hello {{name}}', 'Hello {name}'];
        yield 'two placeholders'     => ['{{a}} and {{b}}', '{a} and {b}'];
        yield 'adjacent braces'      => ['{{{{x}}}}', '{{x}}'];
        yield 'select subformat'     => [
            '{{gender, select, male {{He}} female {{She}} other {{They}}}}',
            '{gender, select, male {He} female {She} other {They}}',
        ];
        yield 'number format'        => ['{{amount, number, currency}}', '{amount, number, currency}'];
        yield 'utf8 around braces'   => ['Příliš {{počet}} žluťoučků', 'Příliš {počet} žluťoučků'];
        yield 'lone closing brace'   => ['ends }', 'ends }'];
        yield 'trailing escape only' => ['{{', '{'];
        yield 'escape at very end'   => ['a{{', 'a{'];
        yield 'closing escape at end' => ['a}}', 'a}'];
    }

    #[DataProvider('avtEscapeProvider')]
    public function testAvtEscapeIsUndoneInDefault(string $written, string $expected): void
    {
        $keys = $this->scan($this->stylesheet(
            '<i18n:t key="k" default="' . $written . '"/>',
        ));

        self::assertCount(1, $keys);
        self::assertSame($expected, $keys[0]->sourceText);
    }

    public function testAvtEscapeIsUndoneInNote(): void
    {
        $keys = $this->scan($this->stylesheet(
            '<i18n:t key="k" default="D" note="Uses {{count}} placeholder"/>',
        ));

        self::assertCount(1, $keys);
        self::assertSame('Uses {count} placeholder', $keys[0]->description);
    }

    public function testSkipsDynamicKeyExpression(): void
    {
        $keys = $this->scan($this->stylesheet('<i18n:t key="{$dynamic}" default="D"/>'));

        self::assertSame([], $keys);
        self::assertTrue($this->logger->hasRecordContaining('warning', '@key contains an AVT expression'));
        self::assertSame(
            ['path' => 'tpl/tpl.xsl', 'line' => 5, 'attribute' => 'key', 'value' => '{$dynamic}'],
            $this->logger->contextOfFirstContaining('@key contains an AVT expression'),
        );
    }

    /**
     * A dynamic value is skipped, so the logged `value` is the only observable of the AVT
     * parser's handling of real expressions. These cases pin the expression boundaries:
     * the whole `{…}` span must survive verbatim while the escaped braces around it
     * collapse.
     *
     * @param string $written  as spelled in the stylesheet attribute
     * @param string $reported as it must appear in the warning context
     */
    #[DataProvider('dynamicKeyProvider')]
    public function testDynamicExpressionSpanIsPreservedVerbatim(string $written, string $reported): void
    {
        $keys = $this->scan($this->stylesheet('<i18n:t key="' . $written . '" default="D"/>'));

        self::assertSame([], $keys);
        $context = $this->logger->contextOfFirstContaining('@key contains an AVT expression');
        self::assertSame($reported, $context['value'] ?? null);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function dynamicKeyProvider(): iterable
    {
        yield 'variable reference' => ['{$dynamic}', '{$dynamic}'];
        yield 'xpath path'         => ['{a/b/@c}', '{a/b/@c}'];
        yield 'text around'        => ['pre{x}post', 'pre{x}post'];
        yield 'two expressions'    => ['{x}{y}', '{x}{y}'];
        yield 'escaped then expr'  => ['{{lit}}{x}', '{lit}{x}'];
        yield 'expr then escaped'  => ['{x}{{lit}}', '{x}{lit}'];
        yield 'unterminated'       => ['{unterminated', '{unterminated'];
        yield 'lone opening brace' => ['{', '{'];
        yield 'trailing brace'     => ['a{', 'a{'];
        yield 'empty expression'   => ['{}', '{}'];
    }

    public function testSkipsDynamicDefaultExpression(): void
    {
        $keys = $this->scan($this->stylesheet('<i18n:t key="k" default="Hello {customer/name}"/>'));

        self::assertSame([], $keys);
        self::assertTrue($this->logger->hasRecordContaining('warning', '@default contains an AVT expression'));
    }

    public function testUnterminatedAvtExpressionIsTreatedAsDynamic(): void
    {
        $keys = $this->scan($this->stylesheet('<i18n:t key="k" default="broken {oops"/>'));

        self::assertSame([], $keys);
        self::assertTrue($this->logger->hasRecordContaining('warning', '@default contains an AVT expression'));
    }

    public function testParamAttributesDoNotAffectExtraction(): void
    {
        // The AVT params are the renderer's business; the scanner only reports key/default/note.
        $keys = $this->scan($this->stylesheet(
            '<i18n:t key="k" default="Hi {{name}}" name="{$who}" count="{sum(rows/row/@n)}"/>',
        ));

        self::assertCount(1, $keys);
        self::assertSame('k', $keys[0]->name);
        self::assertSame('Hi {name}', $keys[0]->sourceText);
    }

    public function testWarnsWhenDefaultComesFromXslAttributeElement(): void
    {
        // Renders correctly, but no static attribute exists to read — the key would
        // otherwise disappear from the sync without a word.
        $keys = $this->scan($this->stylesheet(
            '<i18n:t key="greet">'
            . '<xsl:attribute name="default">Hello {name}</xsl:attribute>'
            . '<xsl:attribute name="name"><xsl:value-of select="$user"/></xsl:attribute>'
            . '</i18n:t>',
        ));

        self::assertSame([], $keys);
        self::assertTrue($this->logger->hasRecordContaining('warning', 'sets @default via <xsl:attribute>'));
    }

    public function testWarnsWhenKeyComesFromXslAttributeElement(): void
    {
        $keys = $this->scan($this->stylesheet(
            '<i18n:t default="D"><xsl:attribute name="key">dyn.key</xsl:attribute></i18n:t>',
        ));

        self::assertSame([], $keys);
        self::assertTrue($this->logger->hasRecordContaining('warning', 'sets @key via <xsl:attribute>'));
    }

    public function testXslAttributeChildForAnUnrelatedAttributeDoesNotWarn(): void
    {
        // Sets @class, not @default — the missing default is an ordinary incomplete
        // element, not the unscannable <xsl:attribute> form.
        $keys = $this->scan($this->stylesheet(
            '<i18n:t key="k"><xsl:attribute name="class">x</xsl:attribute></i18n:t>',
        ));

        self::assertSame([], $keys);
        self::assertFalse($this->logger->hasRecordContaining('warning', 'via <xsl:attribute>'));
        self::assertTrue($this->logger->hasRecordContaining('info', 'missing key/default'));
    }

    public function testAttributeElementOutsideTheXslNamespaceDoesNotWarn(): void
    {
        $keys = $this->scan($this->stylesheet(
            '<i18n:t key="k"><i18n:attribute name="default">x</i18n:attribute></i18n:t>',
        ));

        self::assertSame([], $keys);
        self::assertFalse($this->logger->hasRecordContaining('warning', 'via <xsl:attribute>'));
    }

    public function testNonElementChildrenAreIgnoredWhenLookingForXslAttribute(): void
    {
        $keys = $this->scan($this->stylesheet(
            '<i18n:t key="k" default="D"><!-- a comment --></i18n:t>',
        ));

        self::assertCount(1, $keys);
        self::assertSame('D', $keys[0]->sourceText);
    }

    public function testXslAttributeWinsOverAnEmptyLiteralAttribute(): void
    {
        // The literal attribute is present but empty, so the <xsl:attribute> child is not
        // consulted — the element is reported as incomplete rather than unscannable.
        $keys = $this->scan($this->stylesheet(
            '<i18n:t key="k" default=""><xsl:attribute name="default">D</xsl:attribute></i18n:t>',
        ));

        self::assertSame([], $keys);
        self::assertTrue($this->logger->hasRecordContaining('info', 'missing key/default'));
    }

    // --------------------------------------------------------- namespace rules

    public function testMatchesAnyPrefixBoundToTheI18nNamespace(): void
    {
        $xml = <<<XML
        <?xml version="1.0"?>
        <xsl:stylesheet version="1.0"
                        xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
                        xmlns:tr="https://stromcom.cz/i18n">
            <tr:t key="alt.prefix" default="Alt"/>
        </xsl:stylesheet>
        XML;

        $keys = $this->scan($xml);

        self::assertCount(1, $keys);
        self::assertSame('alt.prefix', $keys[0]->name);
    }

    /**
     * A mistyped namespace makes `XsltRenderer` throw at render time. The scan must not
     * report the key as healthy — it has to warn, so the typo surfaces in CI.
     */
    public function testWarnsAndSkipsWhenNamespaceUriIsWrong(): void
    {
        $xml = <<<XML
        <?xml version="1.0"?>
        <xsl:stylesheet version="1.0"
                        xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
                        xmlns:i18n="http://stromcom.cz/i18n">
            <i18n:t key="wrong.ns" default="X"/>
        </xsl:stylesheet>
        XML;

        $keys = $this->scan($xml);

        self::assertSame([], $keys);
        self::assertTrue($this->logger->hasRecordContaining('warning', 'outside the i18n namespace'));
        self::assertSame(
            [
                'path'     => 'tpl/tpl.xsl',
                'line'     => 5,
                'found'    => 'http://stromcom.cz/i18n',
                'expected' => XsltScanner::NAMESPACE_URI,
            ],
            $this->logger->contextOfFirstContaining('outside the i18n namespace'),
        );
    }

    public function testWarnsForTElementWithNoNamespace(): void
    {
        $keys = $this->scan($this->stylesheet('<t key="bare" default="Bare"/>'));

        self::assertSame([], $keys);
        self::assertTrue($this->logger->hasRecordContaining('warning', 'outside the i18n namespace'));
    }

    public function testForeignTElementWithoutKeyAndDefaultIsNotReported(): void
    {
        // A `<t>` from an unrelated vocabulary must not produce noise.
        $keys = $this->scan($this->stylesheet('<t>plain content</t>'));

        self::assertSame([], $keys);
        self::assertSame([], $this->logger->messages('warning'));
    }

    public function testStylesheetWithoutI18nElementsYieldsNothingQuietly(): void
    {
        $keys = $this->scan($this->stylesheet('<xsl:template match="/"><p>static</p></xsl:template>'));

        self::assertSame([], $keys);
        self::assertSame([], $this->logger->records);
    }
}
