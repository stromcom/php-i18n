<?php

declare(strict_types=1);

namespace Stromcom\I18n\Build;

final readonly class SyncResult
{
    /**
     * @param list<string> $stale
     */
    public function __construct(
        public int $added,
        public int $updated,
        public array $stale,
        public int $totalInSync,
        public int $sent,
    ) {}
}
