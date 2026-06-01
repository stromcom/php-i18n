<?php

declare(strict_types=1);

namespace Stromcom\I18n\Console;

use Stromcom\I18n\Config\I18nConfig;
use Stromcom\I18n\Runtime\BundleLoaderInterface;
use Stromcom\I18n\Scan\ScannerPipeline;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'i18n:status', description: 'Local coverage report: keys in source vs. keys in bundle, per locale.')]
final class StatusCommand extends Command
{
    public function __construct(
        private readonly I18nConfig $config,
        private readonly ScannerPipeline $pipeline,
        private readonly BundleLoaderInterface $loader,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $scannedKeys = $this->pipeline->scan();
        $sourceCount = count($scannedKeys);
        $sourceNames = array_map(static fn ($k) => $k->name, $scannedKeys);

        $io->title('i18n status');
        $io->writeln(sprintf('Source: <info>%d</info> unique key(s) found in code.', $sourceCount));

        $rows = [];
        foreach ($this->config->targetLocales as $locale) {
            $bundle = $this->loader->load($locale);
            $bundleNames = array_keys($bundle);
            $missing = array_diff($sourceNames, $bundleNames);
            $extra   = array_diff($bundleNames, $sourceNames);
            $covered = $sourceCount - count($missing);
            $pct = $sourceCount === 0 ? 0.0 : ($covered / $sourceCount) * 100;
            $rows[] = [
                $locale,
                count($bundle),
                $covered,
                count($missing),
                count($extra),
                sprintf('%.1f %%', $pct),
            ];
        }
        $io->table(['locale', 'bundle keys', 'covered', 'missing', 'orphan', 'coverage'], $rows);

        return Command::SUCCESS;
    }
}
