<?php

declare(strict_types=1);

namespace Stromcom\I18n\Tests\Scan;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Stromcom\I18n\Config\I18nConfig;
use Stromcom\I18n\Runtime\MissingKeyPolicy;
use Stromcom\I18n\Scan\ScannedKey;
use Stromcom\I18n\Scan\ScannerPipeline;
use Stromcom\I18n\Tests\Support\CollectingLogger;
use Stromcom\I18n\Tests\Support\RecordingScanner;
use Stromcom\I18n\Tests\Support\TmpDir;

#[CoversClass(ScannerPipeline::class)]
#[CoversClass(ScannedKey::class)]
final class ScannerPipelineTest extends TestCase
{
    private TmpDir $tmp;
    private CollectingLogger $logger;

    protected function setUp(): void
    {
        $this->tmp = new TmpDir('stromcom-i18n-pipeline');
        $this->logger = new CollectingLogger();
    }

    protected function tearDown(): void
    {
        $this->tmp->cleanup();
    }

    /**
     * @param list<string> $scanPaths
     * @param list<string> $scanExcludes
     */
    private function config(array $scanPaths, array $scanExcludes = ['/vendor/']): I18nConfig
    {
        return new I18nConfig(
            projectId: 't',
            token: '',
            baseUrl: 'https://e.test',
            sourceLocale: 'en',
            targetLocales: ['en'],
            fallbackLocale: 'en',
            bundlesDir: '/tmp',
            scanPaths: $scanPaths,
            scanExcludes: $scanExcludes,
            missingKeyPolicy: MissingKeyPolicy::Silent,
        );
    }

    /**
     * @param list<string>                                     $extensions
     * @param (\Closure(string, string): list<ScannedKey>)|null $produce
     */
    private function fakeScanner(array $extensions, ?\Closure $produce = null): RecordingScanner
    {
        return new RecordingScanner($extensions, $produce);
    }

    // ------------------------------------------------------------- dispatching

    public function testDispatchesFilesToTheScannerMatchingTheExtension(): void
    {
        $this->tmp->write('a.php', '');
        $this->tmp->write('b.twig', '');
        $this->tmp->write('c.xsl', '');
        $php = $this->fakeScanner(['php']);
        $twig = $this->fakeScanner(['twig']);

        $keys = (new ScannerPipeline(
            $this->config([$this->tmp->path()]),
            $this->logger,
            [$php, $twig],
            rootDir: $this->tmp->path(),
        ))->scan();

        self::assertCount(1, $php->seen);
        self::assertCount(1, $twig->seen);
        self::assertSame(['a.php', 'b.twig'], $this->sortedNames($keys));
    }

    public function testFilesWithNoMatchingScannerAreSkipped(): void
    {
        $this->tmp->write('readme.md', '');
        $this->tmp->write('a.php', '');
        $php = $this->fakeScanner(['php']);

        $keys = (new ScannerPipeline(
            $this->config([$this->tmp->path()]),
            $this->logger,
            [$php],
            rootDir: $this->tmp->path(),
        ))->scan();

        self::assertSame(['a.php'], $this->sortedNames($keys));
    }

    public function testExtensionMatchingIsCaseInsensitive(): void
    {
        $this->tmp->write('LOUD.PHP', '');
        $php = $this->fakeScanner(['php']);

        $keys = (new ScannerPipeline(
            $this->config([$this->tmp->path()]),
            $this->logger,
            [$php],
            rootDir: $this->tmp->path(),
        ))->scan();

        self::assertCount(1, $keys);
    }

    public function testFilesWithoutAnExtensionAreSkipped(): void
    {
        $this->tmp->write('Makefile', '');
        $php = $this->fakeScanner(['php']);

        $keys = (new ScannerPipeline(
            $this->config([$this->tmp->path()]),
            $this->logger,
            [$php],
            rootDir: $this->tmp->path(),
        ))->scan();

        self::assertSame([], $keys);
        self::assertSame([], $php->seen);
    }

    public function testFirstRegisteredScannerWinsForAnExtension(): void
    {
        $this->tmp->write('a.php', '');
        $first = $this->fakeScanner(['php']);
        $second = $this->fakeScanner(['php']);

        (new ScannerPipeline(
            $this->config([$this->tmp->path()]),
            $this->logger,
            [$first, $second],
            rootDir: $this->tmp->path(),
        ))->scan();

        self::assertCount(1, $first->seen);
        self::assertSame([], $second->seen);
    }

    public function testWalksNestedDirectoriesRecursively(): void
    {
        $this->tmp->write('top.php', '');
        $this->tmp->write('deep/nested/inner.php', '');
        $php = $this->fakeScanner(['php']);

        $keys = (new ScannerPipeline(
            $this->config([$this->tmp->path()]),
            $this->logger,
            [$php],
            rootDir: $this->tmp->path(),
        ))->scan();

        self::assertSame(['inner.php', 'top.php'], $this->sortedNames($keys));
    }

    public function testAcceptsAFileAsScanPath(): void
    {
        $file = $this->tmp->write('single.php', '');
        $php = $this->fakeScanner(['php']);

        $keys = (new ScannerPipeline(
            $this->config([$file]),
            $this->logger,
            [$php],
            rootDir: $this->tmp->path(),
        ))->scan();

        self::assertSame(['single.php'], $this->sortedNames($keys));
    }

    public function testWarnsAndContinuesWhenAScanPathDoesNotExist(): void
    {
        $this->tmp->write('a.php', '');
        $php = $this->fakeScanner(['php']);

        $keys = (new ScannerPipeline(
            $this->config([$this->tmp->path() . '/nope', $this->tmp->path()]),
            $this->logger,
            [$php],
            rootDir: $this->tmp->path(),
        ))->scan();

        self::assertSame(['a.php'], $this->sortedNames($keys));
        self::assertTrue($this->logger->hasRecordContaining('warning', 'scan path does not exist'));
    }

    // ---------------------------------------------------------------- excludes

    public function testExcludedPathsAreSkipped(): void
    {
        $this->tmp->write('app/a.php', '');
        $this->tmp->write('vendor/lib/b.php', '');
        $php = $this->fakeScanner(['php']);

        $keys = (new ScannerPipeline(
            $this->config([$this->tmp->path()], ['/vendor/']),
            $this->logger,
            [$php],
            rootDir: $this->tmp->path(),
        ))->scan();

        self::assertSame(['a.php'], $this->sortedNames($keys));
    }

    public function testExcludesApplyToAFileGivenDirectlyAsScanPath(): void
    {
        $file = $this->tmp->write('vendor/lib/b.php', '');
        $php = $this->fakeScanner(['php']);

        $keys = (new ScannerPipeline(
            $this->config([$file], ['/vendor/']),
            $this->logger,
            [$php],
            rootDir: $this->tmp->path(),
        ))->scan();

        self::assertSame([], $keys);
    }

    public function testEmptyExcludeListScansEverything(): void
    {
        $this->tmp->write('vendor/lib/b.php', '');
        $php = $this->fakeScanner(['php']);

        $keys = (new ScannerPipeline(
            $this->config([$this->tmp->path()], []),
            $this->logger,
            [$php],
            rootDir: $this->tmp->path(),
        ))->scan();

        self::assertSame(['b.php'], $this->sortedNames($keys));
    }

    // ------------------------------------------------------- dedup and merging

    public function testMergesOccurrencesOfTheSameKeyAcrossFiles(): void
    {
        $this->tmp->write('one.php', '');
        $this->tmp->write('two.php', '');
        $scanner = $this->fakeScanner(['php'], static fn (string $abs, string $rel): array => [
            new ScannedKey('shared.key', 'Same text', null, [$rel . ':7']),
        ]);

        $keys = (new ScannerPipeline(
            $this->config([$this->tmp->path()]),
            $this->logger,
            [$scanner],
            rootDir: $this->tmp->path(),
        ))->scan();

        self::assertCount(1, $keys);
        self::assertSame('shared.key', $keys[0]->name);
        sort($keys[0]->occurrences);
        self::assertSame(['one.php:7', 'two.php:7'], $keys[0]->occurrences);
    }

    public function testDuplicateOccurrenceIsNotAddedTwice(): void
    {
        $this->tmp->write('one.php', '');
        $scanner = $this->fakeScanner(['php'], static fn (string $abs, string $rel): array => [
            new ScannedKey('k', 'T', null, [$rel . ':1']),
            new ScannedKey('k', 'T', null, [$rel . ':1']),
        ]);

        $keys = (new ScannerPipeline(
            $this->config([$this->tmp->path()]),
            $this->logger,
            [$scanner],
            rootDir: $this->tmp->path(),
        ))->scan();

        self::assertCount(1, $keys);
        self::assertSame(['one.php:1'], $keys[0]->occurrences);
    }

    public function testWarnsOnTheSameKeyWithDifferentSourceText(): void
    {
        $this->tmp->write('one.php', '');
        $this->tmp->write('two.php', '');
        $scanner = $this->fakeScanner(['php'], static fn (string $abs, string $rel): array => [
            new ScannedKey('k', 'text from ' . basename($rel), null, [$rel . ':1']),
        ]);

        $keys = (new ScannerPipeline(
            $this->config([$this->tmp->path()]),
            $this->logger,
            [$scanner],
            rootDir: $this->tmp->path(),
        ))->scan();

        self::assertCount(1, $keys);
        self::assertTrue($this->logger->hasRecordContaining('warning', 'different source_text'));
    }

    public function testIdenticalSourceTextDoesNotWarn(): void
    {
        $this->tmp->write('one.php', '');
        $this->tmp->write('two.php', '');
        $scanner = $this->fakeScanner(['php'], static fn (string $abs, string $rel): array => [
            new ScannedKey('k', 'identical', null, [$rel . ':1']),
        ]);

        (new ScannerPipeline(
            $this->config([$this->tmp->path()]),
            $this->logger,
            [$scanner],
            rootDir: $this->tmp->path(),
        ))->scan();

        self::assertFalse($this->logger->hasRecordContaining('warning', 'different source_text'));
    }

    public function testFirstDescriptionWinsAndConflictIsLogged(): void
    {
        $this->tmp->write('one.php', '');
        $this->tmp->write('two.php', '');
        $scanner = $this->fakeScanner(['php'], static fn (string $abs, string $rel): array => [
            new ScannedKey('k', 'same', 'note from ' . basename($rel), [$rel . ':1']),
        ]);

        $keys = (new ScannerPipeline(
            $this->config([$this->tmp->path()]),
            $this->logger,
            [$scanner],
            rootDir: $this->tmp->path(),
        ))->scan();

        self::assertCount(1, $keys);
        self::assertStringStartsWith('note from ', (string) $keys[0]->description);
        self::assertTrue($this->logger->hasRecordContaining('info', 'different note'));
    }

    public function testMissingDescriptionOnOneSideDoesNotLogAConflict(): void
    {
        $this->tmp->write('one.php', '');
        $this->tmp->write('two.php', '');
        $scanner = $this->fakeScanner(['php'], static fn (string $abs, string $rel): array => [
            new ScannedKey('k', 'same', basename($rel) === 'one.php' ? 'a note' : null, [$rel . ':1']),
        ]);

        (new ScannerPipeline(
            $this->config([$this->tmp->path()]),
            $this->logger,
            [$scanner],
            rootDir: $this->tmp->path(),
        ))->scan();

        self::assertFalse($this->logger->hasRecordContaining('info', 'different note'));
    }

    public function testDistinctKeysAreAllReturned(): void
    {
        $this->tmp->write('one.php', '');
        $this->tmp->write('two.php', '');
        $php = $this->fakeScanner(['php']);

        $keys = (new ScannerPipeline(
            $this->config([$this->tmp->path()]),
            $this->logger,
            [$php],
            rootDir: $this->tmp->path(),
        ))->scan();

        self::assertSame(['one.php', 'two.php'], $this->sortedNames($keys));
    }

    public function testReturnsAListWithSequentialKeys(): void
    {
        $this->tmp->write('one.php', '');
        $this->tmp->write('two.php', '');
        $php = $this->fakeScanner(['php']);

        $keys = (new ScannerPipeline(
            $this->config([$this->tmp->path()]),
            $this->logger,
            [$php],
            rootDir: $this->tmp->path(),
        ))->scan();

        self::assertSame([0, 1], array_keys($keys));
    }

    public function testNoScannersProducesNothing(): void
    {
        $this->tmp->write('a.php', '');

        $keys = (new ScannerPipeline(
            $this->config([$this->tmp->path()]),
            $this->logger,
            [],
            rootDir: $this->tmp->path(),
        ))->scan();

        self::assertSame([], $keys);
    }

    // ------------------------------------------------------- path relativizing

    public function testOccurrencePathsAreRelativeToTheRootDir(): void
    {
        $this->tmp->write('src/deep/page.php', '');
        $php = $this->fakeScanner(['php']);

        (new ScannerPipeline(
            $this->config([$this->tmp->path()]),
            $this->logger,
            [$php],
            rootDir: $this->tmp->path(),
        ))->scan();

        self::assertSame(['src/deep/page.php'], $php->relativePaths());
    }

    public function testRootDirIsMatchedOnAWholeSegmentBoundary(): void
    {
        // A trailing slash is appended before comparing, so a sibling directory sharing
        // a name prefix must not be treated as being inside the root.
        $inside = $this->tmp->mkdir('app');
        $this->tmp->write('app-extra/other.php', '');
        $php = $this->fakeScanner(['php']);

        (new ScannerPipeline(
            $this->config([$this->tmp->path() . '/app-extra']),
            $this->logger,
            [$php],
            rootDir: $inside,
        ))->scan();

        // Not under the root → the absolute path is kept rather than a mangled suffix.
        self::assertSame([$this->tmp->path() . '/app-extra/other.php'], $php->relativePaths());
    }

    public function testTrailingSlashOnRootDirIsTolerated(): void
    {
        $this->tmp->write('src/page.php', '');
        $php = $this->fakeScanner(['php']);

        (new ScannerPipeline(
            $this->config([$this->tmp->path()]),
            $this->logger,
            [$php],
            rootDir: $this->tmp->path() . '/',
        ))->scan();

        self::assertSame(['src/page.php'], $php->relativePaths());
    }

    // ------------------------------------------------------------ root dir inference

    public function testInfersCommonRootDirFromMultipleScanPaths(): void
    {
        $this->tmp->write('proj/src/a.php', '');
        $this->tmp->write('proj/templates/b.php', '');
        $php = $this->fakeScanner(['php']);

        // No explicit rootDir — the longest common prefix is <tmp>/proj.
        (new ScannerPipeline(
            $this->config([$this->tmp->path() . '/proj/src', $this->tmp->path() . '/proj/templates']),
            $this->logger,
            [$php],
        ))->scan();

        $relatives = $php->relativePaths();
        sort($relatives);
        self::assertSame(['src/a.php', 'templates/b.php'], $relatives);
    }

    public function testInfersRootDirFromASingleScanPath(): void
    {
        $this->tmp->write('proj/src/a.php', '');
        $php = $this->fakeScanner(['php']);

        (new ScannerPipeline(
            $this->config([$this->tmp->path() . '/proj/src']),
            $this->logger,
            [$php],
        ))->scan();

        self::assertSame(['a.php'], $php->relativePaths());
    }

    public function testFallsBackToCwdWhenThereAreNoScanPaths(): void
    {
        $pipeline = new ScannerPipeline($this->config([]), $this->logger, [$this->fakeScanner(['php'])]);

        self::assertSame([], $pipeline->scan());
    }

    public function testDivergentScanPathsCollapseToTheSharedPrefix(): void
    {
        $this->tmp->write('alpha/a.php', '');
        $this->tmp->write('beta/b.php', '');
        $php = $this->fakeScanner(['php']);

        (new ScannerPipeline(
            $this->config([$this->tmp->path() . '/alpha', $this->tmp->path() . '/beta']),
            $this->logger,
            [$php],
        ))->scan();

        $relatives = $php->relativePaths();
        sort($relatives);
        self::assertSame(['alpha/a.php', 'beta/b.php'], $relatives);
    }

    /**
     * @param list<ScannedKey> $keys
     *
     * @return list<string>
     */
    private function sortedNames(array $keys): array
    {
        $names = array_map(static fn (ScannedKey $k): string => $k->name, $keys);
        sort($names);
        return $names;
    }
}
