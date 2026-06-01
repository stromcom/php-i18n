<?php

declare(strict_types=1);

namespace Stromcom\I18n\Scan\Visitor;

use Psr\Log\LoggerInterface;
use Stromcom\I18n\Scan\ScannedKey;
use Twig\Environment;
use Twig\Node\Expression\ConstantExpression;
use Twig\Node\Expression\FunctionExpression;
use Twig\Node\Node;
use Twig\NodeVisitor\NodeVisitorInterface;

/**
 * Twig AST visitor for `{{ t('key', 'default') }}` calls. Twig 3.x represents
 * a function call as `Twig\Node\Expression\FunctionExpression` with the name in
 * `getAttribute('name')` and the arguments as the named subnode `arguments`.
 *
 * Stateful — accumulates `$keys`, one instance per file.
 */
final class TwigTFunctionVisitor implements NodeVisitorInterface
{
    /** @var list<ScannedKey> */
    private array $keys = [];

    public function __construct(
        private readonly string $relativePath,
        private readonly LoggerInterface $logger,
    ) {}

    public function enterNode(Node $node, Environment $env): Node
    {
        if (!$node instanceof FunctionExpression) {
            return $node;
        }
        if ($node->getAttribute('name') !== 't') {
            return $node;
        }

        if (!$node->hasNode('arguments')) {
            return $node;
        }
        $args = $node->getNode('arguments');

        $key = $this->literalAt($args, 0, 'key');
        $default = $this->literalAt($args, 1, 'default');
        if ($key === null || $default === null) {
            $this->logger->info('[i18n] TwigScanner: skipping t() with non-literal key/default', [
                'path' => $this->relativePath,
                'line' => $node->getTemplateLine(),
            ]);
            return $node;
        }

        $description = null;
        $maybeNote = $this->namedLiteral($args, 'note');
        if ($maybeNote !== null) {
            $description = $maybeNote;
        }

        $this->keys[] = new ScannedKey(
            name: $key,
            sourceText: $default,
            description: $description,
            occurrences: [$this->relativePath . ':' . $node->getTemplateLine()],
        );

        return $node;
    }

    public function leaveNode(Node $node, Environment $env): Node
    {
        return $node;
    }

    public function getPriority(): int
    {
        return 0;
    }

    /**
     * Returns the literal at the given position, otherwise null. Twig arguments
     * can be named (`{name: value}`) as well as positional — named ones are
     * ignored for the index-based lookup.
     */
    private function literalAt(Node $args, int $index, string $label): ?string
    {
        $i = 0;
        foreach ($args as $name => $child) {
            if (is_string($name)) {
                continue; // named arg
            }
            if ($i === $index) {
                if ($child instanceof ConstantExpression && is_string($child->getAttribute('value'))) {
                    return $child->getAttribute('value');
                }
                $this->logger->info('[i18n] TwigScanner: ' . $label . ' arg is not a string literal', [
                    'path' => $this->relativePath,
                ]);
                return null;
            }
            $i++;
        }
        return null;
    }

    private function namedLiteral(Node $args, string $name): ?string
    {
        foreach ($args as $argName => $child) {
            if ($argName === $name && $child instanceof ConstantExpression && is_string($child->getAttribute('value'))) {
                return $child->getAttribute('value');
            }
        }
        return null;
    }

    /**
     * @return list<ScannedKey>
     */
    public function keys(): array
    {
        return $this->keys;
    }
}
