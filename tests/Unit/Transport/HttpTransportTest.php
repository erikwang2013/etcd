<?php

declare(strict_types=1);

namespace Erikwang2013\Etcd\Tests\Unit\Transport;

use Erikwang2013\Etcd\Exception\AuthException;
use Erikwang2013\Etcd\Exception\ConnectionException;
use Erikwang2013\Etcd\Exception\EtcdException;
use Erikwang2013\Etcd\Tests\Support\PsrHttpMocks;
use Erikwang2013\Etcd\Transport\HttpTransport;
use PHPUnit\Framework\TestCase;

// watch() not unit-tested: it loops forever (while(true)) with real fopen()/stream_select() and cannot be terminated from tests.
class HttpTransportTest extends TestCase
{
    private function transport(array $config = []): HttpTransport
    {
        return new HttpTransport(['127.0.0.1:2379'], array_merge(['retry' => 0], $config));
    }

    public function testConstructRejectsPlaintextAuth(): void
    {
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Refusing to send etcd credentials over plaintext HTTP');
        $this->transport(['auth' => ['user' => 'u', 'password' => 'p']]);
    }

    public function testConstructAllowsHttpsAuth(): void
    {
        $transport = $this->transport(['scheme' => 'https', 'auth' => ['user' => 'u', 'password' => 'p']]);
        self::assertInstanceOf(HttpTransport::class, $transport);
    }

    public function testSendSendsPostRequestWithHeadersAndBody(): void
    {
        $mocks = new PsrHttpMocks();
        $mocks->queue(200, '{"header":{}}');
        $transport = $this->transport();
        $transport->setHttpClient($mocks->client(), $mocks->requestFactory(), $mocks->streamFactory());

        $body = ['key' => base64_encode('foo'), 'value' => base64_encode('bar')];
        $transport->send('/v3/kv/put', $body);

        [$method, $uri, $headers, $sentBody] = $mocks->lastRequest();
        self::assertSame('POST', $method);
        self::assertSame('http://127.0.0.1:2379/v3/kv/put', $uri);
        self::assertSame('application/json', $headers['Content-Type']);
        self::assertSame(json_encode($body, JSON_UNESCAPED_SLASHES), $sentBody);
    }

    // etcd v3 does not accept HTTP Basic: it wants a token from
    // /v3/auth/authenticate, sent bare (no "Bearer"). Basic was only ever
    // accepted up to 3.4, so the old assertion here described a broken client.
    public function testSendExchangesCredentialsForATokenAndSendsItBare(): void
    {
        $mocks = new PsrHttpMocks();
        $mocks->queue(200, '{"token":"tok-123"}');   // /v3/auth/authenticate
        $mocks->queue(200, '{"header":{}}');         // the request itself
        $transport = $this->transport(['scheme' => 'https', 'auth' => ['user' => 'u', 'password' => 'p']]);
        $transport->setHttpClient($mocks->client(), $mocks->requestFactory(), $mocks->streamFactory());

        $transport->send('/v3/kv/put', ['key' => 'x']);

        [, , $headers] = $mocks->lastRequest();
        self::assertSame('tok-123', $headers['Authorization']);
        self::assertStringNotContainsString('Basic', $headers['Authorization']);

        // and the token is cached, not re-fetched per request
        $mocks->queue(200, '{"header":{}}');
        $transport->send('/v3/kv/put', ['key' => 'y']);
        [, , $headers] = $mocks->lastRequest();
        self::assertSame('tok-123', $headers['Authorization']);
    }

    public function testSendDecodesJsonResponse(): void
    {
        $mocks = new PsrHttpMocks();
        $mocks->queue(200, '{"header":{"revision":1}}');
        $transport = $this->transport();
        $transport->setHttpClient($mocks->client(), $mocks->requestFactory(), $mocks->streamFactory());

        self::assertSame(['header' => ['revision' => 1]], $transport->send('/v3/kv/range', ['key' => 'x']));
    }

    public function testSendThrowsAuthExceptionOn401(): void
    {
        $mocks = new PsrHttpMocks();
        $mocks->queue(401, '{}');
        $transport = $this->transport();
        $transport->setHttpClient($mocks->client(), $mocks->requestFactory(), $mocks->streamFactory());

        $this->expectException(AuthException::class);
        $transport->send('/v3/kv/put', ['key' => 'x']);
    }

    public function testSendThrowsEtcdExceptionWithServerMessageOn400(): void
    {
        $mocks = new PsrHttpMocks();
        $mocks->queue(400, '{"message":"bad request"}');
        $transport = $this->transport();
        $transport->setHttpClient($mocks->client(), $mocks->requestFactory(), $mocks->streamFactory());

        $this->expectException(EtcdException::class);
        $this->expectExceptionMessage('bad request');
        $transport->send('/v3/kv/put', ['key' => 'x']);
    }

    public function testSendThrowsEtcdExceptionOnInvalidJson(): void
    {
        $mocks = new PsrHttpMocks();
        $mocks->queue(200, 'not-json');
        $transport = $this->transport();
        $transport->setHttpClient($mocks->client(), $mocks->requestFactory(), $mocks->streamFactory());

        $this->expectException(EtcdException::class);
        $this->expectExceptionMessage('Invalid JSON response from etcd');
        $transport->send('/v3/kv/put', ['key' => 'x']);
    }

    public function testSendWrapsClientFailureInConnectionException(): void
    {
        $mocks = new PsrHttpMocks();
        $mocks->exception = new \RuntimeException('boom');
        $transport = $this->transport();
        $transport->setHttpClient($mocks->client(), $mocks->requestFactory(), $mocks->streamFactory());

        try {
            $transport->send('/v3/kv/put', ['key' => 'x']);
            self::fail('Expected ConnectionException');
        } catch (ConnectionException $e) {
            self::assertInstanceOf(\RuntimeException::class, $e->getPrevious());
            self::assertStringContainsString('HTTP client failure', $e->getMessage());
            self::assertStringContainsString('boom', $e->getMessage());
        }
    }

    public function testSendRawReturnsBodyOn200(): void
    {
        $mocks = new PsrHttpMocks();
        $mocks->queue(200, 'raw-body');
        $transport = $this->transport();
        $transport->setHttpClient($mocks->client(), $mocks->requestFactory(), $mocks->streamFactory());

        self::assertSame('raw-body', $transport->sendRaw('/v3/lease/leases'));
    }

    public function testSendRawThrowsAuthExceptionOn401(): void
    {
        $mocks = new PsrHttpMocks();
        $mocks->queue(401, '{}');
        $transport = $this->transport();
        $transport->setHttpClient($mocks->client(), $mocks->requestFactory(), $mocks->streamFactory());

        $this->expectException(AuthException::class);
        $transport->sendRaw('/v3/lease/leases');
    }

    public function testSendRawThrowsEtcdExceptionOnHttpError(): void
    {
        $mocks = new PsrHttpMocks();
        $mocks->queue(500, 'server error');
        $transport = $this->transport();
        $transport->setHttpClient($mocks->client(), $mocks->requestFactory(), $mocks->streamFactory());

        $this->expectException(EtcdException::class);
        $this->expectExceptionMessage('HTTP 500');
        $transport->sendRaw('/v3/lease/leases');
    }

    // --- native driver selection: curl, then PHP's stream wrapper, then advice ---

    public function testDetectDriverPrefersCurlWhenAvailable(): void
    {
        self::assertSame(HttpTransport::DRIVER_CURL, HttpTransport::detectDriver(true, true));
        self::assertSame(HttpTransport::DRIVER_CURL, HttpTransport::detectDriver(true, false));
    }

    public function testDetectDriverFallsBackToTheStreamWrapper(): void
    {
        self::assertSame(HttpTransport::DRIVER_STREAM, HttpTransport::detectDriver(false, true));
    }

    public function testDetectDriverReportsNoneWhenNeitherIsAvailable(): void
    {
        self::assertSame('none', HttpTransport::detectDriver(false, false));
    }

    public function testDetectDriverReadsTheLiveRuntimeByDefault(): void
    {
        $expected = \function_exists('curl_init')
            ? HttpTransport::DRIVER_CURL
            : (ini_get('allow_url_fopen') ? HttpTransport::DRIVER_STREAM : 'none');

        self::assertSame($expected, HttpTransport::detectDriver());
    }

    public function testUnknownDriverValueFallsBackToAutoDetection(): void
    {
        self::assertSame(HttpTransport::detectDriver(), $this->transport(['driver' => 'nope'])->driver());
    }

    public function testExplicitCurlDriverIsAcceptedOrExplained(): void
    {
        if (\function_exists('curl_init')) {
            self::assertSame(HttpTransport::DRIVER_CURL, $this->transport(['driver' => 'curl'])->driver());
            return;
        }
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('ext-curl is not loaded');
        $this->transport(['driver' => 'curl'])->driver();
    }

    public function testExplicitStreamDriverNeedsAllowUrlFopen(): void
    {
        if (!ini_get('allow_url_fopen')) {
            $this->expectException(ConnectionException::class);
            $this->expectExceptionMessage('allow_url_fopen is disabled');
            $this->transport(['driver' => 'stream'])->driver();
            return;
        }
        self::assertSame(HttpTransport::DRIVER_STREAM, $this->transport(['driver' => 'stream'])->driver());
    }
}
