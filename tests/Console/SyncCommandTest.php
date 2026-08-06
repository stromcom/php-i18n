<?php

declare(strict_types=1);

namespace Stromcom\I18n\Tests\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use Stromcom\I18n\Build\KeySync;
use Stromcom\I18n\Build\TranslatorClient;
use Stromcom\I18n\Config\I18nConfig;
use Stromcom\I18n\Console\SyncCommand;
use Stromcom\I18n\Scan\ScannedKey;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(SyncCommand::class)]
final class SyncCommandTest extends ConsoleCommandTestCase
{
    /**
     * @param list<ScannedKey> $keys
     */
    private function runCommand(array $keys, MockResponse $response): CommandTester
    {
        $config = $this->config();
        $client = new TranslatorClient($config, new MockHttpClient(static fn (): MockResponse => $response));
        $command = new SyncCommand(
            $this->pipeline($keys, $config),
            new KeySync($config, $client, $this->logger),
        );

        $tester = new CommandTester($command);
        $tester->execute([]);

        return $tester;
    }

    private function okResponse(int $added = 2, int $updated = 0, string $stale = '[]'): MockResponse
    {
        return new MockResponse(sprintf(
            '{"added":%d,"updated":%d,"stale":%s,"total_in_sync":7}',
            $added,
            $updated,
            $stale,
        ));
    }

    public function testReportsTheSyncSummary(): void
    {
        $tester = $this->runCommand($this->sampleKeys(), $this->okResponse());

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $output = $tester->getDisplay();
        self::assertStringContainsString('Scanned 2 unique key(s).', $output);
        self::assertStringContainsString('sent: 2', $output);
        self::assertStringContainsString('added: 2', $output);
        self::assertStringContainsString('total_in_sync: 7', $output);
    }

    public function testSkipsTheRequestWhenTheScanFoundNothing(): void
    {
        $config = $this->config();
        $called = false;
        $client = new TranslatorClient($config, new MockHttpClient(static function () use (&$called): MockResponse {
            $called = true;
            return new MockResponse('{}');
        }));
        $command = new SyncCommand($this->pipeline([], $config), new KeySync($config, $client, $this->logger));

        $tester = new CommandTester($command);
        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Nothing to sync', $tester->getDisplay());
        self::assertFalse($called, 'no HTTP request for an empty key set');
    }

    public function testListsStaleKeysReturnedByTheServer(): void
    {
        $tester = $this->runCommand(
            $this->sampleKeys(),
            $this->okResponse(stale: '["old.one","old.two"]'),
        );

        $output = $tester->getDisplay();
        self::assertStringContainsString('stale on server: 2', $output);
        self::assertStringContainsString('old.one', $output);
        self::assertStringContainsString('old.two', $output);
        self::assertStringContainsString('Delete them manually', $output);
    }

    public function testNoStaleSectionWhenThereAreNoStaleKeys(): void
    {
        $tester = $this->runCommand($this->sampleKeys(), $this->okResponse());

        self::assertStringNotContainsString('Stale keys on translator', $tester->getDisplay());
    }

    public function testFailsOnAServerError(): void
    {
        $tester = $this->runCommand(
            $this->sampleKeys(),
            new MockResponse('upstream down', ['http_code' => 503]),
        );

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('HTTP 503', $tester->getDisplay());
    }

    public function testFailsOnAnUnparseableResponse(): void
    {
        $tester = $this->runCommand($this->sampleKeys(), new MockResponse('<html/>'));

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('not JSON', $tester->getDisplay());
    }
}
