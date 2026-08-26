<?php

declare(strict_types=1);

namespace Erikwang2013\Etcd\Tests\Unit;

use Erikwang2013\Etcd\Auth\AuthClient;
use Erikwang2013\Etcd\Cluster\ClusterClient;
use Erikwang2013\Etcd\EtcdClient;
use Erikwang2013\Etcd\Kv\KvClient;
use Erikwang2013\Etcd\Lease\LeaseClient;
use Erikwang2013\Etcd\Maintenance\MaintenanceClient;
use Erikwang2013\Etcd\Transport\HttpTransport;
use Erikwang2013\Etcd\Transport\TransportInterface;
use Erikwang2013\Etcd\Watch\WatchClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EtcdClientTest extends TestCase
{
    protected function setUp(): void
    {
        EtcdClient::resetInstance();
    }

    protected function tearDown(): void
    {
        EtcdClient::resetInstance();
    }

    public static function prefixProvider(): array
    {
        return [
            'empty prefix'      => ['', "\x00"],
            'plain ascii'       => ['foo', 'fop'],
            'single 0xff byte'  => ["\xff", "\x00"],
            'all 0xff bytes'    => ["\xff\xff", "\x00"],
            'trailing 0xff'     => ["a\xff", 'b'],
        ];
    }

    #[DataProvider('prefixProvider')]
    public function testPrefixToRangeEnd(string $prefix, string $expected): void
    {
        self::assertSame($expected, EtcdClient::prefixToRangeEnd($prefix));
    }

    public function testInstanceReturnsSameSingleton(): void
    {
        self::assertSame(EtcdClient::instance(), EtcdClient::instance());
    }

    public function testInstanceWithNewConfigAfterInitThrows(): void
    {
        EtcdClient::instance();
        $this->expectException(\LogicException::class);
        EtcdClient::instance(['timeout' => 9.0]);
    }

    public function testInstanceAfterResetAcceptsNewConfig(): void
    {
        $first = EtcdClient::instance();
        EtcdClient::resetInstance();
        $second = EtcdClient::instance(['timeout' => 9.0]);
        self::assertNotSame($first, $second);
        self::assertSame(9.0, $second->config()['timeout']);
    }

    public function testConfigDefaults(): void
    {
        $client = new EtcdClient();
        self::assertSame(
            ['endpoints' => ['127.0.0.1:2379'], 'transport' => 'auto', 'scheme' => 'http', 'timeout' => 5.0, 'retry' => 2],
            $client->config()
        );
    }

    public function testConfigMergesUserOverrides(): void
    {
        $client = new EtcdClient([
            'endpoints' => ['10.0.0.1:2379'],
            'scheme'    => 'https',
            'timeout'   => 1.5,
            'retry'     => 0,
        ]);
        self::assertSame(['10.0.0.1:2379'], $client->config()['endpoints']);
        self::assertSame('https', $client->config()['scheme']);
        self::assertSame(1.5, $client->config()['timeout']);
        self::assertSame(0, $client->config()['retry']);
        self::assertSame('auto', $client->config()['transport']);
    }

    public function testClientsCreatedLazilyAndCached(): void
    {
        $client = new EtcdClient();
        self::assertInstanceOf(KvClient::class, $client->kv());
        self::assertInstanceOf(WatchClient::class, $client->watch());
        self::assertInstanceOf(LeaseClient::class, $client->lease());
        self::assertInstanceOf(AuthClient::class, $client->auth());
        self::assertInstanceOf(ClusterClient::class, $client->cluster());
        self::assertInstanceOf(MaintenanceClient::class, $client->maintenance());
        self::assertSame($client->kv(), $client->kv());
        self::assertSame($client->watch(), $client->watch());
        self::assertSame($client->lease(), $client->lease());
        self::assertSame($client->auth(), $client->auth());
        self::assertSame($client->cluster(), $client->cluster());
        self::assertSame($client->maintenance(), $client->maintenance());
    }

    public function testTransportReturnsTransportInterface(): void
    {
        $client = new EtcdClient();
        self::assertInstanceOf(TransportInterface::class, $client->transport());
        self::assertInstanceOf(HttpTransport::class, $client->transport());
    }
}
