<?php

declare(strict_types=1);

namespace Stromcom\I18n\Console;

use Stromcom\I18n\Scan\ScannerPipeline;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'i18n:scan', description: 'Scan source files and list discovered i18n keys (debug — does not push to translator).')]
final class ScanCommand extends Command
{
    public function __construct(private readonly ScannerPipeline $pipeline)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $keys = $this->pipeline->scan();
        $io->title(sprintf('i18n scan — %d unique key(s)', count($keys)));

        if ($keys === []) {
            $io->warning('No keys found. Check scanPaths in I18nConfig.');
            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($keys as $key) {
            $rows[] = [
                $key->name,
                $this->truncate($key->sourceText, 60),
                $key->description !== null ? $this->truncate($key->description, 40) : '',
                count($key->occurrences) === 1 ? $key->occurrences[0] : count($key->occurrences) . '×',
            ];
        }
        $io->table(['key', 'source_text', 'note', 'occurrences'], $rows);

        return Command::SUCCESS;
    }

    private function truncate(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        return mb_substr($text, 0, $max - 1) . '…';
    }
}
