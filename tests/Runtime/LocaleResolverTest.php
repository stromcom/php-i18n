<?php

declare(strict_types=1);

namespace Stromcom\I18n\Tests\Runtime;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Stromcom\I18n\Config\I18nConfig;
use Stromcom\I18n\Runtime\LocaleResolver;

#[CoversClass(LocaleResolver::class)]
final class LocaleResolverTest extends TestCase
{
    private function config(): I18nConfig
    {
        return new I18nConfig(
            projectId: 't',
            token: '',
            baseUrl: 'https://e.test',
            sourceLocale: 'en',
            targetLocales: ['cs', 'en', 'de', 'sk'],
            fallbackLocale: 'en',
            bundlesDir: '/tmp',
            scanPaths: [],
        );
    }

    /**
     * @param array<string, string> $query
     * @param array<string, string> $cookies
     */
    private function makeRequest(array $query = [], array $cookies = [], string $acceptLanguage = ''): ServerRequestInterface
    {
        $request = self::createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn($query);
        $request->method('getCookieParams')->willReturn($cookies);
        $request->method('getHeaderLine')->willReturnCallback(
            static fn (string $name): string => strtolower($name) === 'accept-language' ? $acceptLanguage : '',
        );
        $uri = self::createStub(UriInterface::class);
        $uri->method('getScheme')->willReturn('https');
        $request->method('getUri')->willReturn($uri);
        return $request;
    }

    public function testQueryParamWins(): void
    {
        $r = new LocaleResolver($this->config());
        $result = $r->resolve($this->makeRequest(query: ['locale' => 'de'], cookies: ['locale' => 'cs'], acceptLanguage: 'sk'));
        self::assertSame(['locale' => 'de', 'persist' => true], $result);
    }

    public function testQueryParamMustBeSupported(): void
    {
        $r = new LocaleResolver($this->config());
        $result = $r->resolve($this->makeRequest(query: ['locale' => 'xx'], cookies: ['locale' => 'cs']));
        self::assertSame(['locale' => 'cs', 'persist' => false], $result);
    }

    public function testCookieUsedWhenNoQuery(): void
    {
        $r = new LocaleResolver($this->config());
        $result = $r->resolve($this->makeRequest(cookies: ['locale' => 'sk'], acceptLanguage: 'de'));
        self::assertSame(['locale' => 'sk', 'persist' => false], $result);
    }

    public function testAcceptLanguageQValues(): void
    {
        $r = new LocaleResolver($this->config());
        $result = $r->resolve($this->makeRequest(acceptLanguage: 'fr;q=0.9, de;q=0.5, cs;q=0.7'));
        self::assertSame(['locale' => 'cs', 'persist' => false], $result);
    }

    public function testAcceptLanguagePrimarySubtagFallback(): void
    {
        $r = new LocaleResolver($this->config());
        $result = $r->resolve($this->makeRequest(acceptLanguage: 'cs-CZ,en;q=0.5'));
        self::assertSame(['locale' => 'cs', 'persist' => false], $result);
    }

    public function testFallbackWhenNothingMatches(): void
    {
        $r = new LocaleResolver($this->config());
        $result = $r->resolve($this->makeRequest(acceptLanguage: 'fr-FR,it'));
        self::assertSame(['locale' => 'en', 'persist' => false], $result);
    }
}
