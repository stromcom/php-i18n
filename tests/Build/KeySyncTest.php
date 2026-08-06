<?php

declare(strict_types=1);

namespace Stromcom\I18n\Tests\Build;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stromcom\I18n\Build\KeySync;
use Stromcom\I18n\Build\SyncResult;
use Stromcom\I18n\Build\TranslatorClient;
use Stromcom\I18n\Build\TranslatorClientException;
use Stromcom\I18n\Config\I18nConfig;
use Stromcom\I18n\Scan\ScannedKey;
use Stromcom\I18n\Tests\Support\CollectingLogger;
use Stromcom\I18n\Tests\Support\HttpRecorder;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(KeySync::class)]
#[CoversClass(SyncResult::class)]
#[CoversClass(ScannedKey::class)]
final class KeySyncTest extends TestCase
{
    private CollectingLogger $logger;
    private HttpRecorder $recorder;

    protected function setUp(): void
    {
        $this->logger = new CollectingLogger();
        $this->recorder = new HttpRecorder();
    }

    private function config(string $projectId = 'proj'): I18nConfig
    {
        return new I18nConfig(
            projectId: $projectId,
            token: 'tok',
            baseUrl: 'https://translator.test',
            sourceLocale: 'en',
            targetLocales: ['en'],
            fallbackLocale: 'en',
            bundlesDir: '/tmp',
            scanPaths: [],
        );
    }

    private function sync(MockResponse $response, ?I18nConfig $config = null): KeySync
    {
        $config ??= $this->config();
        $mock = $this->recorder->alwaysReturning($response);

        return new KeySync($config, new TranslatorClient($config, $mock), $this->logger);
    }

    /**
     * @return list<ScannedKey>
     */
    private function keys(): array
    {
        return [
            new ScannedKey('a.key', 'Alpha', 'a note', ['src/a.php:1']),
            new ScannedKey('b.key', 'Beta', null, ['src/b.php:2', 'src/c.php:3']),
        ];
    }

    public function testPostsAllKeysAsOnePayload(): void
    {
        $this->sync(new MockResponse('{"added":2,"updated":0,"total_in_sync":2}'))->sync($this->keys());

        $request = $this->recorder->first();
        self::assertSame('POST', $request->method);
        self::assertSame('https://translator.test/api/v1/projects/proj/keys/sync', $request->url);

        $body = json_decode($request->body(), true, 8, JSON_THROW_ON_ERROR);
        self::assertSame([
            'keys' => [
                ['name' => 'a.key', 'source_text' => 'Alpha', 'description' => 'a note', 'occurrences' => ['src/a.php:1']],
                ['name' => 'b.key', 'source_text' => 'Beta', 'occurrences' => ['src/b.php:2', 'src/c.php:3']],
            ],
        ], $body);
    }

    public function testProjectIdIsUrlEncoded(): void
    {
        $this->sync(new MockResponse('{}'), $this->config('proj id/x'))->sync([]);

        self::assertSame(
            'https://translator.test/api/v1/projects/proj%20id%2Fx/keys/sync',
            $this->recorder->first()->url,
        );
    }

    public function testReturnsTheParsedCounts(): void
    {
        $response = new MockResponse(json_encode([
            'added'         => 3,
            'updated'       => 1,
            'stale'         => ['gone.key', 'old.key'],
            'total_in_sync' => 42,
        ], JSON_THROW_ON_ERROR));

        $result = $this->sync($response)->sync($this->keys());

        self::assertSame(3, $result->added);
        self::assertSame(1, $result->updated);
        self::assertSame(['gone.key', 'old.key'], $result->stale);
        self::assertSame(42, $result->totalInSync);
        self::assertSame(2, $result->sent);
    }

    public function testMissingCountsDefaultToZeroAndStaleToEmpty(): void
    {
        $result = $this->sync(new MockResponse('{}'))->sync($this->keys());

        self::assertSame(0, $result->added);
        self::assertSame(0, $result->updated);
        self::assertSame([], $result->stale);
        self::assertSame(2, $result->totalInSync, 'total_in_sync falls back to the number sent');
    }

    public function testNonIntegerCountsAreIgnored(): void
    {
        $response = new MockResponse('{"added":"3","updated":null,"total_in_sync":true}');

        $result = $this->sync($response)->sync($this->keys());

        self::assertSame(0, $result->added);
        self::assertSame(0, $result->updated);
        self::assertSame(2, $result->totalInSync);
    }

    public function testNonStringStaleEntriesAreDiscarded(): void
    {
        $response = new MockResponse('{"stale":["ok.key",123,null,{"a":1}]}');

        $result = $this->sync($response)->sync([]);

        self::assertSame(['ok.key'], $result->stale);
    }

    public function testNonArrayStaleIsIgnored(): void
    {
        $result = $this->sync(new MockResponse('{"stale":"not-an-array"}'))->sync([]);

        self::assertSame([], $result->stale);
    }

    public function testLogsTheOutcome(): void
    {
        $this->sync(new MockResponse('{"added":1,"updated":0,"total_in_sync":1}'))->sync($this->keys());

        self::assertTrue($this->logger->hasRecordContaining('info', 'KeySync: ok'));
        self::assertSame(
            ['added' => 1, 'updated' => 0, 'stale' => 0, 'total_in_sync' => 1, 'sent' => 2],
            $this->logger->contextOfFirstContaining('KeySync: ok'),
        );
    }

    public function testEmptyKeyListIsStillSent(): void
    {
        $result = $this->sync(new MockResponse('{"total_in_sync":0}'))->sync([]);

        self::assertSame(0, $result->sent);
        self::assertSame('{"keys":[]}', $this->recorder->first()->body());
    }

    public function testThrowsOnHttpError(): void
    {
        $sync = $this->sync(new MockResponse('server exploded', ['http_code' => 500]));

        $this->expectException(TranslatorClientException::class);
        $this->expectExceptionMessage('Key sync failed: HTTP 500 — server exploded');
        $sync->sync($this->keys());
    }

    public function testThrowsOnUnauthorised(): void
    {
        $sync = $this->sync(new MockResponse('{"error":"bad token"}', ['http_code' => 401]));

        try {
            $sync->sync([]);
            self::fail('Expected TranslatorClientException');
        } catch (TranslatorClientException $e) {
            self::assertStringContainsString('HTTP 401', $e->getMessage());
        }
    }

    public function testThrowsWhenResponseIsNotJson(): void
    {
        $sync = $this->sync(new MockResponse('<html>gateway</html>', ['http_code' => 200]));

        $this->expectException(TranslatorClientException::class);
        $this->expectExceptionMessage('Sync response is not JSON');
        $sync->sync([]);
    }

    public function testThrowsWhenResponseRootIsNotAnObject(): void
    {
        $sync = $this->sync(new MockResponse('"a string"', ['http_code' => 200]));

        $this->expectException(TranslatorClientException::class);
        $this->expectExceptionMessage('Sync response root is not an object');
        $sync->sync([]);
    }

    public function testA300StatusIsTreatedAsFailure(): void
    {
        $sync = $this->sync(new MockResponse('moved', ['http_code' => 300]));

        $this->expectException(TranslatorClientException::class);
        $sync->sync([]);
    }

    public function testA299StatusIsTreatedAsSuccess(): void
    {
        $result = $this->sync(new MockResponse('{"added":1}', ['http_code' => 299]))->sync([]);

        self::assertSame(1, $result->added);
    }
}
