<?php

declare(strict_types=1);

namespace Stromcom\I18n\Scan;

interface ScannerInterface
{
    /**
     * Returns the extensions the scanner can process (lowercase, without dot).
     *
     * @return list<string>
     */
    public function supportedExtensions(): array;

    /**
     * Scans a single file and returns the keys found. Implementations MUST
     * ignore any `t()` call with a non-literal argument
     * (variable, concatenation, function call) — the scan is purely static.
     *
     * @param string $absolutePath Absolute path to the file.
     * @param string $relativePath Path relative to the repo root (for `occurrences`).
     *
     * @return list<ScannedKey>
     */
    public function scanFile(string $absolutePath, string $relativePath): array;
}
