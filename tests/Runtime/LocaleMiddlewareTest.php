<?php

declare(strict_types=1);

namespace Stromcom\I18n\Tests\Runtime;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Stromcom\I18n\Config\I18nConfig;
use Stromcom\I18n\Runtime\LocaleContext;
use Stromcom\I18n\Runtime\LocaleMiddleware;
use Stromcom\I18n\Runtime\LocaleResolver;

#[CoversClass(LocaleMiddleware::class)]
#[CoversClass(LocaleContext::class)]
final class LocaleMiddlewareTest extends TestCase
{
    private function config(string $cookieName = 'locale', int $cookieTtl = 31_536_000): I18nConfig
    {
        return new I18nConfig(
            projectId: 't',
            token: '',
            baseUrl: 'https://e.test',
            sourceLocale: 'en',
            targetLocales: ['cs', 'en', 'de'],
            fallbackLocale: 'en',
            bundlesDir: '/tmp',
            scanPaths: [],
            cookieName: $cookieName,
            cookieTtl: $cookieTtl,
        );
    }

    /**
     * @param array<string, string> $query
     * @param array<string, string> $cookies
     */
    private function request(
        array $query = [],
        array $cookies = [],
        string $acceptLanguage = '',
        string $scheme = 'https',
    ): ServerRequestInterface {
        $request = self::createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn($query);
        $request->method('getCookieParams')->willReturn($cookies);
        $request->method('getHeaderLine')->willReturnCallback(
            static fn (string $name): string => strtolower($name) === 'accept-language' ? $acceptLanguage : '',
        );
        $uri = self::createStub(UriInterface::class);
        $uri->method('getScheme')->willReturn($scheme);
        $request->method('getUri')->willReturn($uri);

        return $request;
    }

    private function handlerReturning(ResponseInterface $response): RequestHandlerInterface
    {
        $handler = self::createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        return $handler;
    }

    public function testResolvedLocaleIsStoredInTheContextBeforeTheHandlerRuns(): void
    {
        $config = $this->config();
        $context = new LocaleContext($config);
        $response = self::createStub(ResponseInterface::class);

        $seen = null;
        $handler = self::createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturnCallback(
            static function () use ($context, $response, &$seen): ResponseInterface {
                $seen = $context->get();
                return $response;
            },
        );

        $middleware = new LocaleMiddleware($config, new LocaleResolver($config), $context);
        $middleware->process($this->request(cookies: ['locale' => 'de']), $handler);

        self::assertSame('de', $seen);
    }

    public function testReturnsTheHandlerResponseUnchangedWhenNoPersistIsNeeded(): void
    {
        $config = $this->config();
        $response = self::createMock(ResponseInterface::class);
        $response->expects(self::never())->method('withAddedHeader');

        $middleware = new LocaleMiddleware($config, new LocaleResolver($config), new LocaleContext($config));
        $result = $middleware->process($this->request(cookies: ['locale' => 'cs']), $this->handlerReturning($response));

        self::assertSame($response, $result);
    }

    public function testQueryParamLocaleAppendsAPersistentCookie(): void
    {
        $config = $this->config();
        $response = self::createMock(ResponseInterface::class);
        $response->expects(self::once())
            ->method('withAddedHeader')
            ->with('Set-Cookie', 'locale=cs; Path=/; Max-Age=31536000; SameSite=Lax; Secure')
            ->willReturnSelf();

        $middleware = new LocaleMiddleware($config, new LocaleResolver($config), new LocaleContext($config));
        $middleware->process($this->request(query: ['locale' => 'cs']), $this->handlerReturning($response));
    }

    public function testCookieOmitsSecureOverPlainHttp(): void
    {
        $config = $this->config();
        $response = self::createMock(ResponseInterface::class);
        $response->expects(self::once())
            ->method('withAddedHeader')
            ->with('Set-Cookie', 'locale=cs; Path=/; Max-Age=31536000; SameSite=Lax')
            ->willReturnSelf();

        $middleware = new LocaleMiddleware($config, new LocaleResolver($config), new LocaleContext($config));
        $middleware->process(
            $this->request(query: ['locale' => 'cs'], scheme: 'http'),
            $this->handlerReturning($response),
        );
    }

    public function testSchemeComparisonIsCaseInsensitive(): void
    {
        $config = $this->config();
        $response = self::createMock(ResponseInterface::class);
        $response->expects(self::once())
            ->method('withAddedHeader')
            ->with('Set-Cookie', self::stringContains('; Secure'))
            ->willReturnSelf();

        $middleware = new LocaleMiddleware($config, new LocaleResolver($config), new LocaleContext($config));
        $middleware->process(
            $this->request(query: ['locale' => 'cs'], scheme: 'HTTPS'),
            $this->handlerReturning($response),
        );
    }

    public function testCookieNameAndTtlComeFromTheConfig(): void
    {
        $config = $this->config(cookieName: 'app_lang', cookieTtl: 600);
        $response = self::createMock(ResponseInterface::class);
        $response->expects(self::once())
            ->method('withAddedHeader')
            ->with('Set-Cookie', 'app_lang=de; Path=/; Max-Age=600; SameSite=Lax; Secure')
            ->willReturnSelf();

        $middleware = new LocaleMiddleware($config, new LocaleResolver($config), new LocaleContext($config));
        $middleware->process($this->request(query: ['locale' => 'de']), $this->handlerReturning($response));
    }

    public function testUnsupportedQueryLocaleFallsBackWithoutSettingACookie(): void
    {
        $config = $this->config();
        $context = new LocaleContext($config);
        $response = self::createMock(ResponseInterface::class);
        $response->expects(self::never())->method('withAddedHeader');

        $middleware = new LocaleMiddleware($config, new LocaleResolver($config), $context);
        $middleware->process($this->request(query: ['locale' => 'fr']), $this->handlerReturning($response));

        self::assertSame('en', $context->get());
    }

    public function testTheAppendedCookieResponseIsWhatGetsReturned(): void
    {
        $config = $this->config();
        $withCookie = self::createStub(ResponseInterface::class);
        $response = self::createStub(ResponseInterface::class);
        $response->method('withAddedHeader')->willReturn($withCookie);

        $middleware = new LocaleMiddleware($config, new LocaleResolver($config), new LocaleContext($config));
        $result = $middleware->process($this->request(query: ['locale' => 'cs']), $this->handlerReturning($response));

        self::assertSame($withCookie, $result);
    }

    // ------------------------------------------------------------ LocaleContext

    public function testContextFallsBackToTheConfiguredFallbackBeforeAnyRequest(): void
    {
        self::assertSame('en', (new LocaleContext($this->config()))->get());
    }

    public function testContextResetRestoresTheFallback(): void
    {
        $context = new LocaleContext($this->config());
        $context->set('de');
        self::assertSame('de', $context->get());

        $context->reset();
        self::assertSame('en', $context->get());
    }

    public function testContextSetOverwritesAPreviousValue(): void
    {
        // A warm container keeps the singleton between requests, so the second request
        // must not inherit the first one's locale.
        $context = new LocaleContext($this->config());
        $context->set('de');
        $context->set('cs');

        self::assertSame('cs', $context->get());
    }
}
