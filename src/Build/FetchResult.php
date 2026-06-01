<?php

declare(strict_types=1);

namespace Stromcom\I18n\Build;

final readonly class FetchResult
{
    /**
     * @param list<string> $missingKeys
     */
    public function __construct(
        public string $locale,
        public int $status,
        public bool $written,
        public array $missingKeys,
    ) {}

    public function isOk(): bool
    {
        return $this->status === 200 || $this->status === 304;
    }
}
