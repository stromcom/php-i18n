<?php

declare(strict_types=1);

namespace Stromcom\I18n\Tests\Console;

use PHPUnit\Framework\TestCase;
use Stromcom\I18n\Config\I18nConfig;
use Stromcom\I18n\Runtime\MissingKeyPolicy;
use Stromcom\I18n\Scan\ScannedKey;
use Stromcom\I18n\Scan\ScannerPipeline;
use Stromcom\I18n\Tests\Support\CollectingLogger;
use Stromcom\I18n\Tests\Support\StaticScanner;
use Stromcom\I18n\Tests\Support\TmpDir;

/**
 * Shared plumbing for the console commands: a temp workspace, a config pointing at it,
 * and a real `ScannerPipeline` primed to yield a fixed set of keys.
 */
abstract class ConsoleCommandTestCase extends TestCase
{
    protected TmpDir $tmp;
    protected CollectingLogger $logger;

    protected function setUp(): void
    {
        $this->tmp = new TmpDir('stromcom-i18n-console');
        $this->logger = new CollectingLogger();
    }

    protected function tearDown(): void
    {
        $this->tmp->cleanup();
    }

    /**
     * @param list<string> $targetLocales
     */
    protected function config(
        array $targetLocales = ['cs', 'en'],
        ?string $bundlesDir = null,
    ): I18nConfig {
        return new I18nConfig(
            projectId: 'proj',
            token: 'tok',
            baseUrl: 'https://translator.test',
            sourceLocale: 'en',
            targetLocales: $targetLocales,
            fallbackLocale: 'en',
            bundlesDir: $bundlesDir ?? $this->tmp->path() . '/locales',
            scanPaths: [$this->tmp->path() . '/src'],
            missingKeyPolicy: MissingKeyPolicy::Silent,
        );
    }

    /**
     * @param list<ScannedKey> $keys
     */
    protected function pipeline(array $keys, ?I18nConfig $config = null): ScannerPipeline
    {
        $this->tmp->write('src/app.php', '<?php');

        return new ScannerPipeline(
            $config ?? $this->config(),
            $this->logger,
            [new StaticScanner($keys)],
            rootDir: $this->tmp->path(),
        );
    }

    /**
     * @return list<ScannedKey>
     */
    protected function sampleKeys(): array
    {
        return [
            new ScannedKey('page.title', 'Welcome', 'Homepage heading', ['src/a.php:1']),
            new ScannedKey('page.body', 'Body text', null, ['src/a.php:2', 'src/b.php:9']),
        ];
    }
}
