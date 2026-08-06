<?php

declare(strict_types=1);

namespace Stromcom\I18n\Tests\Scan;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stromcom\I18n\Scan\ScannerStubExtension;
use Twig\Environment;
use Twig\Error\SyntaxError;
use Twig\Loader\ArrayLoader;
use Twig\TwigFunction;

/**
 * The stub exists so `TwigScanner` can *parse* templates calling `t()` without the real
 * runtime extension. Its job is to keep the parser quiet, not to translate.
 */
#[CoversClass(ScannerStubExtension::class)]
final class ScannerStubExtensionTest extends TestCase
{
    public function testRegistersTheFunctionsTheRuntimeExtensionProvides(): void
    {
        $names = array_map(
            static fn (TwigFunction $f): string => $f->getName(),
            (new ScannerStubExtension())->getFunctions(),
        );

        self::assertSame(['t', 'locale_switch_url'], $names);
    }

    private function parseEnvironment(bool $withStub): Environment
    {
        $env = new Environment(new ArrayLoader([
            'page' => '{{ t("some.key", "Some default") }}{{ locale_switch_url("cs") }}',
        ]), ['cache' => false]);
        if ($withStub) {
            $env->addExtension(new ScannerStubExtension());
        }

        return $env;
    }

    public function testTemplateUsingTCannotBeParsedWithoutTheStub(): void
    {
        // This is the failure the stub exists to prevent.
        $env = $this->parseEnvironment(withStub: false);

        $this->expectException(SyntaxError::class);
        $env->parse($env->tokenize($env->getLoader()->getSourceContext('page')));
    }

    public function testTemplateUsingTParsesOnceTheStubIsRegistered(): void
    {
        // tokenize + parse is exactly what TwigScanner does before walking the AST.
        $env = $this->parseEnvironment(withStub: true);

        $ast = $env->parse($env->tokenize($env->getLoader()->getSourceContext('page')));

        self::assertSame('page', $ast->getTemplateName());
    }

    public function testTheStubbedFunctionsReturnEmptyStringsWhenCalled(): void
    {
        // The scanner works on the AST and never evaluates these, but a consumer that does
        // render with the stub must not get a fatal — just empty output.
        $env = new Environment(new ArrayLoader([
            'page' => '[{{ t("k", "d") }}][{{ locale_switch_url("cs") }}]',
        ]), ['cache' => false]);
        $env->addExtension(new ScannerStubExtension());

        self::assertSame('[][]', $env->render('page'));
    }
}
