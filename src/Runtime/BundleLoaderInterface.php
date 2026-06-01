<?php

declare(strict_types=1);

namespace Stromcom\I18n\Runtime;

interface BundleLoaderInterface
{
    /**
     * @return array<string, string>  flat map key → translated text
     */
    public function load(string $locale): array;
}
