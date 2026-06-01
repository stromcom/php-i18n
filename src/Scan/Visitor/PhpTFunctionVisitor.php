<?php

declare(strict_types=1);

namespace Stromcom\I18n\Scan\Visitor;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeVisitorAbstract;
use Psr\Log\LoggerInterface;
use Stromcom\I18n\Scan\ScannedKey;

/**
 * AST visitor for `$translator->trans('key', 'default', note: 'context')` calls.
 *
 * Matching strategy: any method call with `->trans(literal, literal[, ...])`,
 * where both the 1st and 2nd argument are `Node\Scalar\String_`. No type inference on
 * `$translator` — if the project had another method with the same name on a different
 * class, the method used here can be renamed via the PhpScanner constructor.
 *
 * Note: the visitor is stateful (accumulates `$keys`), do not reuse it.
 */
final class PhpTFunctionVisitor extends NodeVisitorAbstract
{
    /** @var list<ScannedKey> */
    private array $keys = [];

    public function __construct(
        private readonly string $methodName,
        private readonly string $relativePath,
        private readonly LoggerInterface $logger,
    ) {}

    public function enterNode(Node $node): null
    {
        if (!$node instanceof MethodCall) {
            return null;
        }
        if (!$node->name instanceof Identifier || $node->name->toString() !== $this->methodName) {
            return null;
        }

        $positional = [];
        $named = [];
        foreach ($node->args as $arg) {
            if (!$arg instanceof Arg) {
                return null;
            }
            if ($arg->name instanceof Identifier) {
                $named[$arg->name->toString()] = $arg;
            } else {
                $positional[] = $arg;
            }
        }

        if (count($positional) < 2) {
            return null;
        }

        $keyArg = $positional[0]->value;
        $defaultArg = $positional[1]->value;

        if (!$keyArg instanceof String_) {
            $this->logger->info('[i18n] PhpScanner: skipping ->trans() with non-literal key', [
                'path' => $this->relativePath,
                'line' => $node->getStartLine(),
            ]);
            return null;
        }
        if (!$defaultArg instanceof String_) {
            $this->logger->info('[i18n] PhpScanner: skipping ->trans() with non-literal default', [
                'path' => $this->relativePath,
                'line' => $node->getStartLine(),
                'key'  => $keyArg->value,
            ]);
            return null;
        }

        $description = null;
        if (isset($named['note']) && $named['note']->value instanceof String_) {
            $description = $named['note']->value->value;
        }

        $this->keys[] = new ScannedKey(
            name: $keyArg->value,
            sourceText: $defaultArg->value,
            description: $description,
            occurrences: [$this->relativePath . ':' . $node->getStartLine()],
        );

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
