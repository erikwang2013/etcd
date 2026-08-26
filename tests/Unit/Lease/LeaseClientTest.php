<?php

declare(strict_types=1);

namespace Erikwang2013\Etcd\Tests\Unit\Lease;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Erikwang2013\Etcd\Lease\LeaseClient;
use Erikwang2013\Etcd\Exception\EtcdException;
use Erikwang2013\Etcd\Tests\Support\FakeTransport;

class LeaseClientTest extends TestCase
{
    #[Test]
    public function grantSendsTtlWithoutIdWhenZero(): void
    {
        $t = new FakeTransport();
        $t->addResponse(['ID' => '123', 'TTL' => '60']);
        $client = new LeaseClient($t);

        $result = $client->grant(60);

        $this->assertSame('/v3/lease/grant', $t->sent[0][0]);
        $this->assertSame(['TTL' => 60], $t->sent[0][1]);
        $this->assertSame(123, $result['ID']);
        $this->assertSame(60, $result['TTL']);
        $this->assertSame([], $result['header']);
    }

    #[Test]
    public function grantSendsIdWhenPositive(): void
    {
        $t = new FakeTransport();
        $t->addResponse([]);
        $client = new LeaseClient($t);

        $client->grant(60, 999);

        $this->assertSame(['TTL' => 60, 'ID' => 999], $t->sent[0][1]);
    }

    #[Test]
    public function grantFallsBackTtlAndIdWhenMissing(): void
    {
        $t = new FakeTransport();
        $t->addResponse(['header' => ['revision' => 1]]);
        $client = new LeaseClient($t);

        $result = $client->grant(45);

        $this->assertSame(0, $result['ID']);
        $this->assertSame(45, $result['TTL']);
        $this->assertSame(['revision' => 1], $result['header']);
    }

    #[Test]
    public function revokeSendsIdAndPassesHeader(): void
    {
        $t = new FakeTransport();
        $t->addResponse(['header' => ['revision' => 2]]);
        $client = new LeaseClient($t);

        $result = $client->revoke(42);

        $this->assertSame('/v3/lease/revoke', $t->sent[0][0]);
        $this->assertSame(['ID' => 42], $t->sent[0][1]);
        $this->assertSame(['revision' => 2], $result['header']);
    }

    #[Test]
    public function keepAliveReturnsIdAndTtl(): void
    {
        $t = new FakeTransport();
        $t->addResponse(['ID' => '7', 'TTL' => '30', 'header' => ['revision' => 3]]);
        $client = new LeaseClient($t);

        $result = $client->keepAlive(7);

        $this->assertSame('/v3/lease/keepalive', $t->sent[0][0]);
        $this->assertSame(['ID' => 7], $t->sent[0][1]);
        $this->assertSame(7, $result['ID']);
        $this->assertSame(30, $result['TTL']);
        $this->assertSame(['revision' => 3], $result['header']);
    }

    #[Test]
    public function keepAliveThrowsWhenLeaseExpired(): void
    {
        $t = new FakeTransport();
        $t->addResponse(['ID' => 0, 'TTL' => 0]);
        $client = new LeaseClient($t);

        $this->expectException(EtcdException::class);
        $client->keepAlive(7);
    }

    #[Test]
    public function timeToLiveSendsIdAndKeysFlag(): void
    {
        $t = new FakeTransport();
        $t->addResponse([]);
        $client = new LeaseClient($t);

        $client->timeToLive(9, true);

        $this->assertSame('/v3/lease/timetolive', $t->sent[0][0]);
        $this->assertSame(['ID' => 9, 'keys' => true], $t->sent[0][1]);
    }

    #[Test]
    public function timeToLiveDecodesKeysAndCastsFields(): void
    {
        $t = new FakeTransport();
        $t->addResponse([
            'ID' => '9',
            'TTL' => '5',
            'grantedTTL' => '60',
            'keys' => [base64_encode('中文key'), 'not-base64!!'],
        ]);
        $client = new LeaseClient($t);

        $result = $client->timeToLive(9, true);

        $this->assertSame(9, $result['ID']);
        $this->assertSame(5, $result['TTL']);
        $this->assertSame(60, $result['grantedTTL']);
        $this->assertSame(['中文key', 'not-base64!!'], $result['keys']);
    }

    #[Test]
    public function listCastsLeaseIds(): void
    {
        $t = new FakeTransport();
        $t->addResponse([
            'header' => ['revision' => 4],
            'leases' => [['ID' => '11'], ['ID' => '22']],
        ]);
        $client = new LeaseClient($t);

        $result = $client->list();

        $this->assertSame('/v3/lease/leases', $t->sent[0][0]);
        $this->assertSame([], $t->sent[0][1]);
        $this->assertSame(['revision' => 4], $result['header']);
        $this->assertSame([['ID' => 11], ['ID' => 22]], $result['leases']);
    }

    #[Test]
    public function listHandlesEmptyLeases(): void
    {
        $t = new FakeTransport();
        $t->addResponse([]);
        $client = new LeaseClient($t);

        $result = $client->list();

        $this->assertSame([], $result['leases']);
    }

    #[Test]
    public function transportExceptionPropagates(): void
    {
        $t = new FakeTransport();
        $t->sendException = new \RuntimeException('boom');
        $client = new LeaseClient($t);

        $this->expectException(\RuntimeException::class);
        $client->grant(60);
    }
}
