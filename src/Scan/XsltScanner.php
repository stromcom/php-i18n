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
 * where `xmlns:i18n="https://stromcom.cz/i18n"` (the expected namespace). Matching is
 * by namespace URI, not by prefix — any prefix bound to that URI works, and an element
 * that merely *looks* like `i18n:t` but sits in a different namespace is reported as a
 * warning instead of being silently synced (`XsltRenderer` would leave such an element
 * unresolved in the output, so a typo must not pass the scan quietly).
 *
 * **Attribute value templates**: `<i18n:t>` is a literal result element, so XSLT treats
 * *all* of its attributes as AVTs. That means the source spells an ICU pattern with
 * doubled braces — `default="{{count, plural, one {{# item}} other {{# items}}}}"` — and
 * XSLTProcessor collapses them to `{count, plural, one {# item} other {# items}}` before
 * `XsltRenderer` ever sees it. The scanner performs the same unescaping, so the
 * `source_text` synced to the platform is the ICU pattern the runtime actually formats.
 * An attribute holding a real AVT expression (single braces, e.g. `key="{$dynamic}"`)
 * cannot be resolved statically — such an element is skipped with a warning.
 *
 * The XPath variant `i18n:t('key', 'default', 'note')` inside a `select` attribute
 * would require an XPath parser — TODO once there is a concrete use-case. The element
 * variant covers most of the static text in XSLT templates.
 *
 * **Not detected**: attributes supplied via `<xsl:attribute name="default">…</xsl:attribute>`
 * instead of a literal attribute. Those are child elements, invisible to an attribute
 * read, and their value may depend on runtime data. Such an element renders fine but its
 * key never reaches the platform — the scanner logs a warning naming the attribute.
 */
final class XsltScanner implements ScannerInterface
{
    public const NAMESPACE_URI = 'https://stromcom.cz/i18n';

    /** The XSLT namespace — needed to spot `<xsl:attribute>` children. */
    public const XSL_NAMESPACE_URI = 'http://www.w3.org/1999/XSL/Transform';

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

            // registerNodeNS: false — otherwise the document's own prefix bindings
            // shadow the registered one and an `i18n:` prefix bound to the *wrong* URI
            // would still match. Matching must follow the namespace URI alone, the way
            // XsltRenderer resolves elements at runtime.
            $xpath = new \DOMXPath($dom, false);
            $xpath->registerNamespace('i18n', self::NAMESPACE_URI);

            $this->warnAboutForeignNamespaces($xpath, $relativePath);

            $nodes = $xpath->query('//i18n:t');
            if ($nodes === false) {
                return [];
            }

            $keys = [];
            foreach ($nodes as $node) {
                if (!$node instanceof \DOMElement) {
                    continue;
                }
                $line = $node->getLineNo();

                $key = $this->literalAttribute($node, 'key', $relativePath, $line);
                $default = $this->literalAttribute($node, 'default', $relativePath, $line);
                if ($key === null || $default === null) {
                    continue;
                }
                if ($key === '' || $default === '') {
                    $this->logger->info('[i18n] XsltScanner: skipping <i18n:t> missing key/default', [
                        'path' => $relativePath,
                        'line' => $line,
                    ]);
                    continue;
                }

                $note = $this->unescapeAvt($node->getAttribute('note'))[0];
                $keys[] = new ScannedKey(
                    name: $key,
                    sourceText: $default,
                    description: $note === '' ? null : $note,
                    occurrences: [$relativePath . ':' . $line],
                );
            }
            return $keys;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousUseErrors);
        }
    }

    /**
     * Reads an `<i18n:t>` attribute as a static literal, undoing the XSLT AVT escaping.
     * Returns `null` when the value cannot be resolved statically — either because the
     * attribute is missing but supplied via `<xsl:attribute>`, or because it holds a real
     * AVT expression. Both cases are logged; the caller skips the element.
     */
    private function literalAttribute(\DOMElement $element, string $name, string $relativePath, int $line): ?string
    {
        if (!$element->hasAttribute($name) && $this->hasXslAttributeChild($element, $name)) {
            $this->logger->warning(
                '[i18n] XsltScanner: <i18n:t> sets @' . $name . ' via <xsl:attribute> — key cannot be synced',
                ['path' => $relativePath, 'line' => $line, 'attribute' => $name],
            );
            return null;
        }

        [$literal, $isDynamic] = $this->unescapeAvt($element->getAttribute($name));
        if ($isDynamic) {
            $this->logger->warning(
                '[i18n] XsltScanner: <i18n:t> @' . $name . ' contains an AVT expression — key cannot be synced',
                ['path' => $relativePath, 'line' => $line, 'attribute' => $name, 'value' => $literal],
            );
            return null;
        }
        return $literal;
    }

    /**
     * Detects `<xsl:attribute name="$name">` among the element's children — the runtime-only
     * way of setting an attribute, which an attribute read cannot see.
     */
    private function hasXslAttributeChild(\DOMElement $element, string $name): bool
    {
        foreach ($element->childNodes as $child) {
            if ($child instanceof \DOMElement
                && $child->namespaceURI === self::XSL_NAMESPACE_URI
                && $child->localName === 'attribute'
                && $child->getAttribute('name') === $name
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Undoes XSLT attribute-value-template escaping: `{{` → `{`, `}}` → `}`. A single
     * `{…}` is a real XPath expression evaluated at runtime, so it is passed through
     * verbatim and flagged as dynamic.
     *
     * Operates on bytes — every brace is ASCII and UTF-8 continuation bytes are >= 0x80,
     * so multibyte characters cannot be mistaken for a delimiter.
     *
     * @return array{0: string, 1: bool} `[unescaped value, contains an AVT expression]`
     */
    private function unescapeAvt(string $value): array
    {
        $out = '';
        $dynamic = false;
        $length = strlen($value);

        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];

            if ($char === '{') {
                if ($i + 1 < $length && $value[$i + 1] === '{') {
                    $out .= '{';
                    $i++;
                    continue;
                }
                $dynamic = true;
                $close = strpos($value, '}', $i + 1);
                if ($close === false) {
                    // Unterminated AVT — malformed XSLT; keep the remainder verbatim.
                    return [$out . substr($value, $i), true];
                }
                $out .= substr($value, $i, $close - $i + 1);
                $i = $close;
                continue;
            }

            if ($char === '}' && $i + 1 < $length && $value[$i + 1] === '}') {
                $out .= '}';
                $i++;
                continue;
            }

            $out .= $char;
        }

        return [$out, $dynamic];
    }

    /**
     * A `<t key= default=>` element outside the i18n namespace is almost always a
     * mistyped namespace declaration (`http://` instead of `https://`, a stale URI).
     * The renderer would leave it unresolved in the output, so flag it here.
     */
    private function warnAboutForeignNamespaces(\DOMXPath $xpath, string $relativePath): void
    {
        $suspects = $xpath->query(
            '//*[local-name()="t" and namespace-uri()!="' . self::NAMESPACE_URI . '" and @key and @default]',
        );
        if ($suspects === false) {
            return;
        }
        foreach ($suspects as $suspect) {
            if (!$suspect instanceof \DOMElement) {
                continue;
            }
            $this->logger->warning('[i18n] XsltScanner: <t> element with key/default outside the i18n namespace', [
                'path'     => $relativePath,
                'line'     => $suspect->getLineNo(),
                'found'    => $suspect->namespaceURI ?? '(none)',
                'expected' => self::NAMESPACE_URI,
            ]);
        }
    }
}
