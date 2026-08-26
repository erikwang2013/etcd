<?php

declare(strict_types=1);

namespace Erikwang2013\Etcd\Tests\Unit\Transport;

use Erikwang2013\Etcd\Exception\ConnectionException;
use Erikwang2013\Etcd\Transport\GrpcTransport;
use PHPUnit\Framework\TestCase;

class GrpcTransportTest extends TestCase
{
    public function testGetCurrentEndpointIsFirstEndpoint(): void
    {
        $transport = new GrpcTransport(['a:2379', 'b:2379']);
        self::assertSame('a:2379', $transport->getCurrentEndpoint());
    }

    public function testSendNotImplemented(): void
    {
        $transport = new GrpcTransport(['127.0.0.1:2379']);
        $this->expectException(ConnectionException::class);
        $transport->send('/v3/kv/put', ['key' => 'x']);
    }

    public function testSendRawNotImplemented(): void
    {
        $transport = new GrpcTransport(['127.0.0.1:2379']);
        $this->expectException(ConnectionException::class);
        $transport->sendRaw('/v3/lease/leases');
    }

    public function testWatchNotImplemented(): void
    {
        $transport = new GrpcTransport(['127.0.0.1:2379']);
        $this->expectException(ConnectionException::class);
        $transport->watch('k', '', 0, static fn () => null);
    }

    public function testGetChannelThrowsWithoutGrpcExtension(): void
    {
        if (extension_loaded('grpc')) {
            self::markTestSkipped('grpc extension loaded on this machine; missing-extension path not testable');
        }
        $transport = new GrpcTransport(['127.0.0.1:2379']);
        $this->expectException(ConnectionException::class);
        $transport->getChannel('127.0.0.1:2379');
    }
}
