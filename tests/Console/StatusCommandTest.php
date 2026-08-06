<?php

declare(strict_types=1);

namespace Stromcom\I18n\Tests\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use Stromcom\I18n\Config\I18nConfig;
use Stromcom\I18n\Console\StatusCommand;
use Stromcom\I18n\Scan\ScannedKey;
use Stromcom\I18n\Tests\Support\InMemoryBundleLoader;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(StatusCommand::class)]
final class StatusCommandTest extends ConsoleCommandTestCase
{
    /**
     * @param list<ScannedKey>                     $keys
     * @param array<string, array<string, string>> $bundles
     */
    private function runCommand(array $keys, array $bundles, ?I18nConfig $config = null): CommandTester
    {
        $config ??= $this->config();
        $command = new StatusCommand(
            $config,
            $this->pipeline($keys, $config),
            new InMemoryBundleLoader($bundles),
        );

        $tester = new CommandTester($command);
        $tester->execute([]);

        return $tester;
    }

    /**
     * @return list<ScannedKey>
     */
    private function threeKeys(): array
    {
        return [
            new ScannedKey('a', 'A', null, ['src/a.php:1']),
            new ScannedKey('b', 'B', null, ['src/a.php:2']),
            new ScannedKey('c', 'C', null, ['src/a.php:3']),
        ];
    }

    public function testReportsFullCoverage(): void
    {
        $tester = $this->runCommand(
            $this->threeKeys(),
            ['cs' => ['a' => 'A', 'b' => 'B', 'c' => 'C'], 'en' => ['a' => 'A', 'b' => 'B', 'c' => 'C']],
        );

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $output = $tester->getDisplay();
        self::assertStringContainsString('3 unique key(s) found in code', $output);
        self::assertStringContainsString('100.0 %', $output);
    }

    public function testReportsPartialCoverage(): void
    {
        $tester = $this->runCommand($this->threeKeys(), ['cs' => ['a' => 'A'], 'en' => []]);

        $output = $tester->getDisplay();
        // cs has 1 of 3 → 33.3 %, en has none → 0.0 %
        self::assertStringContainsString('33.3 %', $output);
        self::assertStringContainsString('0.0 %', $output);
    }

    public function testCountsOrphanKeysPresentOnlyInTheBundle(): void
    {
        $tester = $this->runCommand(
            [new ScannedKey('a', 'A', null, ['src/a.php:1'])],
            ['cs' => ['a' => 'A', 'legacy.one' => 'X', 'legacy.two' => 'Y'], 'en' => []],
        );

        $output = $tester->getDisplay();
        self::assertStringContainsString('cs', $output);
        // columns: locale, bundle keys, covered, missing, orphan, coverage
        self::assertMatchesRegularExpression('/cs\s+3\s+1\s+0\s+2\s+100\.0 %/', $output);
    }

    public function testHandlesAnEmptySourceWithoutDividingByZero(): void
    {
        $tester = $this->runCommand([], ['cs' => ['orphan' => 'X'], 'en' => []]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $output = $tester->getDisplay();
        self::assertStringContainsString('0 unique key(s) found in code', $output);
        self::assertStringContainsString('0.0 %', $output);
    }

    public function testListsEveryConfiguredTargetLocale(): void
    {
        $tester = $this->runCommand(
            $this->threeKeys(),
            ['cs' => []],
            $this->config(targetLocales: ['cs', 'en', 'de']),
        );

        $output = $tester->getDisplay();
        foreach (['cs', 'en', 'de'] as $locale) {
            self::assertStringContainsString($locale, $output);
        }
    }

    public function testAMissingBundleCountsAsZeroCoverage(): void
    {
        $tester = $this->runCommand($this->threeKeys(), []);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('0.0 %', $tester->getDisplay());
    }
}
