<?php

declare(strict_types=1);

namespace Stromcom\I18n\Scan;

use Psr\Log\LoggerInterface;

/**
 * Scans XSLT templates (`.xsl` / `.xslt`) via `DOMDocument` + `DOMXPath`.
 * Detects the element variant of the call:
 *
 *   <i18n:t key="login.submit" default="Sign in" note="Login form"/>
 *
 * where `xmlns:i18n="https://stromcom.cz/i18n"` (the expected namespace).
 *
 * The XPath variant `i18n:t('key', 'default', 'note')` inside a `select` attribute
 * would require an XPath parser — TODO once there is a concrete use-case. The element
 * variant covers most of the static text in XSLT templates.
 */
final class XsltScanner implements ScannerInterface
{
    public const NAMESPACE_URI = 'https://stromcom.cz/i18n';

    public function __construct(private readonly LoggerInterface $logger) {}

    public function supportedExtensions(): array
    {
        return ['xsl', 'xslt'];
    }

    public function scanFile(string $absolutePath, string $relativePath): array
    {
        $source = @file_get_contents($absolutePath);
        if ($source === false) {
            $this->logger->warning('[i18n] XsltScanner: file unreadable', ['path' => $relativePath]);
            return [];
        }

        $previousUseErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $dom = new \DOMDocument();
            $dom->preserveWhiteSpace = false;
            $loaded = $dom->loadXML($source, LIBXML_NONET);
            if ($loaded === false) {
                foreach (libxml_get_errors() as $err) {
                    $this->logger->warning('[i18n] XsltScanner: libxml error', [
                        'path'    => $relativePath,
                        'line'    => $err->line,
                        'message' => trim($err->message),
                    ]);
                }
                return [];
            }

            $xpath = new \DOMXPath($dom);
            $xpath->registerNamespace('i18n', self::NAMESPACE_URI);

            $nodes = $xpath->query('//i18n:t');
            if ($nodes === false) {
                return [];
            }

            $keys = [];
            foreach ($nodes as $node) {
                if (!$node instanceof \DOMElement) {
                    continue;
                }
                $key = $node->getAttribute('key');
                $default = $node->getAttribute('default');
                if ($key === '' || $default === '') {
                    $this->logger->info('[i18n] XsltScanner: skipping <i18n:t> missing key/default', [
                        'path' => $relativePath,
                        'line' => $node->getLineNo(),
                    ]);
                    continue;
                }
                $note = $node->getAttribute('note');
                $keys[] = new ScannedKey(
                    name: $key,
                    sourceText: $default,
                    description: $note === '' ? null : $note,
                    occurrences: [$relativePath . ':' . $node->getLineNo()],
                );
            }
            return $keys;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousUseErrors);
        }
    }
}
