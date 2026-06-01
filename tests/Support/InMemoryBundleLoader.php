<?php

declare(strict_types=1);

namespace Stromcom\I18n\Tests\Support;

use Stromcom\I18n\Runtime\BundleLoaderInterface;

final class InMemoryBundleLoader implements BundleLoaderInterface
{
    /**
     * @param array<string, array<string, string>> $bundles
     */
    public function __construct(private readonly array $bundles) {}

    public function load(string $locale): array
    {
        return $this->bundles[$locale] ?? [];
    }
}
