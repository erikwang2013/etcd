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

    public function testSendAddsAuthorizationHeaderWhenHttpsAuth(): void
    {
        $mocks = new PsrHttpMocks();
        $mocks->queue(200, '{"header":{}}');
        $transport = $this->transport(['scheme' => 'https', 'auth' => ['user' => 'u', 'password' => 'p']]);
        $transport->setHttpClient($mocks->client(), $mocks->requestFactory(), $mocks->streamFactory());

        $transport->send('/v3/kv/put', ['key' => 'x']);

        [, , $headers] = $mocks->lastRequest();
        self::assertSame('Basic ' . base64_encode('u:p'), $headers['Authorization']);
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
            self::assertStringContainsString('Failed to connect to etcd', $e->getMessage());
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
}
