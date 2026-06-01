<?php

declare(strict_types=1);

namespace Stromcom\I18n\Build;

/**
 * Generates a `<?php return [...];` from the bundle data and synchronizes the
 * mtime with the corresponding JSON file. Goal: OPcache caches the `.cache.php`
 * bytecode in shared memory, so the `require` in a subsequent request costs no
 * parse at all.
 *
 * `BundleLoader` prefers that file **only if its mtime is ≥ the `.json`** —
 * hence the `touch()` at the end. A manual edit of the JSON is then detected by
 * `BundleLoader` (JSON mtime > PHP cache mtime) and it falls back to JSON.
 */
final readonly class PhpCacheWriter
{
    /**
     * @param array<string, string> $bundle  flat map key → text
     *
     * @return bool true if the write succeeded
     */
    public function write(string $phpPath, array $bundle, string $sourceJsonPath): bool
    {
        $code = "<?php\n\ndeclare(strict_types=1);\n\n"
              . "// Auto-generated from " . basename($sourceJsonPath) . " by stromcom/php-i18n. Do not edit.\n"
              . "// `BundleLoader` prefers this file if its mtime is ≥ the JSON.\n\n"
              . "return " . var_export($bundle, true) . ";\n";

        if (file_put_contents($phpPath, $code) === false) {
            return false;
        }

        if (is_file($sourceJsonPath)) {
            $jsonMtime = @filemtime($sourceJsonPath);
            if ($jsonMtime !== false) {
                // Sync mtime — the PHP cache must be ≥ JSON for the loader to trust it.
                // If the filesystem touch fails (rare), the loader falls back to JSON, so OK.
                @touch($phpPath, $jsonMtime);
            }
        }
        return true;
    }
}
