<?php

declare(strict_types=1);

namespace Stromcom\I18n\Runtime;

use Psr\Http\Message\ServerRequestInterface;
use Stromcom\I18n\Config\I18nConfig;

/**
 * Resolves the locale from the request by priority:
 *   1. `?locale=xx` query param (∈ targetLocales) — wins and triggers a cookie set
 *   2. cookie `<config->cookieName>` (∈ targetLocales)
 *   3. `Accept-Language` header — best match with Q-values
 *   4. `config->fallbackLocale`
 */
final readonly class LocaleResolver
{
    public function __construct(private I18nConfig $config) {}

    /**
     * @return array{locale: string, persist: bool}  `persist=true` means the cookie should be set
     */
    public function resolve(ServerRequestInterface $request): array
    {
        $query = $request->getQueryParams();
        $queryLocale = isset($query['locale']) && is_string($query['locale']) ? $query['locale'] : null;
        if ($queryLocale !== null && $this->config->isLocaleSupported($queryLocale)) {
            return ['locale' => $queryLocale, 'persist' => true];
        }

        $cookies = $request->getCookieParams();
        $cookieLocale = isset($cookies[$this->config->cookieName]) && is_string($cookies[$this->config->cookieName])
            ? $cookies[$this->config->cookieName]
            : null;
        if ($cookieLocale !== null && $this->config->isLocaleSupported($cookieLocale)) {
            return ['locale' => $cookieLocale, 'persist' => false];
        }

        $headerLocale = $this->fromAcceptLanguage($request->getHeaderLine('Accept-Language'));
        if ($headerLocale !== null) {
            return ['locale' => $headerLocale, 'persist' => false];
        }

        return ['locale' => $this->config->fallbackLocale, 'persist' => false];
    }

    /**
     * Parses `cs-CZ,cs;q=0.9,en;q=0.8` and returns the best match from `targetLocales`.
     * Matches both the full tag (`cs-CZ` → `cs-CZ` if it is in targets) and the primary
     * subtag (`cs-CZ` → `cs` if `cs` is in targets).
     */
    private function fromAcceptLanguage(string $header): ?string
    {
        if ($header === '') {
            return null;
        }

        $candidates = [];
        foreach (explode(',', $header) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $bits = explode(';', $part);
            $tag = strtolower(trim($bits[0]));
            $q = 1.0;
            for ($i = 1; $i < count($bits); $i++) {
                $kv = explode('=', trim($bits[$i]), 2);
                if (count($kv) === 2 && trim($kv[0]) === 'q') {
                    $q = (float) trim($kv[1]);
                }
            }
            $candidates[] = ['tag' => $tag, 'q' => $q];
        }
        usort($candidates, static fn ($a, $b) => $b['q'] <=> $a['q']);

        $targets = array_map('strtolower', $this->config->targetLocales);

        foreach ($candidates as $c) {
            $idx = array_search($c['tag'], $targets, true);
            if ($idx !== false) {
                return $this->config->targetLocales[$idx];
            }
            $primary = explode('-', $c['tag'])[0];
            $idx = array_search($primary, $targets, true);
            if ($idx !== false) {
                return $this->config->targetLocales[$idx];
            }
        }
        return null;
    }
}
