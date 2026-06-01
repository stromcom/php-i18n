<?php

declare(strict_types=1);

namespace Stromcom\I18n\Build;

final class TranslatorClientException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $statusCode = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
