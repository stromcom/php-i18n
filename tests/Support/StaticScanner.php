<?php

declare(strict_types=1);

namespace Stromcom\I18n\Tests\Support;

use Stromcom\I18n\Scan\ScannedKey;
use Stromcom\I18n\Scan\ScannerInterface;

/**
 * Returns a fixed set of keys for the first file it is handed, nothing afterwards.
 *
 * `ScannerPipeline` is final readonly and cannot be mocked, so command tests drive a real
 * pipeline over one throwaway file and let this scanner supply the result.
 */
final class StaticScanner implements ScannerInterface
{
    private bool $used = false;

    /**
     * @param list<ScannedKey> $keys
     */
    public function __construct(private readonly array $keys) {}

    public function supportedExtensions(): array
    {
        return ['php'];
    }

    public function scanFile(string $absolutePath, string $relativePath): array
    {
        if ($this->used) {
            return [];
        }
        $this->used = true;

        return $this->keys;
    }
}
