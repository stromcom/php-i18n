<?php

declare(strict_types=1);

namespace Stromcom\I18n\Scan;

use Psr\Log\LoggerInterface;
use Stromcom\I18n\Runtime\TwigI18nExtension;
use Stromcom\I18n\Scan\Visitor\TwigTFunctionVisitor;
use Twig\Environment;
use Twig\Error\SyntaxError;
use Twig\Loader\ArrayLoader;
use Twig\NodeTraverser;
use Twig\Source;
use Twig\TwigFunction;

/**
 * Scans `.twig` templates via the Twig parser + a custom NodeVisitor. Detects
 * `{{ t('key', 'default') }}`, `{{ t('key', 'default', { count: n }) }}` and
 * filter chains `{{ t('key', 'default')|upper }}` — always via the AST, no regex.
 *
 * For a correct parse we must tell Twig that the `t` function exists. We use
 * the real `TwigI18nExtension`, but with no-op runtime instances (the scanner
 * does not need the runtime). Alternatively we could register a naked TwigFunction —
 * here we choose the real extension for consistency with how the package works
 * in production Twig.
 */
final class TwigScanner implements ScannerInterface
{
    public function __construct(private readonly LoggerInterface $logger) {}

    public function supportedExtensions(): array
    {
        return ['twig'];
    }

    public function scanFile(string $absolutePath, string $relativePath): array
    {
        $source = @file_get_contents($absolutePath);
        if ($source === false) {
            $this->logger->warning('[i18n] TwigScanner: file unreadable', ['path' => $relativePath]);
            return [];
        }

        // A fresh Twig env for each scan — minimizes the chance of shared state
        // between files. The loader is an ArrayLoader (Twig needs it for tokenize).
        $env = new Environment(new ArrayLoader([$relativePath => $source]), [
            'cache' => false,
            'autoescape' => false,
            'strict_variables' => false,
        ]);
        $env->addExtension(new ScannerStubExtension());
        // Real templates call plenty of functions (path, url, asset, csp_nonce, …)
        // that we do not need to know about in the scanner. We register a catch-all
        // resolver so the Twig parser does not fail with "Unknown function".
        $env->registerUndefinedFunctionCallback(
            static fn (string $name): TwigFunction => new TwigFunction($name, static fn (): string => ''),
        );
        // Likewise for filters (`|upper`, `|raw`, the consumer's custom filters).
        $env->registerUndefinedFilterCallback(
            static fn (string $name): \Twig\TwigFilter => new \Twig\TwigFilter($name, static fn (mixed $v): mixed => $v),
        );

        try {
            $tokenStream = $env->tokenize(new Source($source, $relativePath));
            $ast = $env->parse($tokenStream);
        } catch (SyntaxError $e) {
            $this->logger->warning('[i18n] TwigScanner: syntax error', [
                'path'  => $relativePath,
                'error' => $e->getMessage(),
            ]);
            return [];
        }

        $visitor = new TwigTFunctionVisitor($relativePath, $this->logger);
        $traverser = new NodeTraverser($env, [$visitor]);
        $traverser->traverse($ast);

        return $visitor->keys();
    }
}
