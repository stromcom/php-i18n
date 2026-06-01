<?php

declare(strict_types=1);

namespace Stromcom\I18n\Console;

use Stromcom\I18n\Build\KeySync;
use Stromcom\I18n\Build\TranslatorClientException;
use Stromcom\I18n\Scan\ScannerPipeline;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'i18n:sync', description: 'Scan source files and POST discovered keys to translator (idempotent UPSERT).')]
final class SyncCommand extends Command
{
    public function __construct(
        private readonly ScannerPipeline $pipeline,
        private readonly KeySync $sync,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $keys = $this->pipeline->scan();
        $io->writeln(sprintf('<info>Scanned %d unique key(s).</info>', count($keys)));

        if ($keys === []) {
            $io->warning('Nothing to sync — scan returned no keys.');
            return Command::SUCCESS;
        }

        try {
            $result = $this->sync->sync($keys);
        } catch (TranslatorClientException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Sync OK — sent: %d, added: %d, updated: %d, stale on server: %d, total_in_sync: %d.',
            $result->sent,
            $result->added,
            $result->updated,
            count($result->stale),
            $result->totalInSync,
        ));

        if ($result->stale !== []) {
            $io->section('Stale keys on translator (exist in DB but not in current code)');
            $io->listing($result->stale);
            $io->note('Delete them manually in translator UI if no longer needed.');
        }

        return Command::SUCCESS;
    }
}
