<?php

declare(strict_types=1);

namespace Erikwang2013\Etcd\Tests\Unit\Maintenance;

use Erikwang2013\Etcd\Maintenance\MaintenanceClient;
use Erikwang2013\Etcd\Tests\Support\FakeTransport;
use Erikwang2013\Etcd\Exception\EtcdException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class MaintenanceClientTest extends TestCase
{
    #[Test]
    public function statusSendsToStatusPath(): void
    {
        $transport = new FakeTransport();
        $client = new MaintenanceClient($transport);

        $client->status();

        $this->assertSame(['/v3/maintenance/status', []], $transport->sent[0]);
    }

    #[Test]
    public function statusCastsNumericFieldsAndPassesThrough(): void
    {
        $transport = new FakeTransport();
        $transport->addResponse([
            'header' => ['revision' => 9],
            'version' => '3.5.0',
            'dbSize' => '123',
            'leader' => '7',
            'raftIndex' => '100',
            'raftTerm' => '2',
            'raftAppliedIndex' => '99',
            'errors' => [],
        ]);
        $client = new MaintenanceClient($transport);

        $result = $client->status();

        $this->assertSame(['revision' => 9], $result['header']);
        $this->assertSame('3.5.0', $result['version']);
        $this->assertSame(123, $result['dbSize']);
        $this->assertSame(7, $result['leader']);
        $this->assertSame(100, $result['raftIndex']);
        $this->assertSame(2, $result['raftTerm']);
        $this->assertSame(99, $result['raftAppliedIndex']);
        $this->assertSame([], $result['errors']);
    }

    #[Test]
    public function statusThrowsOnNonEmptyErrors(): void
    {
        $transport = new FakeTransport();
        $transport->addResponse(['errors' => ['db is corrupted', 'snapshot count mismatch']]);
        $client = new MaintenanceClient($transport);

        $this->expectException(EtcdException::class);
        $this->expectExceptionMessage('db is corrupted; snapshot count mismatch');

        $client->status();
    }

    #[Test]
    public function alarmSendsDefaultBody(): void
    {
        $transport = new FakeTransport();
        $transport->addResponse(['header' => ['revision' => 1], 'alarms' => []]);
        $client = new MaintenanceClient($transport);

        $client->alarm();

        $this->assertSame(['/v3/maintenance/alarm', ['action' => 0]], $transport->sent[0]);
    }

    #[Test]
    public function alarmIncludesMemberIdAndAlarmOnlyWhenPositive(): void
    {
        $transport = new FakeTransport();
        $transport->addResponse([]);
        $client = new MaintenanceClient($transport);

        $client->alarm(1, 2, 3);

        $this->assertSame(
            ['/v3/maintenance/alarm', ['action' => 1, 'memberID' => 3, 'alarm' => 2]],
            $transport->sent[0]
        );
    }

    #[Test]
    public function alarmPassesThroughAlarms(): void
    {
        $transport = new FakeTransport();
        $alarms = [['memberID' => 3, 'alarm' => 1]];
        $transport->addResponse(['header' => ['revision' => 5], 'alarms' => $alarms]);
        $client = new MaintenanceClient($transport);

        $result = $client->alarm();

        $this->assertSame(['revision' => 5], $result['header']);
        $this->assertSame($alarms, $result['alarms']);
    }

    #[Test]
    public function defragmentSendsToDefragmentPath(): void
    {
        $transport = new FakeTransport();
        $transport->addResponse(['header' => ['revision' => 3]]);
        $client = new MaintenanceClient($transport);

        $result = $client->defragment();

        $this->assertSame(['/v3/maintenance/defragment', []], $transport->sent[0]);
        $this->assertSame(['revision' => 3], $result['header']);
    }

    #[Test]
    public function hashIncludesRevisionOnlyWhenPositive(): void
    {
        $transport = new FakeTransport();
        $transport->addResponse(['hash' => 42]);
        $transport->addResponse(['hash' => 42]);
        $client = new MaintenanceClient($transport);

        $client->hash();
        $client->hash(7);

        $this->assertSame(['/v3/maintenance/hash', []], $transport->sent[0]);
        $this->assertSame(['/v3/maintenance/hash', ['revision' => 7]], $transport->sent[1]);
    }

    #[Test]
    public function hashThrowsWhenFieldMissing(): void
    {
        $transport = new FakeTransport();
        $transport->addResponse(['header' => ['revision' => 1]]);
        $client = new MaintenanceClient($transport);

        $this->expectException(EtcdException::class);
        $this->expectExceptionMessage('hash field missing in response');

        $client->hash();
    }

    #[Test]
    public function hashCastsToInt(): void
    {
        $transport = new FakeTransport();
        $transport->addResponse(['hash' => '12345']);
        $client = new MaintenanceClient($transport);

        $result = $client->hash();

        $this->assertSame(12345, $result['hash']);
    }

    #[Test]
    public function snapshotUsesSendRawAndReturnsRawString(): void
    {
        $transport = new FakeTransport();
        $transport->rawResponse = "raw-binary-data\x00\x01";
        $client = new MaintenanceClient($transport);

        $result = $client->snapshot();

        $this->assertSame(['/v3/maintenance/snapshot', []], $transport->sent[0]);
        $this->assertSame("raw-binary-data\x00\x01", $result);
    }

    #[Test]
    public function sendExceptionIsPropagated(): void
    {
        $transport = new FakeTransport();
        $transport->sendException = new EtcdException('boom');
        $client = new MaintenanceClient($transport);

        $this->expectException(EtcdException::class);
        $this->expectExceptionMessage('boom');

        $client->status();
    }
}
