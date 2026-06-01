<?php

declare(strict_types=1);

namespace Stromcom\I18n\Tests\Scan;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Stromcom\I18n\Scan\PhpScanner;
use Stromcom\I18n\Scan\Visitor\PhpTFunctionVisitor;
use Stromcom\I18n\Tests\Support\TmpDir;

#[CoversClass(PhpScanner::class)]
#[CoversClass(PhpTFunctionVisitor::class)]
final class PhpScannerTest extends TestCase
{
    private TmpDir $tmp;

    protected function setUp(): void
    {
        $this->tmp = new TmpDir('stromcom-i18n-php-scanner');
    }

    protected function tearDown(): void
    {
        $this->tmp->cleanup();
    }

    private function writeSource(string $name, string $code): string
    {
        return $this->tmp->write($name, $code);
    }

    public function testExtractsLiteralKeyDefaultAndNote(): void
    {
        $code = <<<'PHP'
        <?php
        class Foo {
            public function run(): string {
                return $this->translator->trans('login.submit', 'Sign in', note: 'Login form');
            }
        }
        PHP;
        $abs = $this->writeSource('Foo.php', $code);
        $scanner = new PhpScanner(new NullLogger());

        $keys = $scanner->scanFile($abs, 'src/Foo.php');

        self::assertCount(1, $keys);
        self::assertSame('login.submit', $keys[0]->name);
        self::assertSame('Sign in', $keys[0]->sourceText);
        self::assertSame('Login form', $keys[0]->description);
        self::assertSame(['src/Foo.php:4'], $keys[0]->occurrences);
    }

    public function testSkipsCallsWithNonLiteralKey(): void
    {
        $code = <<<'PHP'
        <?php
        $key = 'dynamic.key';
        $this->translator->trans($key, 'whatever');
        $this->translator->trans('static.key', 'Yes');
        PHP;
        $abs = $this->writeSource('Dynamic.php', $code);
        $scanner = new PhpScanner(new NullLogger());

        $keys = $scanner->scanFile($abs, 'src/Dynamic.php');

        self::assertCount(1, $keys);
        self::assertSame('static.key', $keys[0]->name);
    }

    public function testIgnoresMethodWithDifferentName(): void
    {
        $code = <<<'PHP'
        <?php
        $this->translator->translate('a', 'b');
        $this->logger->trans('not.translation', 'noop'); // different receiver, but name matches — scanner matches it
        PHP;
        $abs = $this->writeSource('Misc.php', $code);
        $scanner = new PhpScanner(new NullLogger(), methodName: 'translate');

        $keys = $scanner->scanFile($abs, 'src/Misc.php');

        self::assertCount(1, $keys);
        self::assertSame('a', $keys[0]->name);
    }

    public function testReturnsEmptyOnSyntaxError(): void
    {
        $abs = $this->writeSource('Broken.php', "<?php this is not valid PHP &&&");
        $scanner = new PhpScanner(new NullLogger());

        $keys = $scanner->scanFile($abs, 'src/Broken.php');

        self::assertSame([], $keys);
    }
}
