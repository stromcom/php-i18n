<?php

declare(strict_types=1);

namespace Stromcom\I18n\Tests\Build;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stromcom\I18n\Build\PhpCacheWriter;
use Stromcom\I18n\Tests\Support\TmpDir;

#[CoversClass(PhpCacheWriter::class)]
final class PhpCacheWriterTest extends TestCase
{
    private TmpDir $tmp;

    protected function setUp(): void
    {
        $this->tmp = new TmpDir('stromcom-i18n-php-cache-writer');
    }

    protected function tearDown(): void
    {
        $this->tmp->cleanup();
    }

    public function testWritesValidPhpFileThatReturnsArray(): void
    {
        $jsonPath = $this->tmp->writeJson('cs.json', ['a' => 'A', 'b' => 'B']);
        $phpPath = $this->tmp->path() . '/cs.cache.php';

        $writer = new PhpCacheWriter();
        $ok = $writer->write($phpPath, ['a' => 'A', 'b' => 'B'], $jsonPath);

        self::assertTrue($ok);
        self::assertFileExists($phpPath);

        /** @var mixed $loaded */
        $loaded = require $phpPath;
        self::assertSame(['a' => 'A', 'b' => 'B'], $loaded);
    }

    public function testSynchronizesMtimeWithJsonFile(): void
    {
        $jsonPath = $this->tmp->writeJson('cs.json', ['a' => 'A']);
        // Push the JSON mtime into the past so the difference can be verified.
        $past = time() - 3600;
        touch($jsonPath, $past);

        $phpPath = $this->tmp->path() . '/cs.cache.php';
        (new PhpCacheWriter())->write($phpPath, ['a' => 'A'], $jsonPath);

        self::assertSame($past, filemtime($phpPath));
    }

    public function testPreservesUtf8Strings(): void
    {
        $jsonPath = $this->tmp->writeJson('cs.json', ['ahoj' => 'Příliš žluťoučký kůň']);
        $phpPath = $this->tmp->path() . '/cs.cache.php';
        (new PhpCacheWriter())->write($phpPath, ['ahoj' => 'Příliš žluťoučký kůň'], $jsonPath);

        /** @var mixed $loaded */
        $loaded = require $phpPath;
        self::assertSame(['ahoj' => 'Příliš žluťoučký kůň'], $loaded);
    }

    public function testHandlesIcuPlaceholdersWithCurlyBraces(): void
    {
        $bundle = ['cart' => '{count, plural, one {# item} other {# items}}'];
        $jsonPath = $this->tmp->writeJson('cs.json', $bundle);
        $phpPath = $this->tmp->path() . '/cs.cache.php';
        (new PhpCacheWriter())->write($phpPath, $bundle, $jsonPath);

        /** @var mixed $loaded */
        $loaded = require $phpPath;
        self::assertSame($bundle, $loaded);
    }
}
