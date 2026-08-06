<?php

declare(strict_types=1);

namespace Stromcom\I18n\Tests\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stromcom\I18n\Build\FetchResult;
use Stromcom\I18n\Build\SyncResult;
use Stromcom\I18n\Config\I18nConfig;
use Stromcom\I18n\Runtime\MissingKeyPolicy;

#[CoversClass(I18nConfig::class)]
#[CoversClass(FetchResult::class)]
#[CoversClass(SyncResult::class)]
final class I18nConfigTest extends TestCase
{
    /**
     * @param list<string> $targetLocales
     */
    private function config(
        array $targetLocales = ['cs', 'en'],
        string $fallbackLocale = 'en',
        string $bundlesDir = '/srv/build/locales',
    ): I18nConfig {
        return new I18nConfig(
            projectId: 'proj',
            token: 'tok',
            baseUrl: 'https://translator.test',
            sourceLocale: 'en',
            targetLocales: $targetLocales,
            fallbackLocale: $fallbackLocale,
            bundlesDir: $bundlesDir,
            scanPaths: [],
        );
    }

    public function testRejectsAFallbackLocaleOutsideTargetLocales(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('fallbackLocale "fr" must be in targetLocales [cs, en]');

        $this->config(fallbackLocale: 'fr');
    }

    public function testAcceptsAFallbackLocaleInsideTargetLocales(): void
    {
        self::assertSame('cs', $this->config(fallbackLocale: 'cs')->fallbackLocale);
    }

    public function testBundlePath(): void
    {
        self::assertSame(
            '/srv/build/locales' . DIRECTORY_SEPARATOR . 'cs.json',
            $this->config()->bundlePath('cs'),
        );
    }

    public function testBundlePhpCachePath(): void
    {
        self::assertSame(
            '/srv/build/locales' . DIRECTORY_SEPARATOR . 'cs.cache.php',
            $this->config()->bundlePhpCachePath('cs'),
        );
    }

    public function testIsLocaleSupported(): void
    {
        $config = $this->config(['cs', 'en']);

        self::assertTrue($config->isLocaleSupported('cs'));
        self::assertFalse($config->isLocaleSupported('de'));
        self::assertFalse($config->isLocaleSupported('CS'), 'matching is case-sensitive');
    }

    public function testDefaults(): void
    {
        $config = $this->config();

        self::assertSame(['/vendor/', '/node_modules/', '/build/', '/var/', '/.git/'], $config->scanExcludes);
        self::assertSame('locale', $config->cookieName);
        self::assertSame(31_536_000, $config->cookieTtl);
        self::assertSame(MissingKeyPolicy::LogAndFallback, $config->missingKeyPolicy);
        self::assertFalse($config->isDevelop);
        self::assertSame('.i18n-etags.json', $config->etagStorePath);
    }

    public function testFetchResultIsOkForSuccessAndNotModified(): void
    {
        self::assertTrue((new FetchResult('cs', 200, true, []))->isOk());
        self::assertTrue((new FetchResult('cs', 304, false, []))->isOk());
        self::assertFalse((new FetchResult('cs', 422, false, ['a']))->isOk());
        self::assertFalse((new FetchResult('cs', 500, false, []))->isOk());
        self::assertFalse((new FetchResult('cs', 201, true, []))->isOk());
    }

    public function testSyncResultCarriesItsCounts(): void
    {
        $result = new SyncResult(added: 3, updated: 2, stale: ['old.key'], totalInSync: 10, sent: 5);

        self::assertSame(3, $result->added);
        self::assertSame(2, $result->updated);
        self::assertSame(['old.key'], $result->stale);
        self::assertSame(10, $result->totalInSync);
        self::assertSame(5, $result->sent);
    }
}
