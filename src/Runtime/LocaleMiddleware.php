<?php

declare(strict_types=1);

namespace Stromcom\I18n\Runtime;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Stromcom\I18n\Config\I18nConfig;

/**
 * Resolves the locale and stores it in `LocaleContext`. If the user arrived with
 * `?locale=xx`, it sets a cookie so the preference persists between requests. It must
 * be in the Slim pipeline **after the session middleware** (cookies are added to the
 * response) and **before** the route handler (handlers already read the finished
 * context).
 */
final readonly class LocaleMiddleware implements MiddlewareInterface
{
    public function __construct(
        private I18nConfig $config,
        private LocaleResolver $resolver,
        private LocaleContext $context,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $resolved = $this->resolver->resolve($request);
        $this->context->set($resolved['locale']);

        $response = $handler->handle($request);

        if ($resolved['persist']) {
            $response = $this->appendCookie($response, $request, $resolved['locale']);
        }

        return $response;
    }

    private function appendCookie(ResponseInterface $response, ServerRequestInterface $request, string $locale): ResponseInterface
    {
        $isSecure = strtolower($request->getUri()->getScheme()) === 'https';
        $cookie = sprintf(
            '%s=%s; Path=/; Max-Age=%d; SameSite=Lax%s',
            $this->config->cookieName,
            rawurlencode($locale),
            $this->config->cookieTtl,
            $isSecure ? '; Secure' : '',
        );
        // Append, not replace — the session middleware may have already set its own cookie.
        return $response->withAddedHeader('Set-Cookie', $cookie);
    }
}
