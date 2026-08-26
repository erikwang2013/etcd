<?php

declare(strict_types=1);

namespace Erikwang2013\Etcd\Tests\Unit\Watch;

use Erikwang2013\Etcd\Watch\WatchClient;
use Erikwang2013\Etcd\Tests\Support\FakeTransport;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class WatchClientTest extends TestCase
{
    #[Test]
    public function watchForwardsKeyRangeEndAndDefaultOptions(): void
    {
        $transport = new FakeTransport();
        $client = new WatchClient($transport);

        $client->watch('foo', function (array $events): void {});

        $this->assertCount(1, $transport->watchCalls);
        [$key, $rangeEnd, $startRevision, $options] = $transport->watchCalls[0];
        $this->assertSame('foo', $key);
        $this->assertSame('', $rangeEnd);
        $this->assertSame(0, $startRevision);
        $this->assertSame([], $options);
    }

    #[Test]
    public function watchForwardsRangeEndAndStartRevision(): void
    {
        $transport = new FakeTransport();
        $client = new WatchClient($transport);

        $client->watch('foo', function (array $events): void {}, [
            'rangeEnd' => 'foo0',
            'startRevision' => 42,
        ]);

        [$key, $rangeEnd, $startRevision] = $transport->watchCalls[0];
        $this->assertSame('foo', $key);
        $this->assertSame('foo0', $rangeEnd);
        $this->assertSame(42, $startRevision);
    }

    #[Test]
    public function watchSetsPrevKvOptionWhenRequested(): void
    {
        $transport = new FakeTransport();
        $client = new WatchClient($transport);

        $client->watch('foo', function (array $events): void {}, ['prevKv' => true]);

        $options = $transport->watchCalls[0][3];
        $this->assertSame(['prevKv' => true], $options);
    }

    #[Test]
    public function watchSetsProgressNotifyOnlyWhenTrue(): void
    {
        $transport = new FakeTransport();
        $client = new WatchClient($transport);

        $client->watch('a', function (array $events): void {}, ['progressNotify' => false]);
        $client->watch('b', function (array $events): void {}, ['progressNotify' => true]);

        $this->assertSame([], $transport->watchCalls[0][3]);
        $this->assertSame(['progressNotify' => true], $transport->watchCalls[1][3]);
    }

    #[Test]
    public function watchInvokesCallbackWithEachEventBatch(): void
    {
        $transport = new FakeTransport();
        $transport->watchEventBatches = [
            [['type' => 'PUT', 'kv' => ['key' => 'foo', 'value' => '1']]],
            [['type' => 'PUT', 'kv' => ['key' => 'foo', 'value' => '2']]],
        ];
        $client = new WatchClient($transport);

        $received = [];
        $client->watch('foo', function (array $events) use (&$received): void {
            $received[] = $events;
        });

        $this->assertSame($transport->watchEventBatches, $received);
    }

    #[Test]
    public function watchPrefixUsesPrefixAsKeyAndComputedRangeEnd(): void
    {
        $transport = new FakeTransport();
        $client = new WatchClient($transport);

        $client->watchPrefix('foo/', function (array $events): void {});

        [$key, $rangeEnd] = $transport->watchCalls[0];
        $this->assertSame('foo/', $key);
        $this->assertSame('foo0', $rangeEnd);
    }

    #[Test]
    public function watchPrefixWithEmptyPrefixUsesNullRangeEnd(): void
    {
        $transport = new FakeTransport();
        $client = new WatchClient($transport);

        $client->watchPrefix('', function (array $events): void {});

        [$key, $rangeEnd] = $transport->watchCalls[0];
        $this->assertSame('', $key);
        $this->assertSame("\x00", $rangeEnd);
    }
}
