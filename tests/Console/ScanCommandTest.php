<?php

declare(strict_types=1);

namespace Stromcom\I18n\Tests\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use Stromcom\I18n\Console\ScanCommand;
use Stromcom\I18n\Scan\ScannedKey;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(ScanCommand::class)]
final class ScanCommandTest extends ConsoleCommandTestCase
{
    /**
     * @param list<ScannedKey> $keys
     */
    private function runCommand(array $keys): CommandTester
    {
        $tester = new CommandTester(new ScanCommand($this->pipeline($keys)));
        $tester->execute([]);

        return $tester;
    }

    public function testListsDiscoveredKeys(): void
    {
        $tester = $this->runCommand($this->sampleKeys());

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $output = $tester->getDisplay();
        self::assertStringContainsString('2 unique key(s)', $output);
        self::assertStringContainsString('page.title', $output);
        self::assertStringContainsString('Welcome', $output);
        self::assertStringContainsString('Homepage heading', $output);
    }

    public function testShowsTheSingleOccurrenceInline(): void
    {
        $tester = $this->runCommand([new ScannedKey('a', 'A', null, ['src/only.php:12'])]);

        self::assertStringContainsString('src/only.php:12', $tester->getDisplay());
    }

    public function testCollapsesMultipleOccurrencesToACount(): void
    {
        $tester = $this->runCommand([
            new ScannedKey('a', 'A', null, ['src/a.php:1', 'src/b.php:2', 'src/c.php:3']),
        ]);

        self::assertStringContainsString('3×', $tester->getDisplay());
    }

    public function testWarnsWhenNothingIsFound(): void
    {
        $tester = $this->runCommand([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('0 unique key(s)', $tester->getDisplay());
        self::assertStringContainsString('No keys found', $tester->getDisplay());
    }

    public function testLongSourceTextIsTruncatedWithAnEllipsis(): void
    {
        $tester = $this->runCommand([new ScannedKey('a', str_repeat('x', 100), null, ['src/a.php:1'])]);

        $output = $tester->getDisplay();
        self::assertStringContainsString('…', $output);
        self::assertStringNotContainsString(str_repeat('x', 100), $output);
        self::assertStringContainsString(str_repeat('x', 59) . '…', $output);
    }

    public function testTextExactlyAtTheLimitIsNotTruncated(): void
    {
        $tester = $this->runCommand([new ScannedKey('a', str_repeat('y', 60), null, ['src/a.php:1'])]);

        self::assertStringContainsString(str_repeat('y', 60), $tester->getDisplay());
    }

    public function testTruncationCountsCharactersNotBytes(): void
    {
        // 70 multibyte characters — a byte-based cut would slice one in half.
        $tester = $this->runCommand([new ScannedKey('a', str_repeat('ř', 70), null, ['src/a.php:1'])]);

        $output = $tester->getDisplay();
        self::assertStringContainsString(str_repeat('ř', 59) . '…', $output);
    }

    public function testLongNoteIsTruncated(): void
    {
        $tester = $this->runCommand([new ScannedKey('a', 'A', str_repeat('n', 80), ['src/a.php:1'])]);

        self::assertStringContainsString(str_repeat('n', 39) . '…', $tester->getDisplay());
    }

    public function testMissingNoteRendersAsAnEmptyCell(): void
    {
        $tester = $this->runCommand([new ScannedKey('no.note', 'A', null, ['src/a.php:1'])]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('no.note', $tester->getDisplay());
    }
}
