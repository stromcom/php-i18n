<?php

declare(strict_types=1);

namespace Stromcom\I18n\Runtime;

use Stromcom\I18n\Scan\XsltScanner;

/**
 * Two-pass XSLT renderer with i18n translations.
 *
 * **Pass 1 — XSLT transformation**:
 *   The consumer passes an XSL template + XML data. XSLTProcessor evaluates the AVTs
 *   (attribute value templates) in `<i18n:t/>` elements, so attributes like
 *   `count="{$total}"` or `name="{customer/firstName}"` hold concrete strings in the
 *   intermediate result. The `<i18n:t/>` elements themselves are not resolved — they
 *   remain in the intermediate result.
 *
 * **Pass 2 — DOM post-processing**:
 *   Walk all `//i18n:t` via DOMXPath, replacing each with a text node holding the
 *   translated content. The `key` + `default` attributes go into
 *   `TranslatorInterface::trans()`, the `note` attribute is metadata for the scanner
 *   (ignored at runtime), and all other attributes go in as ICU `MessageFormatter`
 *   parameters.
 *
 * **Attribute convention**:
 *
 *   <i18n:t key="cart.itemCount"
 *           default="{count, plural, one {# item} other {# items}}"
 *           count="{$totalItems}"
 *           note="Counter in the sidebar"/>
 *
 * The namespace for the elements is `XsltScanner::NAMESPACE_URI` (`https://stromcom.cz/i18n`).
 *
 * **Output format**: if the XSL defines `<xsl:output method="html"/>`, we return via
 * `saveHTML()`. For `method="xml"` (default), `saveXML()`. The consumer can override via
 * the `$outputFormat` parameter.
 */
final readonly class XsltRenderer
{
    public function __construct(
        private TranslatorInterface $translator,
        private LocaleContext $context,
    ) {}

    /**
     * @param array<string, scalar> $xsltParams Parameters passed to XSLTProcessor::setParameter('', $name, $value).
     */
    public function render(
        string $xslPath,
        string|\DOMDocument $data,
        ?string $locale = null,
        array $xsltParams = [],
        ?string $outputFormat = null,
    ): string {
        $effectiveLocale = $locale ?? $this->context->get();

        $previousUseErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $xsl = $this->loadStylesheet($xslPath);
            $xml = $this->loadData($data);

            $processor = new \XSLTProcessor();
            $processor->importStylesheet($xsl);
            foreach ($xsltParams as $name => $value) {
                $processor->setParameter('', $name, (string) $value);
            }

            $intermediate = $processor->transformToDoc($xml);
            if ($intermediate === false) {
                throw new XsltRendererException('XSLT transformation failed: ' . $this->collectLibxmlErrors());
            }
            // Without an explicit UTF-8 encoding, saveXML() would escape non-ASCII
            // characters into numeric entities (`Á` → `&#xC1;`). transformToDoc()
            // does not propagate the encoding from the XSL output declaration, so we
            // set it here — the input data is UTF-8 even if the XSL says otherwise.
            $intermediate->encoding = 'UTF-8';

            $this->translatePass($intermediate, $effectiveLocale);

            $format = $outputFormat ?? $this->detectOutputFormat($xsl);
            return $this->serialize($intermediate, $format);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousUseErrors);
        }
    }

    private function loadStylesheet(string $path): \DOMDocument
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new XsltRendererException('XSL stylesheet not readable: ' . $path);
        }
        $dom = new \DOMDocument();
        if ($dom->load($path, LIBXML_NONET) === false) {
            throw new XsltRendererException('XSL stylesheet parse error: ' . $this->collectLibxmlErrors());
        }
        return $dom;
    }

    private function loadData(string|\DOMDocument $data): \DOMDocument
    {
        if ($data instanceof \DOMDocument) {
            return $data;
        }
        $dom = new \DOMDocument();
        if ($dom->loadXML($data, LIBXML_NONET) === false) {
            throw new XsltRendererException('XML input parse error: ' . $this->collectLibxmlErrors());
        }
        return $dom;
    }

    private function translatePass(\DOMDocument $intermediate, string $locale): void
    {
        $xpath = new \DOMXPath($intermediate);
        $xpath->registerNamespace('i18n', XsltScanner::NAMESPACE_URI);

        $nodes = $xpath->query('//i18n:t');
        if ($nodes === false) {
            return;
        }

        // Convert to an array, because replaceChild mutates the DOM during iteration
        // and the NodeList would get out of sync.
        $elements = [];
        foreach ($nodes as $node) {
            if ($node instanceof \DOMElement) {
                $elements[] = $node;
            }
        }

        foreach ($elements as $element) {
            $key = $element->getAttribute('key');
            $default = $element->getAttribute('default');
            if ($key === '' || $default === '') {
                // Incomplete element — remove the whole node, yields empty text
                $parent = $element->parentNode;
                if ($parent !== null) {
                    $parent->removeChild($element);
                }
                continue;
            }

            $params = $this->collectParams($element);
            $translated = $this->translator->trans($key, $default, $params, $locale);

            $textNode = $intermediate->createTextNode($translated);
            $parent = $element->parentNode;
            if ($parent !== null) {
                $parent->replaceChild($textNode, $element);
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private function collectParams(\DOMElement $element): array
    {
        $params = [];
        foreach ($element->attributes as $attr) {
            if (!$attr instanceof \DOMAttr) {
                continue;
            }
            $name = $attr->name;
            if ($name === 'key' || $name === 'default' || $name === 'note') {
                continue;
            }
            $params[$name] = $attr->value;
        }
        return $params;
    }

    /**
     * From the XSL `<xsl:output method="..."/>` determines whether we generate HTML or XML.
     * The default XSLT 1.0 behaviour is XML, unless the output root is `<html>`.
     */
    private function detectOutputFormat(\DOMDocument $xsl): string
    {
        $xpath = new \DOMXPath($xsl);
        $xpath->registerNamespace('xsl', 'http://www.w3.org/1999/XSL/Transform');
        $output = $xpath->query('//xsl:output[@method]');
        if ($output !== false && $output->length > 0) {
            $first = $output->item(0);
            if ($first instanceof \DOMElement) {
                $method = strtolower($first->getAttribute('method'));
                if ($method === 'html') {
                    return 'html';
                }
                if ($method === 'text') {
                    return 'text';
                }
            }
        }
        return 'xml';
    }

    private function serialize(\DOMDocument $doc, string $format): string
    {
        if ($format === 'html') {
            $out = $doc->saveHTML();
            return $out === false ? '' : $out;
        }
        if ($format === 'text') {
            return $doc->textContent;
        }
        $out = $doc->saveXML();
        return $out === false ? '' : $out;
    }

    private function collectLibxmlErrors(): string
    {
        $messages = [];
        foreach (libxml_get_errors() as $err) {
            $messages[] = trim($err->message) . ' (line ' . $err->line . ')';
        }
        return $messages === [] ? '(no details)' : implode('; ', $messages);
    }
}
