<?php

declare(strict_types=1);

namespace Stromcom\I18n\Runtime;

final class MissingTranslationException extends \RuntimeException
{
    public function __construct(public readonly string $key, public readonly string $locale)
    {
        parent::__construct(sprintf('Missing translation for key "%s" in locale "%s".', $key, $locale));
    }
}
