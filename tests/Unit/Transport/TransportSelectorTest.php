<?php

declare(strict_types=1);

namespace Erikwang2013\Etcd\Tests\Unit\Transport;

use Erikwang2013\Etcd\Exception\ConnectionException;
use Erikwang2013\Etcd\Transport\GrpcTransport;
use Erikwang2013\Etcd\Transport\HttpTransport;
use Erikwang2013\Etcd\Transport\TransportSelector;
use PHPUnit\Framework\TestCase;

class TransportSelectorTest extends TestCase
{
    public function testAutoSelectsHttpTransport(): void
    {
        self::assertInstanceOf(HttpTransport::class, TransportSelector::select(['transport' => 'auto']));
    }

    public function testHttpSelectsHttpTransport(): void
    {
        self::assertInstanceOf(HttpTransport::class, TransportSelector::select(['transport' => 'http']));
    }

    public function testGrpcSelectsGrpcTransport(): void
    {
        self::assertInstanceOf(GrpcTransport::class, TransportSelector::select([
            'transport' => 'grpc',
            'endpoints' => ['127.0.0.1:2379'],
        ]));
    }

    public function testUnknownTransportThrows(): void
    {
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Unknown transport');
        TransportSelector::select(['transport' => 'unknown', 'endpoints' => ['127.0.0.1:2379']]);
    }

    public function testEmptyEndpointsThrows(): void
    {
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('No etcd endpoints configured');
        TransportSelector::select(['transport' => 'http', 'endpoints' => []]);
    }
}
