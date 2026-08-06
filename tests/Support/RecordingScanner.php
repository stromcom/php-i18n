<?php

declare(strict_types=1);

namespace Stromcom\I18n\Tests\Support;

use Stromcom\I18n\Scan\ScannedKey;
use Stromcom\I18n\Scan\ScannerInterface;

/**
 * Records which files it was handed and yields keys from an optional factory, so
 * `ScannerPipeline` tests can observe dispatching and path relativizing without parsing
 * any real source.
 */
final class RecordingScanner implements ScannerInterface
{
    /** @var list<array{abs: string, rel: string}> */
    public array $seen = [];

    /**
     * @param list<string>                                     $extensions
     * @param (\Closure(string, string): list<ScannedKey>)|null $produce Defaults to one key named after the file.
     */
    public function __construct(
        private readonly array $extensions,
        private readonly ?\Closure $produce = null,
    ) {}

    public function supportedExtensions(): array
    {
        return $this->extensions;
    }

    public function scanFile(string $absolutePath, string $relativePath): array
    {
        $this->seen[] = ['abs' => $absolutePath, 'rel' => $relativePath];

        if ($this->produce !== null) {
            return ($this->produce)($absolutePath, $relativePath);
        }

        return [new ScannedKey(
            name: basename($relativePath),
            sourceText: 'text of ' . basename($relativePath),
            description: null,
            occurrences: [$relativePath . ':1'],
        )];
    }

    /**
     * @return list<string> Relative paths, in the order they were scanned.
     */
    public function relativePaths(): array
    {
        return array_map(static fn (array $s): string => $s['rel'], $this->seen);
    }
}
