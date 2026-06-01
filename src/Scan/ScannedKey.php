<?php

declare(strict_types=1);

namespace Stromcom\I18n\Scan;

/**
 * A single scan-output record. The `occurrences` array uses the translator API
 * format `"path:line"` (relative to the repo root).
 */
final class ScannedKey
{
    /** @var list<string> */
    public array $occurrences;

    /**
     * @param list<string> $occurrences
     */
    public function __construct(
        public readonly string $name,
        public readonly string $sourceText,
        public readonly ?string $description,
        array $occurrences = [],
    ) {
        $this->occurrences = $occurrences;
    }

    public function addOccurrence(string $occurrence): void
    {
        if (!in_array($occurrence, $this->occurrences, true)) {
            $this->occurrences[] = $occurrence;
        }
    }

    /**
     * @return array{name: string, source_text: string, description?: string, occurrences?: list<string>}
     */
    public function toApiPayload(): array
    {
        $payload = ['name' => $this->name, 'source_text' => $this->sourceText];
        if ($this->description !== null && $this->description !== '') {
            $payload['description'] = $this->description;
        }
        if ($this->occurrences !== []) {
            $payload['occurrences'] = $this->occurrences;
        }
        return $payload;
    }
}
