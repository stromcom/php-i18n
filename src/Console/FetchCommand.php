<?php

declare(strict_types=1);

namespace Stromcom\I18n\Console;

use Stromcom\I18n\Build\BundleFetcher;
use Stromcom\I18n\Build\TranslatorClientException;
use Stromcom\I18n\Config\I18nConfig;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'i18n:fetch', description: 'Download translation bundles to disk (default: published; --draft for in-progress).')]
final class FetchCommand extends Command
{
    public function __construct(
        private readonly I18nConfig $config,
        private readonly BundleFetcher $fetcher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('draft', null, InputOption::VALUE_NONE, 'Fetch draft bundles instead of published.');
        $this->addOption('locale', null, InputOption::VALUE_REQUIRED, 'Fetch only this locale (default: all configured locales).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $version = $input->getOption('draft') === true ? 'draft' : 'published';

        $localeOpt = $input->getOption('locale');
        if ($localeOpt !== null) {
            if (!is_string($localeOpt) || !$this->config->isLocaleSupported($localeOpt)) {
                $shown = is_string($localeOpt) ? $localeOpt : get_debug_type($localeOpt);
                $io->error(sprintf('Locale "%s" is not in configured targetLocales.', $shown));
                return Command::FAILURE;
            }
            $locales = [$localeOpt];
        } else {
            $locales = $this->config->targetLocales;
        }

        $io->writeln(sprintf('<info>Fetching %s bundles for: %s</info>', $version, implode(', ', $locales)));

        $failed = [];
        $hasMissing = false;
        foreach ($locales as $locale) {
            try {
                $result = $this->fetcher->fetch($locale, $version);
            } catch (TranslatorClientException $e) {
                $io->error(sprintf('[%s] %s', $locale, $e->getMessage()));
                $failed[] = $locale;
                continue;
            }
            if ($result->status === 422) {
                $io->warning(sprintf('[%s] 422 — %d missing key(s) on translator', $locale, count($result->missingKeys)));
                if ($result->missingKeys !== []) {
                    $io->listing(array_slice($result->missingKeys, 0, 20));
                    if (count($result->missingKeys) > 20) {
                        $io->writeln(sprintf('<comment>… and %d more.</comment>', count($result->missingKeys) - 20));
                    }
                }
                $hasMissing = true;
                continue;
            }
            if (!$result->isOk()) {
                $failed[] = $locale;
                continue;
            }
            $io->writeln(sprintf('[%s] HTTP %d — %s', $locale, $result->status, $result->written ? 'written' : 'cached (304)'));
        }

        if ($failed !== []) {
            $io->error('Failed for: ' . implode(', ', $failed));
            return Command::FAILURE;
        }
        if ($hasMissing) {
            return Command::FAILURE;
        }
        $io->success('Bundles up to date.');
        return Command::SUCCESS;
    }
}
