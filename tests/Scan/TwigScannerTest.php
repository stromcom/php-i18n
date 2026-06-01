<?php

declare(strict_types=1);

namespace Stromcom\I18n\Tests\Scan;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Stromcom\I18n\Scan\TwigScanner;
use Stromcom\I18n\Scan\Visitor\TwigTFunctionVisitor;
use Stromcom\I18n\Tests\Support\TmpDir;

#[CoversClass(TwigScanner::class)]
#[CoversClass(TwigTFunctionVisitor::class)]
final class TwigScannerTest extends TestCase
{
    private TmpDir $tmp;

    protected function setUp(): void
    {
        $this->tmp = new TmpDir('stromcom-i18n-twig-scanner');
    }

    protected function tearDown(): void
    {
        $this->tmp->cleanup();
    }

    private function writeTemplate(string $name, string $code): string
    {
        return $this->tmp->write($name, $code);
    }

    public function testExtractsBasicTCall(): void
    {
        $abs = $this->writeTemplate('login.twig', "<button>{{ t('login.submit', 'Sign in') }}</button>");
        $scanner = new TwigScanner(new NullLogger());

        $keys = $scanner->scanFile($abs, 'templates/login.twig');

        self::assertCount(1, $keys);
        self::assertSame('login.submit', $keys[0]->name);
        self::assertSame('Sign in', $keys[0]->sourceText);
        self::assertNull($keys[0]->description);
    }

    public function testExtractsWithParamsAndFilters(): void
    {
        $template = "{{ t('cart.itemCount', '{count, plural, one {# item} other {# items}}', { count: 5 })|upper }}";
        $abs = $this->writeTemplate('cart.twig', $template);
        $scanner = new TwigScanner(new NullLogger());

        $keys = $scanner->scanFile($abs, 'templates/cart.twig');

        self::assertCount(1, $keys);
        self::assertSame('cart.itemCount', $keys[0]->name);
        self::assertSame('{count, plural, one {# item} other {# items}}', $keys[0]->sourceText);
    }

    public function testIgnoresOtherFunctions(): void
    {
        $abs = $this->writeTemplate('mix.twig', "{{ path('home') }} {{ t('a.b', 'Hello') }} {{ url('login') }}");
        $scanner = new TwigScanner(new NullLogger());

        $keys = $scanner->scanFile($abs, 'templates/mix.twig');

        self::assertCount(1, $keys);
        self::assertSame('a.b', $keys[0]->name);
    }

    public function testReturnsEmptyOnSyntaxError(): void
    {
        $abs = $this->writeTemplate('bad.twig', "{{ broken syntax %}");
        $scanner = new TwigScanner(new NullLogger());

        $keys = $scanner->scanFile($abs, 'templates/bad.twig');

        self::assertSame([], $keys);
    }
}
