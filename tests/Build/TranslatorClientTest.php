<?php

declare(strict_types=1);

namespace Stromcom\I18n\Tests\Build;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stromcom\I18n\Build\TranslatorClient;
use Stromcom\I18n\Build\TranslatorClientException;
use Stromcom\I18n\Config\I18nConfig;
use Stromcom\I18n\Tests\Support\HttpRecorder;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

#[CoversClass(TranslatorClient::class)]
#[CoversClass(TranslatorClientException::class)]
final class TranslatorClientTest extends TestCase
{
    private HttpRecorder $recorder;

    protected function setUp(): void
    {
        $this->recorder = new HttpRecorder();
    }

    private function config(string $baseUrl = 'https://translator.test', string $token = 'secret'): I18nConfig
    {
        return new I18nConfig(
            projectId: 'proj',
            token: $token,
            baseUrl: $baseUrl,
            sourceLocale: 'en',
            targetLocales: ['en'],
            fallbackLocale: 'en',
            bundlesDir: '/tmp',
            scanPaths: [],
        );
    }

    /**
     * Records every outgoing request and replies with the given responses in order.
     *
     * @param list<MockResponse> $responses
     */
    private function client(array $responses, ?I18nConfig $config = null): TranslatorClient
    {
        $mock = $this->recorder->client(
            static fn (): MockResponse => array_shift($responses) ?? new MockResponse('{}'),
        );

        return new TranslatorClient($config ?? $this->config(), $mock);
    }

    public function testPostJsonSendsTheEncodedBodyToTheResolvedUrl(): void
    {
        $client = $this->client([new MockResponse('{"ok":true}', ['http_code' => 200])]);

        $response = $client->postJson('api/v1/projects/proj/keys/sync', ['keys' => [['name' => 'a']]]);

        self::assertSame(200, $response->getStatusCode());
        $request = $this->recorder->last();
        self::assertSame('POST', $request->method);
        self::assertSame('https://translator.test/api/v1/projects/proj/keys/sync', $request->url);
        self::assertSame('{"keys":[{"name":"a"}]}', $request->body());
    }

    public function testGetSendsTheQueryString(): void
    {
        $client = $this->client([new MockResponse('{}', ['http_code' => 200])]);

        $client->get('api/v1/projects/proj/bundles/cs', ['version' => 'draft']);

        $request = $this->recorder->last();
        self::assertSame('GET', $request->method);
        self::assertSame('https://translator.test/api/v1/projects/proj/bundles/cs?version=draft', $request->url);
    }

    public function testBearerTokenAndAcceptHeaderAreApplied(): void
    {
        $client = $this->client([new MockResponse('{}')]);

        $client->get('api/v1/ping');

        $headers = $this->recorder->last()->headers();
        self::assertContains('Authorization: Bearer secret', $headers);
        self::assertContains('Accept: application/json', $headers);
    }

    public function testExtraHeadersAreMergedIn(): void
    {
        $client = $this->client([new MockResponse('{}')]);

        $client->get('api/v1/projects/proj/bundles/cs', [], ['If-None-Match' => '"etag-value"']);

        self::assertContains('If-None-Match: "etag-value"', $this->recorder->last()->headers());
    }

    public function testBaseUrlTrailingSlashIsNormalised(): void
    {
        $client = $this->client([new MockResponse('{}')], $this->config(baseUrl: 'https://translator.test/'));

        $client->get('api/v1/ping');

        self::assertSame('https://translator.test/api/v1/ping', $this->recorder->last()->url);
    }

    public function testLeadingSlashInPathDoesNotEscapeTheBaseUrl(): void
    {
        // ltrim() keeps the path relative, otherwise '/api' would resolve against the host root
        // and silently drop any base path.
        $client = $this->client([new MockResponse('{}')], $this->config(baseUrl: 'https://translator.test/api-root'));

        $client->get('/api/v1/ping');

        self::assertSame('https://translator.test/api-root/api/v1/ping', $this->recorder->last()->url);
    }

    public function testNonSuccessStatusIsReturnedRatherThanThrown(): void
    {
        // The client is a thin transport; interpreting the status is the caller's job.
        $client = $this->client([new MockResponse('nope', ['http_code' => 500])]);

        $response = $client->postJson('api/v1/x', []);

        self::assertSame(500, $response->getStatusCode());
    }

    public function testTransportFailureOnGetBecomesTranslatorClientException(): void
    {
        $mock = new MockHttpClient(static function (): ResponseInterface {
            throw new \Symfony\Component\HttpClient\Exception\TransportException('connection refused');
        });
        $client = new TranslatorClient($this->config(), $mock);

        $this->expectException(TranslatorClientException::class);
        $this->expectExceptionMessage('Translator GET failed: connection refused');
        $client->get('api/v1/ping');
    }

    public function testTransportFailureOnPostBecomesTranslatorClientException(): void
    {
        $mock = new MockHttpClient(static function (): ResponseInterface {
            throw new \Symfony\Component\HttpClient\Exception\TransportException('dns failure');
        });
        $client = new TranslatorClient($this->config(), $mock);

        $this->expectException(TranslatorClientException::class);
        $this->expectExceptionMessage('Translator POST failed: dns failure');
        $client->postJson('api/v1/x', []);
    }

    public function testTransportExceptionIsKeptAsThePrevious(): void
    {
        $mock = new MockHttpClient(static function (): ResponseInterface {
            throw new \Symfony\Component\HttpClient\Exception\TransportException('boom');
        });
        $client = new TranslatorClient($this->config(), $mock);

        try {
            $client->get('api/v1/ping');
            self::fail('Expected TranslatorClientException');
        } catch (TranslatorClientException $e) {
            self::assertInstanceOf(
                \Symfony\Component\HttpClient\Exception\TransportException::class,
                $e->getPrevious(),
            );
        }
    }
}
