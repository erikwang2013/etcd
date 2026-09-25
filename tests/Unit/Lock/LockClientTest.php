<?php

declare(strict_types=1);

namespace Erikwang2013\Etcd\Tests\Unit\Lock;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Erikwang2013\Etcd\Exception\EtcdException;
use Erikwang2013\Etcd\Lock\LockClient;
use Erikwang2013\Etcd\Tests\Support\FakeTransport;

/**
 * FakeTransport's response list scripts successes only, and a failing campaign
 * is exactly what acquire() has to clean up after.
 */
final class FailingTransport extends FakeTransport
{
    public function __construct(private readonly int $failOn, private readonly string $message = 'boom')
    {
    }

    public function send(string $path, array $body, ?float $timeout = null): array
    {
        if (count($this->sent) + 1 === $this->failOn) {
            $this->sent[] = [$path, $body];
            $this->timeouts[] = $timeout;
            throw new EtcdException($this->message);
        }

        return parent::send($path, $body, $timeout);
    }
}

class LockClientTest extends TestCase
{
    private static function campaignResponse(): array
    {
        return [
            'header' => ['revision' => '7'],
            'leader' => [
                'name'  => base64_encode('locks/a/'),
                'key'   => base64_encode('locks/a/6a6ca0d9'),
                'rev'   => '7',
                'lease' => '42',
            ],
        ];
    }

    #[Test]
    public function acquireGrantsALeaseThenCampaingsOnThePrefix(): void
    {
        $t = new FakeTransport();
        $t->addResponse(['ID' => '42', 'TTL' => '30']);   // lease grant
        $t->addResponse(self::campaignResponse());        // campaign
        $client = new LockClient($t);

        $lock = $client->acquire('locks/a/', 30, 10.0);

        $this->assertSame(['/v3/lease/grant', ['TTL' => 30]], $t->sent[0]);
        $this->assertSame('/v3/election/campaign', $t->sent[1][0]);
        $this->assertSame(base64_encode('locks/a/'), $t->sent[1][1]['name']);
        $this->assertSame(42, $t->sent[1][1]['lease']);
        $this->assertSame(10.0, $t->timeouts[1]);

        // The owner is generated per acquisition; the lock reports back the same
        // string that was campaigned with.
        $owner = base64_decode($t->sent[1][1]['value'], true);
        $this->assertNotSame('', $owner);
        $this->assertSame($owner, $lock['owner']);
        $this->assertSame($owner, $lock['value']);

        $this->assertSame('locks/a/', $lock['name']);
        $this->assertSame('locks/a/6a6ca0d9', $lock['key']);
        $this->assertSame(7, $lock['rev']);
        $this->assertSame(42, $lock['lease']);
    }

    #[Test]
    public function acquireOwnersAreUnique(): void
    {
        $t = new FakeTransport();
        for ($i = 0; $i < 2; $i++) {
            $t->addResponse(['ID' => (string) (42 + $i), 'TTL' => '30']);
            $t->addResponse(self::campaignResponse());
        }
        $client = new LockClient($t);

        $first = $client->acquire('locks/a/');
        $second = $client->acquire('locks/a/');

        $this->assertNotSame($first['owner'], $second['owner']);
    }

    #[Test]
    public function acquireRevokesTheLeaseWhenTheCampaignFails(): void
    {
        // 1 = grant, 2 = campaign (fails), 3 = the revoke that cleans up after it.
        $t = new FailingTransport(2);
        $t->addResponse(['ID' => '42', 'TTL' => '30']);
        $client = new LockClient($t);

        try {
            $client->acquire('locks/a/');
            $this->fail('acquire() should report the failed campaign');
        } catch (EtcdException $e) {
            $this->assertStringContainsString('boom', $e->getMessage());
        }

        // A campaign that won without its response arriving would otherwise leave
        // the caller holding a lock it never heard about; the lease must not
        // outlive the attempt either way.
        $this->assertSame(['/v3/lease/revoke', ['ID' => 42]], $t->sent[2]);
    }

    #[Test]
    public function releaseResignsThenRevokes(): void
    {
        $t = new FakeTransport();
        $t->addResponse([]);   // resign
        $t->addResponse([]);   // revoke
        $client = new LockClient($t);

        $client->release([
            'name'  => 'locks/a/',
            'key'   => 'locks/a/6a6ca0d9',
            'rev'   => 7,
            'lease' => 42,
            'owner' => 'host-1-abc',
        ]);

        $this->assertSame('/v3/election/resign', $t->sent[0][0]);
        $this->assertSame(['leader' => [
            'name' => base64_encode('locks/a/'),
            'key'  => base64_encode('locks/a/6a6ca0d9'),
            'rev'  => 7,
        ]], $t->sent[0][1]);
        $this->assertSame(['/v3/lease/revoke', ['ID' => 42]], $t->sent[1]);
    }

    #[Test]
    public function releaseAcceptsALeaseThatHasAlreadyExpired(): void
    {
        // 1 = resign (succeeds), 2 = revoke (the lease is gone by now).
        $t = new FailingTransport(2, 'etcd error: /v3/lease/revoke: etcdserver: requested lease not found');
        $t->addResponse(['header' => ['revision' => '3']]);
        $client = new LockClient($t);

        $client->release(['name' => 'locks/a/', 'key' => 'locks/a/6a6ca0d9', 'rev' => 7, 'lease' => 42]);

        $this->assertSame('/v3/lease/revoke', $t->sent[1][0]);
    }

    #[Test]
    public function releaseStillReportsOtherRevokeFailures(): void
    {
        $t = new FailingTransport(2, 'etcd server error: /v3/lease/revoke: etcdserver: no leader');
        $t->addResponse([]);
        $client = new LockClient($t);

        $this->expectException(EtcdException::class);
        $this->expectExceptionMessage('no leader');
        $client->release(['name' => 'locks/a/', 'key' => 'locks/a/6a6ca0d9', 'rev' => 7, 'lease' => 42]);
    }

    #[Test]
    public function releaseRefusesADescriptorWithoutALease(): void
    {
        $t = new FakeTransport();
        $client = new LockClient($t);

        try {
            $client->release(['name' => 'locks/a/', 'key' => 'locks/a/6a6ca0d9', 'rev' => 7]);
            $this->fail('a lock without a lease should be refused');
        } catch (EtcdException $e) {
            $this->assertStringContainsString('acquire()', $e->getMessage());
        }

        $this->assertSame([], $t->sent);
    }

    #[Test]
    public function leaderReportsTheCurrentHolder(): void
    {
        $t = new FakeTransport();
        $t->addResponse([
            'kv' => [
                'key'             => base64_encode('locks/a/6a6ca0d9'),
                'create_revision' => '7',
                'value'           => base64_encode('host-2-def'),
                'lease'           => '77',
            ],
        ]);
        $client = new LockClient($t);

        $holder = $client->leader('locks/a/');

        $this->assertSame('/v3/election/leader', $t->sent[0][0]);
        $this->assertSame('host-2-def', $holder['value']);
        // Same shape as acquire() returns, so callers read 'owner' in both.
        $this->assertSame('host-2-def', $holder['owner']);
        $this->assertSame(77, $holder['lease']);
    }

    #[Test]
    public function leaderIsNullWhileTheLockIsFree(): void
    {
        $t = new FakeTransport();
        $t->sendException = new EtcdException('etcd server error: /v3/election/leader: election: no leader');

        $this->assertNull((new LockClient($t))->leader('locks/a/'));
    }
}
