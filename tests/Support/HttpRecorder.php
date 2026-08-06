<?php

declare(strict_types=1);

namespace Stromcom\I18n\Tests\Support;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Builds a `MockHttpClient` that records every outgoing request as a typed
 * {@see RecordedRequest}, so tests can assert on URLs, headers and bodies.
 */
final class HttpRecorder
{
    /** @var list<RecordedRequest> */
    public array $requests = [];

    /**
     * @param \Closure(string, string): MockResponse $responder Receives (method, url).
     */
    public function client(\Closure $responder): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url, array $options) use ($responder): MockResponse {
            /** @var array<string, mixed> $options */
            $this->requests[] = new RecordedRequest($method, $url, $options);

            return $responder($method, $url);
        });
    }

    /**
     * Always replies with the same response.
     */
    public function alwaysReturning(MockResponse $response): MockHttpClient
    {
        return $this->client(static fn (): MockResponse => $response);
    }

    public function first(): RecordedRequest
    {
        return $this->requests[0] ?? throw new \RuntimeException('No request was recorded.');
    }

    public function last(): RecordedRequest
    {
        $last = end($this->requests);
        if ($last === false) {
            throw new \RuntimeException('No request was recorded.');
        }
        return $last;
    }

    public function count(): int
    {
        return count($this->requests);
    }

    /**
     * @return list<string>
     */
    public function urls(): array
    {
        return array_map(static fn (RecordedRequest $r): string => $r->url, $this->requests);
    }
}
