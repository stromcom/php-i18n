<?php

declare(strict_types=1);

namespace Stromcom\I18n\Tests\Runtime;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Stromcom\I18n\Config\I18nConfig;
use Stromcom\I18n\Runtime\BundleLoader;
use Stromcom\I18n\Tests\Support\TmpDir;

#[CoversClass(BundleLoader::class)]
final class BundleLoaderTest extends TestCase
{
    private TmpDir $tmp;

    protected function setUp(): void
    {
        $this->tmp = new TmpDir('stromcom-i18n-bundle');
    }

    protected function tearDown(): void
    {
        $this->tmp->cleanup();
    }

    private function config(): I18nConfig
    {
        return new I18nConfig(
            projectId: 't',
            token: '',
            baseUrl: 'https://e.test',
            sourceLocale: 'en',
            targetLocales: ['en', 'cs'],
            fallbackLocale: 'en',
            bundlesDir: $this->tmp->path(),
            scanPaths: [],
        );
    }

    public function testLoadsExistingBundle(): void
    {
        $this->tmp->writeJson('cs.json', ['a' => 'A', 'b' => 'B']);
        $loader = new BundleLoader($this->config(), new NullLogger());

        self::assertSame(['a' => 'A', 'b' => 'B'], $loader->load('cs'));
    }

    public function testUnwrapsTranslationsObjectFromTranslatorApi(): void
    {
        // Translator returns a wrapper {version, locale, generated_at, translations: {...}}
        $this->tmp->writeJson('cs.json', [
            'version' => 'published',
            'revision' => 1,
            'locale' => 'cs',
            'generated_at' => '2026-05-28T21:28:58+00:00',
            'translations' => [
                'login.title' => 'Přihlášení',
                'login.form.email_label' => 'E-mail',
            ],
        ]);
        $loader = new BundleLoader($this->config(), new NullLogger());

        $bundle = $loader->load('cs');
        self::assertSame([
            'login.title' => 'Přihlášení',
            'login.form.email_label' => 'E-mail',
        ], $bundle);
        // Metadata MUST NOT end up in the bundle as "keys"
        self::assertArrayNotHasKey('version', $bundle);
        self::assertArrayNotHasKey('locale', $bundle);
    }

    public function testReturnsEmptyArrayWhenFileMissing(): void
    {
        $loader = new BundleLoader($this->config(), new NullLogger());
        self::assertSame([], $loader->load('cs'));
    }

    public function testReturnsEmptyArrayOnInvalidJson(): void
    {
        $this->tmp->write('cs.json', 'not json');
        $loader = new BundleLoader($this->config(), new NullLogger());
        self::assertSame([], $loader->load('cs'));
    }

    public function testCachesAcrossCalls(): void
    {
        $this->tmp->writeJson('cs.json', ['a' => 'A']);
        $loader = new BundleLoader($this->config(), new NullLogger());

        $first = $loader->load('cs');
        @unlink($this->tmp->path() . '/cs.json');
        $second = $loader->load('cs');

        self::assertSame($first, $second);
    }

    public function testFiltersOutNonStringEntries(): void
    {
        $this->tmp->writeJson('cs.json', ['ok' => 'value', 'bad' => ['nested'], 0 => 'numeric key dropped']);
        $loader = new BundleLoader($this->config(), new NullLogger());
        self::assertSame(['ok' => 'value'], $loader->load('cs'));
    }

    public function testPrefersPhpCacheWhenMtimeMatchesJson(): void
    {
        $this->tmp->writeJson('cs.json', ['from' => 'json']);
        $this->writePhpCache('cs.cache.php', ['from' => 'php-cache'], syncWith: 'cs.json');

        $loader = new BundleLoader($this->config(), new NullLogger());

        self::assertSame(['from' => 'php-cache'], $loader->load('cs'));
    }

    public function testFallsBackToJsonWhenPhpCacheStale(): void
    {
        // PHP cache is older than JSON → JSON was manually edited after the fetch
        $this->writePhpCache('cs.cache.php', ['from' => 'stale-php']);
        $jsonPath = $this->tmp->writeJson('cs.json', ['from' => 'fresh-json']);
        // Force the PHP cache into the past
        $jsonMtime = filemtime($jsonPath);
        if ($jsonMtime === false) {
            self::fail('Cannot read JSON mtime');
        }
        touch($this->tmp->path() . '/cs.cache.php', $jsonMtime - 60);

        $loader = new BundleLoader($this->config(), new NullLogger());

        self::assertSame(['from' => 'fresh-json'], $loader->load('cs'));
    }

    public function testUsesPhpCacheWhenJsonMissing(): void
    {
        // Edge case: the deploy artifact contains only .cache.php (CI might remove the JSON)
        $this->writePhpCache('cs.cache.php', ['only' => 'php']);

        $loader = new BundleLoader($this->config(), new NullLogger());

        self::assertSame(['only' => 'php'], $loader->load('cs'));
    }

    public function testIgnoresPhpCacheReturningNonArray(): void
    {
        $this->tmp->writeJson('cs.json', ['from' => 'json']);
        $this->tmp->write('cs.cache.php', "<?php return 42;\n");

        $loader = new BundleLoader($this->config(), new NullLogger());

        self::assertSame(['from' => 'json'], $loader->load('cs'));
    }

    /**
     * @param array<string, string> $bundle
     */
    private function writePhpCache(string $name, array $bundle, ?string $syncWith = null): void
    {
        $code = "<?php return " . var_export($bundle, true) . ";\n";
        $path = $this->tmp->write($name, $code);
        if ($syncWith !== null) {
            $mtime = @filemtime($this->tmp->path() . '/' . $syncWith);
            if ($mtime !== false) {
                touch($path, $mtime);
            }
        }
    }
}
