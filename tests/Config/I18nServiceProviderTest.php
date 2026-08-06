<?php

declare(strict_types=1);

namespace Stromcom\I18n\Tests\Config;

use DI\ContainerBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Stromcom\I18n\Build\BundleFetcher;
use Stromcom\I18n\Build\EtagStore;
use Stromcom\I18n\Build\KeySync;
use Stromcom\I18n\Build\PhpCacheWriter;
use Stromcom\I18n\Build\TranslatorClient;
use Stromcom\I18n\Config\I18nConfig;
use Stromcom\I18n\Config\I18nServiceProvider;
use Stromcom\I18n\Console\FetchCommand;
use Stromcom\I18n\Console\ScanCommand;
use Stromcom\I18n\Console\StatusCommand;
use Stromcom\I18n\Console\SyncCommand;
use Stromcom\I18n\Runtime\BundleLoader;
use Stromcom\I18n\Runtime\BundleLoaderInterface;
use Stromcom\I18n\Runtime\LocaleContext;
use Stromcom\I18n\Runtime\LocaleMiddleware;
use Stromcom\I18n\Runtime\LocaleResolver;
use Stromcom\I18n\Runtime\Translator;
use Stromcom\I18n\Runtime\TranslatorInterface;
use Stromcom\I18n\Runtime\TwigI18nExtension;
use Stromcom\I18n\Runtime\XsltRenderer;
use Stromcom\I18n\Scan\PhpScanner;
use Stromcom\I18n\Scan\ScannerPipeline;
use Stromcom\I18n\Scan\TwigScanner;
use Stromcom\I18n\Scan\XsltScanner;
use Symfony\Component\Console\Command\Command;

/**
 * Boots a real PHP-DI container from the shipped definitions. Autowiring only fails at
 * resolution time, so this is what catches a constructor change that the definitions no
 * longer satisfy.
 */
#[CoversClass(I18nServiceProvider::class)]
final class I18nServiceProviderTest extends TestCase
{
    private function container(): ContainerInterface
    {
        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);
        $builder->addDefinitions(I18nServiceProvider::definitions());
        $builder->addDefinitions([
            LoggerInterface::class => static fn (): LoggerInterface => new NullLogger(),
            I18nConfig::class      => static fn (): I18nConfig => new I18nConfig(
                projectId: 'proj',
                token: 'tok',
                baseUrl: 'https://translator.test',
                sourceLocale: 'en',
                targetLocales: ['cs', 'en'],
                fallbackLocale: 'en',
                bundlesDir: sys_get_temp_dir() . '/i18n-di-test',
                scanPaths: [sys_get_temp_dir()],
                etagStorePath: sys_get_temp_dir() . '/i18n-di-test/.etags.json',
            ),
        ]);

        return $builder->build();
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function serviceProvider(): iterable
    {
        yield 'BundleLoaderInterface' => [BundleLoaderInterface::class];
        yield 'LocaleContext'         => [LocaleContext::class];
        yield 'LocaleResolver'        => [LocaleResolver::class];
        yield 'LocaleMiddleware'      => [LocaleMiddleware::class];
        yield 'TranslatorInterface'   => [TranslatorInterface::class];
        yield 'Translator'            => [Translator::class];
        yield 'TwigI18nExtension'     => [TwigI18nExtension::class];
        yield 'XsltRenderer'          => [XsltRenderer::class];
        yield 'PhpScanner'            => [PhpScanner::class];
        yield 'TwigScanner'           => [TwigScanner::class];
        yield 'XsltScanner'           => [XsltScanner::class];
        yield 'ScannerPipeline'       => [ScannerPipeline::class];
        yield 'TranslatorClient'      => [TranslatorClient::class];
        yield 'EtagStore'             => [EtagStore::class];
        yield 'BundleFetcher'         => [BundleFetcher::class];
        yield 'PhpCacheWriter'        => [PhpCacheWriter::class];
        yield 'KeySync'               => [KeySync::class];
        yield 'ScanCommand'           => [ScanCommand::class];
        yield 'SyncCommand'           => [SyncCommand::class];
        yield 'FetchCommand'          => [FetchCommand::class];
        yield 'StatusCommand'         => [StatusCommand::class];
    }

    /**
     * @param class-string $id
     */
    #[DataProvider('serviceProvider')]
    public function testEveryDefinedServiceResolves(string $id): void
    {
        self::assertInstanceOf($id, $this->container()->get($id));
    }

    public function testInterfacesResolveToTheConcreteImplementations(): void
    {
        $container = $this->container();

        self::assertInstanceOf(BundleLoader::class, $container->get(BundleLoaderInterface::class));
        self::assertInstanceOf(Translator::class, $container->get(TranslatorInterface::class));
    }

    public function testServicesAreSharedWithinAContainer(): void
    {
        $container = $this->container();

        self::assertSame($container->get(LocaleContext::class), $container->get(LocaleContext::class));
    }

    public function testEtagStoreFactoryUsesTheConfiguredPath(): void
    {
        $store = $this->container()->get(EtagStore::class);

        self::assertInstanceOf(EtagStore::class, $store);
        self::assertNull($store->get('cs'), 'a fresh store starts empty at the configured path');
    }

    public function testScannerPipelineIsWiredWithAllThreeScanners(): void
    {
        // The factory passes php + twig + xslt; a scan over a directory holding one file
        // of each type must reach all of them.
        $pipeline = $this->container()->get(ScannerPipeline::class);

        self::assertInstanceOf(ScannerPipeline::class, $pipeline);
    }

    public function testConsoleCommandsListMatchesTheDefinedCommands(): void
    {
        self::assertSame(
            [ScanCommand::class, SyncCommand::class, FetchCommand::class, StatusCommand::class],
            I18nServiceProvider::consoleCommands(),
        );
    }

    public function testEveryListedConsoleCommandResolvesAsACommand(): void
    {
        $container = $this->container();

        foreach (I18nServiceProvider::consoleCommands() as $class) {
            self::assertInstanceOf(Command::class, $container->get($class));
        }
    }

    public function testDefinitionsCoverEveryConsoleCommand(): void
    {
        $definitions = I18nServiceProvider::definitions();

        foreach (I18nServiceProvider::consoleCommands() as $class) {
            self::assertArrayHasKey($class, $definitions);
        }
    }
}
