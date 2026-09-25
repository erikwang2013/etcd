<?php

declare(strict_types=1);

namespace Erikwang2013\Etcd\Tests\Unit\Election;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Erikwang2013\Etcd\EtcdClient;
use Erikwang2013\Etcd\Election\ElectionClient;
use Erikwang2013\Etcd\Exception\EtcdException;
use Erikwang2013\Etcd\Tests\Support\FakeTransport;
use Erikwang2013\Etcd\Support\WatchHandle;

class ElectionClientTest extends TestCase
{
    /** @param array<string, mixed> $overrides */
    private static function leaderResponse(array $overrides = []): array
    {
        return array_replace([
            'header' => ['revision' => '7'],
            'leader' => [
                'name'  => base64_encode('locks/a'),
                'key'   => base64_encode('locks/a/6a6ca0d9'),
                'rev'   => '7',
                'lease' => '42',
            ],
        ], $overrides);
    }

    /** The same leader as /v3/election/leader reports it: a KeyValue, no name. */
    private static function kvResponse(?string $value = 'owner-1'): array
    {
        return [
            'header' => ['revision' => '9'],
            'kv'     => [
                'key'             => base64_encode('locks/a/6a6ca0d9'),
                'create_revision' => '7',
                'mod_revision'    => '12',
                'version'         => '2',
                'value'           => base64_encode((string) $value),
                'lease'           => '42',
            ],
        ];
    }

    #[Test]
    public function campaignSendsBase64NameValueAndLease(): void
    {
        $t = new FakeTransport();
        $t->addResponse(self::leaderResponse());
        $client = new ElectionClient($t);

        $result = $client->campaign('locks/a', 'owner-1', 42, 30.0);

        $this->assertSame('/v3/election/campaign', $t->sent[0][0]);
        $this->assertSame([
            'name'  => base64_encode('locks/a'),
            'value' => base64_encode('owner-1'),
            'lease' => 42,
        ], $t->sent[0][1]);
        // The campaign is the one call whose wait is the election itself, so the
        // per-call timeout has to reach the transport.
        $this->assertSame(30.0, $t->timeouts[0]);

        $this->assertSame(['revision' => '7'], $result['header']);
        $this->assertSame('locks/a', $result['name']);
        $this->assertSame('locks/a/6a6ca0d9', $result['key']);
        $this->assertSame(7, $result['rev']);
        $this->assertSame(42, $result['lease']);
        // LeaderKey carries no value; what the caller campaigned with is what the
        // leader key now holds.
        $this->assertSame('owner-1', $result['value']);
    }

    #[Test]
    public function campaignKeepsALeaseIdTooLargeForAnIntAsAString(): void
    {
        $t = new FakeTransport();
        $t->addResponse(self::leaderResponse(['leader' => [
            'name'  => base64_encode('locks/a'),
            'key'   => base64_encode('locks/a/6a6ca0d9'),
            'rev'   => '7',
            'lease' => '10276657743932975437',
        ]]));
        $client = new ElectionClient($t);

        $result = $client->campaign('locks/a', 'owner-1', '10276657743932975437');

        $this->assertSame('10276657743932975437', $t->sent[0][1]['lease']);
        $this->assertSame('10276657743932975437', $result['lease']);
    }

    #[Test]
    public function leaderDecodesTheKvAndKeepsTheCreateRevision(): void
    {
        $t = new FakeTransport();
        $t->addResponse(self::kvResponse());
        $client = new ElectionClient($t);

        $leader = $client->leader('locks/a');

        $this->assertSame('/v3/election/leader', $t->sent[0][0]);
        $this->assertSame(['name' => base64_encode('locks/a')], $t->sent[0][1]);
        $this->assertSame('locks/a', $leader['name']);
        $this->assertSame('locks/a/6a6ca0d9', $leader['key']);
        // create_revision (7), not mod_revision (12): resign/proclaim fence on the
        // revision the election was won at.
        $this->assertSame(7, $leader['rev']);
        $this->assertSame(42, $leader['lease']);
        $this->assertSame('owner-1', $leader['value']);
    }

    #[Test]
    public function leaderIsNullWhenTheElectionHasNoLeader(): void
    {
        $t = new FakeTransport();
        // etcd's answer to "who leads an election nobody won": HTTP 500.
        $t->sendException = new EtcdException('etcd server error: /v3/election/leader: election: no leader');
        $client = new ElectionClient($t);

        $this->assertNull($client->leader('locks/a'));
    }

    #[Test]
    public function leaderIsNullWhenTheResponseHasNoKv(): void
    {
        $t = new FakeTransport();
        $t->addResponse(['header' => ['revision' => '9']]);

        $this->assertNull((new ElectionClient($t))->leader('locks/a'));
    }

    #[Test]
    public function leaderPropagatesOtherFailures(): void
    {
        $t = new FakeTransport();
        $t->sendException = new EtcdException('etcd server error: /v3/election/leader: something else');
        $client = new ElectionClient($t);

        $this->expectException(EtcdException::class);
        $this->expectExceptionMessage('something else');
        $client->leader('locks/a');
    }

    #[Test]
    public function proclaimSendsTheWholeDescriptor(): void
    {
        $t = new FakeTransport();
        $t->addResponse(['header' => ['revision' => '10']]);
        $client = new ElectionClient($t);

        $result = $client->proclaim('owner-2', [
            'name'  => 'locks/a',
            'key'   => 'locks/a/6a6ca0d9',
            'rev'   => 7,
            'lease' => 42,
        ]);

        $this->assertSame('/v3/election/proclaim', $t->sent[0][0]);
        $this->assertSame([
            'leader' => [
                'name'  => base64_encode('locks/a'),
                'key'   => base64_encode('locks/a/6a6ca0d9'),
                'rev'   => 7,
                'lease' => 42,
            ],
            'value'  => base64_encode('owner-2'),
        ], $t->sent[0][1]);
        $this->assertSame(['revision' => '10'], $result['header']);
    }

    #[Test]
    public function resignSendsNameKeyAndRev(): void
    {
        $t = new FakeTransport();
        $t->addResponse(['header' => ['revision' => '11']]);
        $client = new ElectionClient($t);

        $result = $client->resign([
            'name' => 'locks/a',
            'key'  => 'locks/a/6a6ca0d9',
            'rev'  => 7,
        ]);

        $this->assertSame('/v3/election/resign', $t->sent[0][0]);
        $this->assertSame(['leader' => [
            'name' => base64_encode('locks/a'),
            'key'  => base64_encode('locks/a/6a6ca0d9'),
            'rev'  => 7,
        ]], $t->sent[0][1]);
        $this->assertSame(['revision' => '11'], $result['header']);
    }

    #[Test]
    public function resignDescriptorWithoutRevIsRefusedNotSent(): void
    {
        $t = new FakeTransport();
        $client = new ElectionClient($t);

        // etcd answers 200 to this and leaves the leader in place (measured), so
        // the request must never leave the client.
        try {
            $client->resign(['name' => 'locks/a', 'key' => 'locks/a/6a6ca0d9']);
            $this->fail('an incomplete descriptor should be refused');
        } catch (EtcdException $e) {
            $this->assertStringContainsString('name, key and rev', $e->getMessage());
        }

        $this->assertSame([], $t->sent);
    }

    #[Test]
    public function proclaimDescriptorMissingNameIsRefused(): void
    {
        $t = new FakeTransport();
        $client = new ElectionClient($t);

        $this->expectException(EtcdException::class);
        try {
            $client->proclaim('v', ['key' => 'locks/a/6a6ca0d9', 'rev' => 7]);
        } finally {
            $this->assertSame([], $t->sent);
        }
    }

    #[Test]
    public function observeReportsTheLeaderThenOnlyChanges(): void
    {
        $t = new FakeTransport();
        $t->addResponse(self::kvResponse('owner-1'));   // initial state
        $t->addResponse(self::kvResponse('owner-1'));   // a second candidate joined the queue
        $t->addResponse(self::kvResponse('owner-2'));   // it won
        $t->watchEventBatches = [
            [['type' => 'PUT', 'kv' => []]],
            [['type' => 'DELETE', 'kv' => []]],
        ];

        $seen = [];
        (new ElectionClient($t))->observe('locks/a', static function (?array $leader) use (&$seen): void {
            $seen[] = $leader['value'] ?? null;
        }, ['prevKv' => true]);

        $this->assertSame(['owner-1', 'owner-2'], $seen);
        $this->assertSame('locks/a', $t->watchCalls[0][0]);
        $this->assertSame(EtcdClient::prefixToRangeEnd('locks/a'), $t->watchCalls[0][1]);
        // header.revision of the first read (9) + 1: a leader elected between the
        // read and the watch is replayed, not missed.
        $this->assertSame(10, $t->watchCalls[0][2]);
        $this->assertSame(['prevKv' => true], $t->watchCalls[0][3]);
    }

    #[Test]
    public function observeReportsAnEmptyElectionAsNull(): void
    {
        $t = new FakeTransport();
        $t->sendException = new EtcdException('etcd server error: /v3/election/leader: election: no leader');

        $seen = [];
        (new ElectionClient($t))->observe('locks/a', static function (?array $leader) use (&$seen): void {
            $seen[] = $leader;
        });

        $this->assertSame([null], $seen);
        $this->assertSame(1, count($t->watchCalls));
        // No leader means the lookup answered with an error and no revision, so
        // there is nothing to resume from: the watch starts from the current one.
        $this->assertSame(0, $t->watchCalls[0][2]);
    }

    #[Test]
    public function observeStopsOnAWatchHandle(): void
    {
        $t = new FakeTransport();
        $t->addResponse(self::kvResponse());
        $handle = new WatchHandle();
        $handle->cancel();

        $seen = [];
        (new ElectionClient($t))->observe('locks/a', static function () use (&$seen): void {
            $seen[] = true;
        }, ['handle' => $handle]);

        // The handle reaches the transport, which is where the loop is stopped.
        $this->assertSame($handle, $t->watchCalls[0][3]['handle']);
    }

    #[Test]
    public function transportExceptionPropagates(): void
    {
        $t = new FakeTransport();
        $t->sendException = new \RuntimeException('boom');
        $client = new ElectionClient($t);

        $this->expectException(\RuntimeException::class);
        $client->campaign('locks/a', 'owner-1', 42);
    }
}
