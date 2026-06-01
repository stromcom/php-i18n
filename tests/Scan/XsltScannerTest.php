<?php

declare(strict_types=1);

namespace Stromcom\I18n\Tests\Scan;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Stromcom\I18n\Scan\XsltScanner;
use Stromcom\I18n\Tests\Support\TmpDir;

#[CoversClass(XsltScanner::class)]
final class XsltScannerTest extends TestCase
{
    private TmpDir $tmp;

    protected function setUp(): void
    {
        $this->tmp = new TmpDir('stromcom-i18n-xslt-scanner');
    }

    protected function tearDown(): void
    {
        $this->tmp->cleanup();
    }

    private function writeXslt(string $name, string $code): string
    {
        return $this->tmp->write($name, $code);
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

    public function testReturnsEmptyOnMalformedXml(): void
    {
        $abs = $this->writeXslt('broken.xsl', "<not><well</not>");
        $scanner = new XsltScanner(new NullLogger());

        $keys = $scanner->scanFile($abs, 'tpl/broken.xsl');

        self::assertSame([], $keys);
    }
}
