<?php

declare(strict_types=1);

namespace Stromcom\I18n\Tests\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use Stromcom\I18n\Build\BundleFetcher;
use Stromcom\I18n\Build\EtagStore;
use Stromcom\I18n\Build\PhpCacheWriter;
use Stromcom\I18n\Build\TranslatorClient;
use Stromcom\I18n\Config\I18nConfig;
use Stromcom\I18n\Console\FetchCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(FetchCommand::class)]
final class FetchCommandTest extends ConsoleCommandTestCase
{
    /** @var list<string> */
    private array $requestedUrls = [];

    /**
     * @param array<string, MockResponse>|MockResponse $responses Keyed by locale, or one for all.
     * @param array<string, mixed>                     $input
     */
    private function runCommand(
        array|MockResponse $responses,
        array $input = [],
        ?I18nConfig $config = null,
    ): CommandTester {
        $config ??= $this->config();
        $client = new TranslatorClient($config, new MockHttpClient(
            function (string $method, string $url) use ($responses): MockResponse {
                $this->requestedUrls[] = $url;
                if ($responses instanceof MockResponse) {
                    return $responses;
                }
                foreach ($responses as $locale => $response) {
                    if (str_contains($url, '/bundles/' . $locale)) {
                        return $response;
                    }
                }
                return new MockResponse('{}');
            },
        ));

        $fetcher = new BundleFetcher(
            $config,
            $client,
            new EtagStore($this->tmp->path() . '/.i18n-etags.json'),
            $this->logger,
            new PhpCacheWriter(),
        );

        $tester = new CommandTester(new FetchCommand($config, $fetcher));
        $tester->execute($input);

        return $tester;
    }

    private function bundle(string $locale): MockResponse
    {
        return new MockResponse(
            json_encode(['locale' => $locale, 'translations' => ['a' => 'A']], JSON_THROW_ON_ERROR),
        );
    }

    public function testFetchesEveryConfiguredLocaleByDefault(): void
    {
        $tester = $this->runCommand(['cs' => $this->bundle('cs'), 'en' => $this->bundle('en')]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Bundles up to date.', $tester->getDisplay());
        self::assertCount(2, $this->requestedUrls);
        self::assertFileExists($this->tmp->path() . '/locales/cs.json');
        self::assertFileExists($this->tmp->path() . '/locales/en.json');
    }

    public function testDefaultsToThePublishedVersion(): void
    {
        $tester = $this->runCommand($this->bundle('cs'));

        self::assertStringContainsString('Fetching published bundles for: cs, en', $tester->getDisplay());
        self::assertStringContainsString('version=published', $this->requestedUrls[0]);
    }

    public function testDraftOptionSwitchesTheVersion(): void
    {
        $tester = $this->runCommand($this->bundle('cs'), ['--draft' => true]);

        self::assertStringContainsString('Fetching draft bundles', $tester->getDisplay());
        self::assertStringContainsString('version=draft', $this->requestedUrls[0]);
    }

    public function testLocaleOptionRestrictsToOneLocale(): void
    {
        $tester = $this->runCommand($this->bundle('cs'), ['--locale' => 'cs']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertCount(1, $this->requestedUrls);
        self::assertStringContainsString('/bundles/cs', $this->requestedUrls[0]);
    }

    public function testUnsupportedLocaleOptionFailsWithoutAnyRequest(): void
    {
        $tester = $this->runCommand($this->bundle('cs'), ['--locale' => 'fr']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Locale "fr" is not in configured targetLocales.', $tester->getDisplay());
        self::assertSame([], $this->requestedUrls);
    }

    public function testReportsWrittenAndCachedPerLocale(): void
    {
        $tester = $this->runCommand([
            'cs' => $this->bundle('cs'),
            'en' => new MockResponse('', ['http_code' => 304]),
        ]);

        $output = $tester->getDisplay();
        self::assertStringContainsString('[cs] HTTP 200 — written', $output);
        self::assertStringContainsString('[en] HTTP 304 — cached (304)', $output);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    public function testMissingKeysCause422FailureAndListTheKeys(): void
    {
        $body = json_encode(['missing_keys' => ['a.key', 'b.key']], JSON_THROW_ON_ERROR);
        $tester = $this->runCommand([
            'cs' => new MockResponse($body, ['http_code' => 422]),
            'en' => $this->bundle('en'),
        ]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        $output = $tester->getDisplay();
        self::assertStringContainsString('[cs] 422 — 2 missing key(s)', $output);
        self::assertStringContainsString('a.key', $output);
        self::assertStringContainsString('b.key', $output);
    }

    public function testLongMissingKeyListIsTruncatedToTwenty(): void
    {
        $keys = array_map(static fn (int $i): string => 'key.' . $i, range(1, 25));
        $body = json_encode(['missing_keys' => $keys], JSON_THROW_ON_ERROR);
        $tester = $this->runCommand(new MockResponse($body, ['http_code' => 422]));

        $output = $tester->getDisplay();
        self::assertStringContainsString('key.20', $output);
        self::assertStringContainsString('and 5 more', $output);
        self::assertStringNotContainsString('key.21', $output);
    }

    public function test422WithoutAnyKeyListStillFails(): void
    {
        $tester = $this->runCommand(new MockResponse('{"missing_keys":[]}', ['http_code' => 422]));

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('0 missing key(s)', $tester->getDisplay());
    }

    public function testServerErrorForOneLocaleFailsTheWholeCommand(): void
    {
        $tester = $this->runCommand([
            'cs' => new MockResponse('boom', ['http_code' => 500]),
            'en' => $this->bundle('en'),
        ]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        $output = $tester->getDisplay();
        self::assertStringContainsString('[cs]', $output);
        self::assertStringContainsString('Failed for: cs', $output);
        self::assertFileExists($this->tmp->path() . '/locales/en.json', 'other locales still get fetched');
    }

    public function testAllLocalesFailingAreListedTogether(): void
    {
        $tester = $this->runCommand(new MockResponse('boom', ['http_code' => 500]));

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Failed for: cs, en', $tester->getDisplay());
    }
}
