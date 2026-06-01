<?php

declare(strict_types=1);

namespace Stromcom\I18n\Build;

use Psr\Log\LoggerInterface;
use Stromcom\I18n\Config\I18nConfig;
use Stromcom\I18n\Scan\ScannedKey;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

/**
 * POST `/api/v1/projects/{id}/keys/sync` — sends all scanned keys in a single
 * request. The API is idempotent (UPSERT).
 */
final readonly class KeySync
{
    public function __construct(
        private I18nConfig $config,
        private TranslatorClient $client,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param list<ScannedKey> $keys
     *
     * @return SyncResult
     */
    public function sync(array $keys): SyncResult
    {
        $payload = ['keys' => array_map(static fn (ScannedKey $k): array => $k->toApiPayload(), $keys)];
        $path = sprintf('api/v1/projects/%s/keys/sync', rawurlencode($this->config->projectId));

        $response = $this->client->postJson($path, $payload);

        try {
            $status = $response->getStatusCode();
            $body = $response->getContent(throw: false);
        } catch (ExceptionInterface $e) {
            throw new TranslatorClientException('Failed reading sync response: ' . $e->getMessage(), previous: $e);
        }

        if ($status < 200 || $status >= 300) {
            throw new TranslatorClientException(
                sprintf('Key sync failed: HTTP %d — %s', $status, substr($body, 0, 500)),
                statusCode: $status,
            );
        }

        try {
            $decoded = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new TranslatorClientException('Sync response is not JSON: ' . $e->getMessage(), previous: $e);
        }

        if (!is_array($decoded)) {
            throw new TranslatorClientException('Sync response root is not an object');
        }

        $added = is_int($decoded['added'] ?? null) ? $decoded['added'] : 0;
        $updated = is_int($decoded['updated'] ?? null) ? $decoded['updated'] : 0;
        $totalInSync = is_int($decoded['total_in_sync'] ?? null) ? $decoded['total_in_sync'] : count($keys);
        $stale = [];
        if (isset($decoded['stale']) && is_array($decoded['stale'])) {
            foreach ($decoded['stale'] as $s) {
                if (is_string($s)) {
                    $stale[] = $s;
                }
            }
        }

        $this->logger->info('[i18n] KeySync: ok', [
            'added'         => $added,
            'updated'       => $updated,
            'stale'         => count($stale),
            'total_in_sync' => $totalInSync,
            'sent'          => count($keys),
        ]);

        return new SyncResult(
            added: $added,
            updated: $updated,
            stale: $stale,
            totalInSync: $totalInSync,
            sent: count($keys),
        );
    }
}
