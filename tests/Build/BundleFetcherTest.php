<?php

declare(strict_types=1);

namespace Stromcom\I18n\Tests\Build;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stromcom\I18n\Build\BundleFetcher;
use Stromcom\I18n\Build\EtagStore;
use Stromcom\I18n\Build\FetchResult;
use Stromcom\I18n\Build\PhpCacheWriter;
use Stromcom\I18n\Build\TranslatorClient;
use Stromcom\I18n\Build\TranslatorClientException;
use Stromcom\I18n\Config\I18nConfig;
use Stromcom\I18n\Tests\Support\CollectingLogger;
use Stromcom\I18n\Tests\Support\HttpRecorder;
use Stromcom\I18n\Tests\Support\TmpDir;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(BundleFetcher::class)]
#[CoversClass(FetchResult::class)]
final class BundleFetcherTest extends TestCase
{
    private TmpDir $tmp;
    private CollectingLogger $logger;
    private EtagStore $etags;

    private HttpRecorder $recorder;

    protected function setUp(): void
    {
        $this->tmp = new TmpDir('stromcom-i18n-fetcher');
        $this->logger = new CollectingLogger();
        $this->recorder = new HttpRecorder();
        $this->etags = new EtagStore($this->tmp->path() . '/.i18n-etags.json');
    }

    protected function tearDown(): void
    {
        $this->tmp->cleanup();
    }

    private function bundlesDir(): string
    {
        return $this->tmp->path() . '/locales';
    }

    private function config(?string $bundlesDir = null): I18nConfig
    {
        return new I18nConfig(
            projectId: 'proj',
            token: 'tok',
            baseUrl: 'https://translator.test',
            sourceLocale: 'en',
            targetLocales: ['cs', 'en'],
            fallbackLocale: 'en',
            bundlesDir: $bundlesDir ?? $this->bundlesDir(),
            scanPaths: [],
        );
    }

    private function fetcher(MockResponse $response, ?I18nConfig $config = null): BundleFetcher
    {
        $config ??= $this->config();
        $mock = $this->recorder->alwaysReturning($response);

        return new BundleFetcher(
            $config,
            new TranslatorClient($config, $mock),
            $this->etags,
            $this->logger,
            new PhpCacheWriter(),
        );
    }

    /**
     * @param array<string, string> $translations
     */
    private function bundleBody(
        array $translations,
        string $locale = 'cs',
        string $generatedAt = '2026-01-01T00:00:00Z',
    ): string {
        return json_encode([
            'version'      => 3,
            'locale'       => $locale,
            'generated_at' => $generatedAt,
            'translations' => $translations,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, string>
     */
    private function writtenBundle(string $locale = 'cs'): array
    {
        /** @var array<string, string> $decoded */
        $decoded = json_decode((string) file_get_contents($this->bundlesDir() . "/$locale.json"), true, 8, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    // ------------------------------------------------------------ happy path

    public function testWritesTheBundleAndReportsSuccess(): void
    {
        $body = $this->bundleBody(['a' => 'A']);

        $result = $this->fetcher(new MockResponse($body, ['http_code' => 200]))->fetch('cs');

        self::assertTrue($result->isOk());
        self::assertTrue($result->written);
        self::assertSame(200, $result->status);
        self::assertSame('cs', $result->locale);
        self::assertSame(['a' => 'A'], $this->writtenBundle());
    }

    // ------------------------------------------------- deterministic on disk

    public function testWritesOnlyTheFlatTranslationsMapWithoutTheEnvelope(): void
    {
        $this->fetcher(new MockResponse($this->bundleBody(['a' => 'A'])))->fetch('cs');

        $json = (string) file_get_contents($this->bundlesDir() . '/cs.json');
        self::assertStringNotContainsString('generated_at', $json);
        self::assertStringNotContainsString('translations', $json);
        self::assertStringEndsWith("\n", $json);
    }

    public function testSortsKeysSoThatResponseOrderDoesNotChangeTheFile(): void
    {
        $this->fetcher(new MockResponse($this->bundleBody(['b' => 'B', 'a' => 'A'])))->fetch('cs');

        self::assertSame(['a', 'b'], array_keys($this->writtenBundle()));
    }

    public function testTwoFetchesOfTheSameContentProduceIdenticalBytes(): void
    {
        // Bundle files are committed build inputs — a publish gate compares them
        // against the published version, so an unstable byte for byte result
        // (fetch timestamp, key order) would make that comparison always fail.
        $this->fetcher(new MockResponse($this->bundleBody(['a' => 'A'], generatedAt: '2026-01-01T00:00:00Z')))->fetch('cs');
        $first = (string) file_get_contents($this->bundlesDir() . '/cs.json');

        $this->fetcher(new MockResponse($this->bundleBody(['a' => 'A'], generatedAt: '2026-06-30T12:34:56Z')))->fetch('cs');
        $second = (string) file_get_contents($this->bundlesDir() . '/cs.json');

        self::assertSame($first, $second);
    }

    public function testWritesPrettyPrintedJsonWithUnescapedSlashesAndUnicode(): void
    {
        // Byte for byte: the file is a committed build input the publish gate diffs
        // against the published bundle, so indentation and escaping are part of the
        // contract, not a cosmetic detail.
        $this->fetcher(new MockResponse($this->bundleBody(['nav/home' => 'Domů'])))->fetch('cs');

        self::assertStringEqualsFile(
            $this->bundlesDir() . '/cs.json',
            "{\n    \"nav/home\": \"Domů\"\n}\n",
        );
    }

    public function testEmptyBundleIsWrittenAsAnObjectNotAnArray(): void
    {
        $this->fetcher(new MockResponse($this->bundleBody([])))->fetch('cs');

        self::assertStringEqualsFile($this->bundlesDir() . '/cs.json', "{}\n");
    }

    public function testAcceptsAFlatBodyWithoutTheEnvelope(): void
    {
        $body = json_encode(['b' => 'B', 'a' => 'A'], JSON_THROW_ON_ERROR);

        $this->fetcher(new MockResponse($body))->fetch('cs');

        self::assertSame(['a' => 'A', 'b' => 'B'], $this->writtenBundle());
    }

    public function testRequestsTheCorrectUrlWithTheVersionQuery(): void
    {
        $this->fetcher(new MockResponse($this->bundleBody([])))->fetch('cs', 'draft');

        self::assertSame('GET', $this->recorder->first()->method);
        self::assertSame(
            'https://translator.test/api/v1/projects/proj/bundles/cs?version=draft',
            $this->recorder->first()->url,
        );
    }

    public function testDefaultsToThePublishedVersion(): void
    {
        $this->fetcher(new MockResponse($this->bundleBody([])))->fetch('cs');

        self::assertStringContainsString('version=published', $this->recorder->first()->url);
    }

    public function testProjectIdAndLocaleAreUrlEncoded(): void
    {
        $this->fetcher(new MockResponse($this->bundleBody([])))->fetch('pt/BR');

        self::assertStringContainsString('/bundles/pt%2FBR', $this->recorder->first()->url);
    }

    public function testCreatesTheBundlesDirectoryWhenMissing(): void
    {
        self::assertDirectoryDoesNotExist($this->bundlesDir());

        $this->fetcher(new MockResponse($this->bundleBody(['a' => 'A'])))->fetch('cs');

        self::assertFileExists($this->bundlesDir() . '/cs.json');
    }

    public function testWritesThePhpCacheAlongsideTheJson(): void
    {
        $this->fetcher(new MockResponse($this->bundleBody(['a' => 'A', 'b' => 'B'])))->fetch('cs');

        $cachePath = $this->bundlesDir() . '/cs.cache.php';
        self::assertFileExists($cachePath);
        /** @var mixed $cached */
        $cached = require $cachePath;
        self::assertSame(['a' => 'A', 'b' => 'B'], $cached);
    }

    public function testPhpCacheHoldsOnlyTheTranslationsMapNotTheWrapper(): void
    {
        $this->fetcher(new MockResponse($this->bundleBody(['a' => 'A'])))->fetch('cs');

        /** @var mixed $cached */
        $cached = require $this->bundlesDir() . '/cs.cache.php';
        self::assertIsArray($cached);
        self::assertArrayNotHasKey('version', $cached);
        self::assertArrayNotHasKey('translations', $cached);
    }

    public function testAcceptsAFlatBundleWithoutTheTranslationsWrapper(): void
    {
        $this->fetcher(new MockResponse('{"a":"A","b":"B"}'))->fetch('cs');

        /** @var mixed $cached */
        $cached = require $this->bundlesDir() . '/cs.cache.php';
        self::assertSame(['a' => 'A', 'b' => 'B'], $cached);
    }

    public function testNonStringTranslationValuesAreDroppedFromThePhpCache(): void
    {
        $body = json_encode([
            'translations' => ['ok' => 'A', 'num' => 5, 'nested' => ['x'], 'nul' => null],
        ], JSON_THROW_ON_ERROR);

        $this->fetcher(new MockResponse($body))->fetch('cs');

        /** @var mixed $cached */
        $cached = require $this->bundlesDir() . '/cs.cache.php';
        self::assertSame(['ok' => 'A'], $cached);
    }

    public function testStoresTheEtagFromTheResponse(): void
    {
        $response = new MockResponse($this->bundleBody([]), [
            'http_code'        => 200,
            'response_headers' => ['ETag' => '"v3-abc"'],
        ]);

        $this->fetcher($response)->fetch('cs');

        self::assertSame('"v3-abc"', $this->etags->get('cs'));
    }

    public function testNoEtagHeaderLeavesTheStoreUntouched(): void
    {
        $this->fetcher(new MockResponse($this->bundleBody([])))->fetch('cs');

        self::assertNull($this->etags->get('cs'));
    }

    public function testLogsTheWriteWithTheLocalePathAndSizeOfWhatLandedOnDisk(): void
    {
        $this->fetcher(new MockResponse($this->bundleBody(['a' => 'A'])))->fetch('cs');

        $path = $this->bundlesDir() . '/cs.json';
        self::assertTrue($this->logger->hasRecordContaining('info', 'BundleFetcher: written'));
        // The size is the canonical file, not the response — the two differ since
        // the envelope is stripped before the write.
        self::assertSame(
            ['locale' => 'cs', 'bytes' => strlen((string) file_get_contents($path)), 'path' => $path],
            $this->logger->contextOfFirstContaining('BundleFetcher: written'),
        );
    }

    // ------------------------------------------------------- conditional GET

    public function testSendsIfNoneMatchWhenAnEtagAndALocalFileExist(): void
    {
        $this->etags->set('cs', '"cached"');
        $this->tmp->write('locales/cs.json', '{}');

        $this->fetcher(new MockResponse($this->bundleBody([])))->fetch('cs');

        self::assertContains('If-None-Match: "cached"', $this->recorder->first()->headers());
    }

    public function testOmitsIfNoneMatchWhenTheLocalFileIsMissing(): void
    {
        // A stored etag without the bundle on disk would otherwise yield a 304 and
        // leave the project with no bundle at all.
        $this->etags->set('cs', '"cached"');

        $this->fetcher(new MockResponse($this->bundleBody([])))->fetch('cs');

        self::assertNotContains('If-None-Match: "cached"', $this->recorder->first()->headers());
    }

    public function testOmitsIfNoneMatchWhenNoEtagIsStored(): void
    {
        $this->tmp->write('locales/cs.json', '{}');

        $this->fetcher(new MockResponse($this->bundleBody([])))->fetch('cs');

        self::assertFalse($this->recorder->first()->hasHeaderNamed('If-None-Match'));
    }

    public function testNotModifiedKeepsTheExistingFile(): void
    {
        $this->tmp->write('locales/cs.json', '{"keep":"me"}');
        $this->etags->set('cs', '"cached"');

        $result = $this->fetcher(new MockResponse('', ['http_code' => 304]))->fetch('cs');

        self::assertSame(304, $result->status);
        self::assertFalse($result->written);
        self::assertTrue($result->isOk());
        self::assertStringEqualsFile($this->bundlesDir() . '/cs.json', '{"keep":"me"}');
        self::assertTrue($this->logger->hasRecordContaining('info', 'not modified'));
    }

    // -------------------------------------------------------------- failures

    public function test422ReturnsTheMissingKeys(): void
    {
        $body = json_encode(['missing_keys' => ['a.key', 'b.key']], JSON_THROW_ON_ERROR);

        $result = $this->fetcher(new MockResponse($body, ['http_code' => 422]))->fetch('cs');

        self::assertSame(422, $result->status);
        self::assertFalse($result->written);
        self::assertFalse($result->isOk());
        self::assertSame(['a.key', 'b.key'], $result->missingKeys);
        self::assertTrue($this->logger->hasRecordContaining('error', '422 missing keys'));
    }

    public function test422WithNonStringEntriesKeepsOnlyStrings(): void
    {
        $body = json_encode(['missing_keys' => ['a.key', 42, null]], JSON_THROW_ON_ERROR);

        $result = $this->fetcher(new MockResponse($body, ['http_code' => 422]))->fetch('cs');

        self::assertSame(['a.key'], $result->missingKeys);
    }

    public function test422WithAnUnparseableBodyYieldsNoKeys(): void
    {
        $result = $this->fetcher(new MockResponse('not json', ['http_code' => 422]))->fetch('cs');

        self::assertSame(422, $result->status);
        self::assertSame([], $result->missingKeys);
    }

    public function test422WithoutTheMissingKeysFieldYieldsNoKeys(): void
    {
        $result = $this->fetcher(new MockResponse('{"error":"x"}', ['http_code' => 422]))->fetch('cs');

        self::assertSame([], $result->missingKeys);
    }

    public function test422DoesNotWriteAnyFile(): void
    {
        $this->fetcher(new MockResponse('{"missing_keys":["a"]}', ['http_code' => 422]))->fetch('cs');

        self::assertFileDoesNotExist($this->bundlesDir() . '/cs.json');
    }

    public function testServerErrorThrows(): void
    {
        $fetcher = $this->fetcher(new MockResponse('boom', ['http_code' => 500]));

        $this->expectException(TranslatorClientException::class);
        $this->expectExceptionMessage('Bundle fetch failed for locale "cs": HTTP 500 — boom');
        $fetcher->fetch('cs');
    }

    public function testNotFoundThrows(): void
    {
        $fetcher = $this->fetcher(new MockResponse('{"error":"no such locale"}', ['http_code' => 404]));

        $this->expectException(TranslatorClientException::class);
        $this->expectExceptionMessage('HTTP 404');
        $fetcher->fetch('xx');
    }

    public function testThrowsWhenTheBundlesDirCannotBeCreated(): void
    {
        // A regular file where the directory should be — mkdir cannot succeed.
        $blocker = $this->tmp->write('blocked', 'x');
        $config = $this->config($blocker . '/locales');
        $fetcher = $this->fetcher(new MockResponse($this->bundleBody([])), $config);

        $this->expectException(TranslatorClientException::class);
        $this->expectExceptionMessage('Cannot create bundles dir');
        $fetcher->fetch('cs');
    }

    public function testMalformedJsonBodyStillWritesTheJsonButSkipsThePhpCache(): void
    {
        // The raw body is trusted enough to store; the derived cache is best-effort,
        // and BundleLoader can still fall back to the JSON.
        $result = $this->fetcher(new MockResponse('{not json', ['http_code' => 200]))->fetch('cs');

        self::assertTrue($result->written);
        self::assertFileExists($this->bundlesDir() . '/cs.json');
        self::assertFileDoesNotExist($this->bundlesDir() . '/cs.cache.php');
        self::assertTrue($this->logger->hasRecordContaining('warning', 'PHP cache skipped'));
    }

    public function testNonArrayJsonBodySkipsThePhpCache(): void
    {
        $result = $this->fetcher(new MockResponse('"a string"', ['http_code' => 200]))->fetch('cs');

        self::assertTrue($result->written);
        self::assertFileDoesNotExist($this->bundlesDir() . '/cs.cache.php');
    }
}
