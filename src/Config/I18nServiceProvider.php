<?php

declare(strict_types=1);

namespace Stromcom\I18n\Config;

use DI\Definition\Helper\AutowireDefinitionHelper;
use DI\Definition\Helper\FactoryDefinitionHelper;
use Psr\Log\LoggerInterface;
use Stromcom\I18n\Build\BundleFetcher;
use Stromcom\I18n\Build\EtagStore;
use Stromcom\I18n\Build\KeySync;
use Stromcom\I18n\Build\PhpCacheWriter;
use Stromcom\I18n\Build\TranslatorClient;
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
use Stromcom\I18n\Scan\ScannerInterface;
use Stromcom\I18n\Scan\ScannerPipeline;
use Stromcom\I18n\Scan\TwigScanner;
use Stromcom\I18n\Scan\XsltScanner;

/**
 * PHP-DI definitions. The consumer calls:
 *
 *   return array_merge(I18nServiceProvider::definitions(), [
 *       I18nConfig::class => static fn () => new I18nConfig(...),
 *       // ... custom definitions ...
 *   ]);
 *
 * The consumer **must** provide their own `I18nConfig::class` definition —
 * the service provider does not guess projectId / token from env (that is the
 * consumer's responsibility).
 *
 * Compatible with PSR-11 containers that support the PHP-DI `autowire()` helper.
 * For other containers use `bareDefinitions()` (a class → factory closure map)
 * and convert them to your own format.
 */
final class I18nServiceProvider
{
    /**
     * @return array<string, AutowireDefinitionHelper|FactoryDefinitionHelper>
     */
    public static function definitions(): array
    {
        return [
            // Runtime
            BundleLoaderInterface::class    => \DI\autowire(BundleLoader::class),
            LocaleContext::class            => \DI\autowire(LocaleContext::class),
            LocaleResolver::class           => \DI\autowire(LocaleResolver::class),
            LocaleMiddleware::class         => \DI\autowire(LocaleMiddleware::class),
            TranslatorInterface::class      => \DI\autowire(Translator::class),
            Translator::class               => \DI\autowire(Translator::class),
            TwigI18nExtension::class        => \DI\autowire(TwigI18nExtension::class),
            XsltRenderer::class             => \DI\autowire(XsltRenderer::class),

            // Scan
            PhpScanner::class               => \DI\autowire(PhpScanner::class),
            TwigScanner::class              => \DI\autowire(TwigScanner::class),
            XsltScanner::class              => \DI\autowire(XsltScanner::class),
            ScannerPipeline::class          => \DI\factory(
                static fn (
                    I18nConfig $config,
                    LoggerInterface $logger,
                    PhpScanner $php,
                    TwigScanner $twig,
                    XsltScanner $xslt,
                ): ScannerPipeline => new ScannerPipeline($config, $logger, [$php, $twig, $xslt]),
            ),
            ScannerInterface::class         => \DI\autowire(PhpScanner::class), // default — explicit injection preferred

            // Build
            TranslatorClient::class         => \DI\autowire(TranslatorClient::class),
            EtagStore::class                => \DI\factory(
                static fn (I18nConfig $config): EtagStore => new EtagStore($config->etagStorePath),
            ),
            BundleFetcher::class            => \DI\autowire(BundleFetcher::class),
            PhpCacheWriter::class           => \DI\autowire(PhpCacheWriter::class),
            KeySync::class                  => \DI\autowire(KeySync::class),

            // Console
            ScanCommand::class              => \DI\autowire(ScanCommand::class),
            SyncCommand::class              => \DI\autowire(SyncCommand::class),
            FetchCommand::class             => \DI\autowire(FetchCommand::class),
            StatusCommand::class            => \DI\autowire(StatusCommand::class),
        ];
    }

    /**
     * List of Symfony Console command classes to register — the consumer drops
     * them into their `Application`. Centralized so the consumer doesn't have to
     * track the specific list when new commands are added to the package.
     *
     * @return list<class-string>
     */
    public static function consoleCommands(): array
    {
        return [
            ScanCommand::class,
            SyncCommand::class,
            FetchCommand::class,
            StatusCommand::class,
        ];
    }
}
