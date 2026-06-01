<?php

declare(strict_types=1);

namespace Stromcom\I18n\Scan;

use Psr\Log\LoggerInterface;
use Stromcom\I18n\Config\I18nConfig;

/**
 * Orchestrator: walks all `scanPaths` from the configuration, dispatches each
 * file to the correct scanner (by extension), deduplicates keys (same `name`
 * from different files → merge `occurrences`), and reports conflicts (different
 * `sourceText`/`description` for the same key) as a warning.
 */
final readonly class ScannerPipeline
{
    /** @var list<ScannerInterface> */
    private array $scanners;

    private string $rootDir;

    /**
     * @param list<ScannerInterface> $scanners
     */
    public function __construct(
        private I18nConfig $config,
        private LoggerInterface $logger,
        array $scanners,
        ?string $rootDir = null,
    ) {
        $this->scanners = $scanners;
        $this->rootDir = $rootDir ?? $this->commonRootDir();
    }

    /**
     * @return list<ScannedKey>
     */
    public function scan(): array
    {
        /** @var array<string, ScannedKey> $byName */
        $byName = [];

        foreach ($this->config->scanPaths as $path) {
            if (!is_dir($path) && !is_file($path)) {
                $this->logger->warning('[i18n] Scanner: scan path does not exist', ['path' => $path]);
                continue;
            }
            foreach ($this->iterateFiles($path) as $abs) {
                $scanner = $this->scannerFor($abs);
                if ($scanner === null) {
                    continue;
                }
                $rel = $this->relativize($abs);
                foreach ($scanner->scanFile($abs, $rel) as $key) {
                    $this->merge($byName, $key);
                }
            }
        }

        return array_values($byName);
    }

    /**
     * @param array<string, ScannedKey> $byName
     */
    private function merge(array &$byName, ScannedKey $key): void
    {
        if (!isset($byName[$key->name])) {
            $byName[$key->name] = $key;
            return;
        }
        $existing = $byName[$key->name];

        if ($existing->sourceText !== $key->sourceText) {
            $this->logger->warning('[i18n] Scanner: duplicate key with different source_text', [
                'key'  => $key->name,
                'a'    => $existing->occurrences[0] ?? '?',
                'a_text' => $existing->sourceText,
                'b'    => $key->occurrences[0] ?? '?',
                'b_text' => $key->sourceText,
            ]);
        }
        if ($key->description !== null && $existing->description !== null
            && $key->description !== $existing->description
        ) {
            $this->logger->info('[i18n] Scanner: duplicate key with different note (keeping first)', [
                'key' => $key->name,
            ]);
        }
        foreach ($key->occurrences as $occ) {
            $existing->addOccurrence($occ);
        }
    }

    /**
     * @return iterable<string>  Absolute file paths.
     */
    private function iterateFiles(string $path): iterable
    {
        if (is_file($path)) {
            if (!$this->isExcluded($path)) {
                yield $path;
            }
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
        );
        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $abs = $file->getPathname();
            if ($this->isExcluded($abs)) {
                continue;
            }
            yield $abs;
        }
    }

    private function isExcluded(string $absolutePath): bool
    {
        $normalized = str_replace('\\', '/', $absolutePath);
        foreach ($this->config->scanExcludes as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }
        return false;
    }

    private function scannerFor(string $absolutePath): ?ScannerInterface
    {
        $ext = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));
        if ($ext === '') {
            return null;
        }
        foreach ($this->scanners as $scanner) {
            if (in_array($ext, $scanner->supportedExtensions(), true)) {
                return $scanner;
            }
        }
        return null;
    }

    private function relativize(string $absolute): string
    {
        $abs = str_replace('\\', '/', $absolute);
        $root = rtrim(str_replace('\\', '/', $this->rootDir), '/') . '/';
        if (str_starts_with($abs, $root)) {
            return substr($abs, strlen($root));
        }
        return $abs;
    }

    /**
     * Longest common directory prefix of all scan paths — works as a
     * heuristic for the repo root when the consumer does not pass an explicit `$rootDir`.
     */
    private function commonRootDir(): string
    {
        $paths = $this->config->scanPaths;
        if ($paths === []) {
            return getcwd() === false ? '/' : (string) getcwd();
        }
        $normalized = array_map(static fn (string $p): string => str_replace('\\', '/', $p), $paths);
        $segments = array_map(static fn (string $p): array => explode('/', rtrim($p, '/')), $normalized);
        $first = $segments[0];
        $commonLen = count($first);
        for ($i = 1, $n = count($segments); $i < $n; $i++) {
            $cur = $segments[$i];
            $max = min($commonLen, count($cur));
            $match = 0;
            while ($match < $max && $first[$match] === $cur[$match]) {
                $match++;
            }
            $commonLen = $match;
        }
        $common = implode('/', array_slice($first, 0, $commonLen));
        return $common === '' ? '/' : $common;
    }
}
