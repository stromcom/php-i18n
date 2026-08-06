<?php

declare(strict_types=1);

namespace Stromcom\I18n\Tests\Support;

use Psr\Log\AbstractLogger;

/**
 * PSR-3 logger that keeps every record, so tests can assert on the diagnostics a
 * scanner emits. The warnings are part of the scanner contract — an `<i18n:t>` that
 * cannot be synced must say so rather than vanish — so they need to be assertable.
 */
final class CollectingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /**
     * @param mixed              $level
     * @param string|\Stringable $message
     * @param array<mixed>       $context
     */
    public function log($level, $message, array $context = []): void
    {
        /** @var array<string, mixed> $context */
        $this->records[] = [
            'level'   => is_string($level) ? $level : (string) json_encode($level),
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    /**
     * @return list<string> Messages of the given level, in order.
     */
    public function messages(?string $level = null): array
    {
        $out = [];
        foreach ($this->records as $record) {
            if ($level === null || $record['level'] === $level) {
                $out[] = $record['message'];
            }
        }
        return $out;
    }

    public function hasRecordContaining(string $level, string $needle): bool
    {
        foreach ($this->messages($level) as $message) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array<string, mixed> Context of the first record whose message contains $needle.
     */
    public function contextOfFirstContaining(string $needle): array
    {
        foreach ($this->records as $record) {
            if (str_contains($record['message'], $needle)) {
                return $record['context'];
            }
        }
        return [];
    }
}
