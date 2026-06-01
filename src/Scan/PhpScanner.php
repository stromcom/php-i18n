<?php

declare(strict_types=1);

namespace Stromcom\I18n\Scan;

use PhpParser\Error as ParserError;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use Psr\Log\LoggerInterface;
use Stromcom\I18n\Scan\Visitor\PhpTFunctionVisitor;

/**
 * Scans `.php` files via the `nikic/php-parser` AST. Detects calls of the form:
 *
 *   $translator->trans('login.submit', 'Sign in');
 *   $translator->trans('cart.itemCount', '{count, plural, ...}', ['count' => 5]);
 *   $translator->trans('signup.title', 'Sign up', note: 'Heading on signup page');
 *
 * Calls with a non-literal 1st or 2nd argument are skipped (logged warning).
 * Parse failure (syntax error in the project) → logged + file skipped.
 */
final class PhpScanner implements ScannerInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $methodName = 'trans',
    ) {}

    public function supportedExtensions(): array
    {
        return ['php'];
    }

    public function scanFile(string $absolutePath, string $relativePath): array
    {
        $source = @file_get_contents($absolutePath);
        if ($source === false) {
            $this->logger->warning('[i18n] PhpScanner: file unreadable', ['path' => $relativePath]);
            return [];
        }

        $parser = (new ParserFactory())->createForHostVersion();
        try {
            $stmts = $parser->parse($source);
        } catch (ParserError $e) {
            $this->logger->warning('[i18n] PhpScanner: parse error', [
                'path'  => $relativePath,
                'error' => $e->getMessage(),
            ]);
            return [];
        }

        if ($stmts === null) {
            return [];
        }

        $visitor = new PhpTFunctionVisitor($this->methodName, $relativePath, $this->logger);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        $traverser->addVisitor($visitor);
        $traverser->traverse($stmts);

        return $visitor->keys();
    }
}
