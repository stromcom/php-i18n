<?php

declare(strict_types=1);

namespace Stromcom\I18n\Tests\Support;

/**
 * One request captured by {@see HttpRecorder}, with typed accessors for the parts tests
 * assert on — Symfony hands the options over as an untyped array.
 */
final readonly class RecordedRequest
{
    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        public string $method,
        public string $url,
        public array $options,
    ) {}

    /**
     * @return list<string> Headers as `Name: value` lines.
     */
    public function headers(): array
    {
        $headers = $this->options['headers'] ?? [];
        if (!is_array($headers)) {
            return [];
        }
        $out = [];
        foreach ($headers as $header) {
            if (is_string($header)) {
                $out[] = $header;
            }
        }
        return $out;
    }

    public function body(): string
    {
        $body = $this->options['body'] ?? '';
        return is_string($body) ? $body : '';
    }

    public function hasHeaderNamed(string $name): bool
    {
        $prefix = strtolower($name) . ':';
        foreach ($this->headers() as $header) {
            if (str_starts_with(strtolower($header), $prefix)) {
                return true;
            }
        }
        return false;
    }
}
