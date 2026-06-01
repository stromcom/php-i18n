<?php

declare(strict_types=1);

namespace Stromcom\I18n\Scan;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Minimal Twig extension used **only by the scanner** — registers the `t()`
 * function so the Twig parser does not fail with "Unknown function". The
 * scanner never actually invokes the call (the visitor works on the AST before evaluation).
 */
final class ScannerStubExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('t', static fn (): string => ''),
            new TwigFunction('locale_switch_url', static fn (): string => ''),
        ];
    }
}
