<?php

declare(strict_types=1);

namespace Stromcom\I18n\Tests\Build;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stromcom\I18n\Build\EtagStore;
use Stromcom\I18n\Tests\Support\TmpDir;

#[CoversClass(EtagStore::class)]
final class EtagStoreTest extends TestCase
{
    private TmpDir $tmp;

    protected function setUp(): void
    {
        $this->tmp = new TmpDir('stromcom-i18n-etag');
    }

    protected function tearDown(): void
    {
        $this->tmp->cleanup();
    }

    private function storePath(): string
    {
        return $this->tmp->path() . '/.i18n-etags.json';
    }

    public function testReturnsNullForAnUnknownLocale(): void
    {
        self::assertNull((new EtagStore($this->storePath()))->get('cs'));
    }

    public function testStoresAndReadsBackAnEtag(): void
    {
        $store = new EtagStore($this->storePath());
        $store->set('cs', 'W/"abc123"');

        self::assertSame('W/"abc123"', $store->get('cs'));
    }

    public function testPersistsAcrossInstances(): void
    {
        (new EtagStore($this->storePath()))->set('cs', '"etag-cs"');

        self::assertSame('"etag-cs"', (new EtagStore($this->storePath()))->get('cs'));
    }

    public function testKeepsLocalesIndependent(): void
    {
        $store = new EtagStore($this->storePath());
        $store->set('cs', '"cs"');
        $store->set('de', '"de"');

        $reloaded = new EtagStore($this->storePath());
        self::assertSame('"cs"', $reloaded->get('cs'));
        self::assertSame('"de"', $reloaded->get('de'));
    }

    public function testOverwritesAnExistingEtag(): void
    {
        $store = new EtagStore($this->storePath());
        $store->set('cs', '"old"');
        $store->set('cs', '"new"');

        self::assertSame('"new"', (new EtagStore($this->storePath()))->get('cs'));
    }

    public function testWritesPrettyPrintedJsonWithATrailingNewline(): void
    {
        (new EtagStore($this->storePath()))->set('cs', '"etag"');

        $raw = file_get_contents($this->storePath());
        self::assertIsString($raw);
        self::assertStringEndsWith("\n", $raw);
        self::assertSame(['cs' => '"etag"'], json_decode($raw, true, 4, JSON_THROW_ON_ERROR));
    }

    public function testUnescapedSlashesAndUnicodeAreKept(): void
    {
        (new EtagStore($this->storePath()))->set('cs', 'a/b-ěščř');

        $raw = file_get_contents($this->storePath());
        self::assertIsString($raw);
        self::assertStringContainsString('a/b-ěščř', $raw);
    }

    public function testMalformedJsonFileIsTreatedAsEmpty(): void
    {
        file_put_contents($this->storePath(), '{not json');

        self::assertNull((new EtagStore($this->storePath()))->get('cs'));
    }

    public function testNonObjectJsonRootIsTreatedAsEmpty(): void
    {
        file_put_contents($this->storePath(), '"just a string"');

        self::assertNull((new EtagStore($this->storePath()))->get('cs'));
    }

    public function testNonStringValuesAreDiscarded(): void
    {
        file_put_contents($this->storePath(), json_encode(['cs' => 123, 'de' => '"ok"'], JSON_THROW_ON_ERROR));
        $store = new EtagStore($this->storePath());

        self::assertNull($store->get('cs'));
        self::assertSame('"ok"', $store->get('de'));
    }

    public function testWriteIntoAMissingDirectoryIsSilentlyIgnored(): void
    {
        // persist() bails out when the directory does not exist — the fetch must not die
        // just because the etag cache cannot be written.
        $store = new EtagStore($this->tmp->path() . '/missing-dir/etags.json');
        $store->set('cs', '"etag"');

        self::assertSame('"etag"', $store->get('cs'), 'the in-memory value still works');
        self::assertFileDoesNotExist($this->tmp->path() . '/missing-dir/etags.json');
    }

    public function testCorruptFileIsRepairedOnNextWrite(): void
    {
        file_put_contents($this->storePath(), '{broken');
        $store = new EtagStore($this->storePath());
        $store->set('cs', '"fresh"');

        self::assertSame('"fresh"', (new EtagStore($this->storePath()))->get('cs'));
    }
}
