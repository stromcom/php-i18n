<?php

declare(strict_types=1);

namespace Stromcom\I18n\Build;

use Stromcom\I18n\Config\I18nConfig;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Thin wrapper around `symfony/http-client` with a pre-configured bearer token
 * and base URL. Higher layers (`KeySync`, `BundleFetcher`) request a specific
 * endpoint and get the raw `ResponseInterface` back.
 */
final readonly class TranslatorClient
{
    private HttpClientInterface $http;

    public function __construct(I18nConfig $config, ?HttpClientInterface $http = null)
    {
        $this->http = ($http ?? HttpClient::create())->withOptions([
            'base_uri' => rtrim($config->baseUrl, '/') . '/',
            'auth_bearer' => $config->token,
            'headers' => [
                'Accept' => 'application/json',
            ],
        ]);
    }

    /**
     * @param array<string, mixed>   $json
     * @param array<string, string>  $headers
     */
    public function postJson(string $path, array $json, array $headers = []): ResponseInterface
    {
        try {
            return $this->http->request('POST', ltrim($path, '/'), [
                'json'    => $json,
                'headers' => $headers,
            ]);
        } catch (ExceptionInterface $e) {
            throw new TranslatorClientException(
                'Translator POST failed: ' . $e->getMessage(),
                previous: $e,
            );
        }
    }

    /**
     * @param array<string, scalar|null> $query
     * @param array<string, string>      $headers
     */
    public function get(string $path, array $query = [], array $headers = []): ResponseInterface
    {
        try {
            return $this->http->request('GET', ltrim($path, '/'), [
                'query'   => $query,
                'headers' => $headers,
            ]);
        } catch (ExceptionInterface $e) {
            throw new TranslatorClientException(
                'Translator GET failed: ' . $e->getMessage(),
                previous: $e,
            );
        }
    }
}
