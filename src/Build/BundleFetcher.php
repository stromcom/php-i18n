<?php

declare(strict_types=1);

namespace Stromcom\I18n\Build;

use Psr\Log\LoggerInterface;
use Stromcom\I18n\Config\I18nConfig;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

/**
 * GET `/api/v1/projects/{id}/bundles/{locale}?version=` with support for ETag /
 * 304 Not Modified. On 304 it leaves the local file untouched. On 422 (missing
 * keys) it returns a result with a message — the caller (CI) should treat this
 * case as a fatal error.
 */
final readonly class BundleFetcher
{
    public function __construct(
        private I18nConfig $config,
        private TranslatorClient $client,
        private EtagStore $etagStore,
        private LoggerInterface $logger,
        private PhpCacheWriter $phpCacheWriter,
    ) {}

    /**
     * @return FetchResult
     */
    public function fetch(string $locale, string $version = 'published'): FetchResult
    {
        $headers = [];
        $previousEtag = $this->etagStore->get($locale);
        if ($previousEtag !== null && is_file($this->config->bundlePath($locale))) {
            $headers['If-None-Match'] = $previousEtag;
        }

        $path = sprintf('api/v1/projects/%s/bundles/%s', rawurlencode($this->config->projectId), rawurlencode($locale));
        $response = $this->client->get($path, ['version' => $version], $headers);

        try {
            $status = $response->getStatusCode();
        } catch (ExceptionInterface $e) {
            throw new TranslatorClientException('Failed to read response status: ' . $e->getMessage(), previous: $e);
        }

        if ($status === 304) {
            $this->logger->info('[i18n] BundleFetcher: not modified, keeping cache', ['locale' => $locale]);
            return new FetchResult(locale: $locale, status: 304, written: false, missingKeys: []);
        }

        try {
            $body = $response->getContent(throw: false);
            $responseHeaders = $response->getHeaders(throw: false);
        } catch (ExceptionInterface $e) {
            throw new TranslatorClientException('Failed to read response body: ' . $e->getMessage(), previous: $e);
        }

        if ($status === 422) {
            $missing = $this->extractMissingKeys($body);
            $this->logger->error('[i18n] BundleFetcher: 422 missing keys', [
                'locale' => $locale,
                'count'  => count($missing),
            ]);
            return new FetchResult(locale: $locale, status: 422, written: false, missingKeys: $missing);
        }

        if ($status < 200 || $status >= 300) {
            throw new TranslatorClientException(
                sprintf('Bundle fetch failed for locale "%s": HTTP %d — %s', $locale, $status, substr($body, 0, 500)),
                statusCode: $status,
            );
        }

        $bundlePath = $this->config->bundlePath($locale);
        $dir = dirname($bundlePath);
        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new TranslatorClientException('Cannot create bundles dir: ' . $dir);
        }
        if (file_put_contents($bundlePath, $body) === false) {
            throw new TranslatorClientException('Cannot write bundle file: ' . $bundlePath);
        }

        $this->writePhpCache($locale, $body, $bundlePath);

        $etag = $this->headerLine($responseHeaders, 'etag');
        if ($etag !== null) {
            $this->etagStore->set($locale, $etag);
        }

        $this->logger->info('[i18n] BundleFetcher: written', [
            'locale' => $locale,
            'bytes'  => strlen($body),
            'path'   => $bundlePath,
        ]);

        return new FetchResult(locale: $locale, status: $status, written: true, missingKeys: []);
    }

    /**
     * Generates an OPcache-friendly `<?php return [...];` from the freshly
     * downloaded JSON. The translator returns a wrapper
     * `{ version, locale, generated_at, translations }` — only the flat
     * `translations` map belongs in the PHP cache (it must mirror what
     * `BundleLoader::normalize()` extracts, otherwise the mtime check would let
     * an empty bundle through to runtime).
     *
     * Failure is not fatal — `BundleLoader` can fall back to JSON.
     */
    private function writePhpCache(string $locale, string $jsonBody, string $jsonPath): void
    {
        try {
            $decoded = json_decode($jsonBody, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->logger->warning('[i18n] BundleFetcher: PHP cache skipped, JSON decode failed', [
                'locale' => $locale,
                'error'  => $e->getMessage(),
            ]);
            return;
        }
        if (!is_array($decoded)) {
            return;
        }

        if (isset($decoded['translations']) && is_array($decoded['translations'])) {
            /** @var array<int|string, mixed> $source */
            $source = $decoded['translations'];
        } else {
            $source = $decoded;
        }

        $bundle = [];
        foreach ($source as $k => $v) {
            if (is_string($k) && is_string($v)) {
                $bundle[$k] = $v;
            }
        }

        $phpPath = $this->config->bundlePhpCachePath($locale);
        if (!$this->phpCacheWriter->write($phpPath, $bundle, $jsonPath)) {
            $this->logger->warning('[i18n] BundleFetcher: PHP cache write failed', [
                'locale' => $locale,
                'path'   => $phpPath,
            ]);
        }
    }

    /**
     * @return list<string>
     */
    private function extractMissingKeys(string $body): array
    {
        try {
            $decoded = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        if (!is_array($decoded) || !isset($decoded['missing_keys']) || !is_array($decoded['missing_keys'])) {
            return [];
        }
        $out = [];
        foreach ($decoded['missing_keys'] as $key) {
            if (is_string($key)) {
                $out[] = $key;
            }
        }
        return $out;
    }

    /**
     * @param array<string, list<string>> $headers
     */
    private function headerLine(array $headers, string $name): ?string
    {
        $name = strtolower($name);
        foreach ($headers as $h => $values) {
            if (strtolower($h) === $name && isset($values[0])) {
                return $values[0];
            }
        }
        return null;
    }
}
