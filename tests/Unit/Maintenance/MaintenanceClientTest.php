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
    /** Measured on etcd 3.5.17: larger than PHP_INT_MAX, so an (int) cast saturates it. */
    private const MEMBER_ID = '10276657743932975437';

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
    public function statusKeepsUint64IdsThatDoNotFitInAnIntAsStrings(): void
    {
        $transport = new FakeTransport();
        $transport->addResponse([
            'version' => '3.5.17',
            'dbSize'  => '876544',
            'leader'  => self::MEMBER_ID,
        ]);
        $client = new MaintenanceClient($transport);

        $result = $client->status();

        // PHP_INT_MAX here would name a member that does not exist.
        $this->assertSame(self::MEMBER_ID, $result['leader']);
        $this->assertSame(876544, $result['dbSize']);
    }

    #[Test]
    public function statusKeepsAnIdOfExactlyPhpIntMaxAsAnInt(): void
    {
        $transport = new FakeTransport();
        $transport->addResponse(['leader' => (string) PHP_INT_MAX]);
        $client = new MaintenanceClient($transport);

        $this->assertSame(PHP_INT_MAX, $client->status()['leader']);
    }

    #[Test]
    public function statusDefaultsTheFieldsTheGatewayOmits(): void
    {
        $transport = new FakeTransport();
        // proto3 drops zero values: a healthy empty member answers with almost nothing.
        $transport->addResponse(['version' => '3.5.17']);
        $client = new MaintenanceClient($transport);

        $result = $client->status();

        $this->assertSame(0, $result['leader']);
        $this->assertSame(0, $result['dbSize']);
        $this->assertSame(0, $result['dbSizeInUse']);
        $this->assertSame([], $result['errors']);
        $this->assertFalse($result['isLearner']);
    }

    #[Test]
    public function statusExposesDbSizeInUseAndIsLearner(): void
    {
        $transport = new FakeTransport();
        $transport->addResponse([
            'dbSize'      => '876544',
            'dbSizeInUse' => '856064',
            'isLearner'   => true,
        ]);
        $client = new MaintenanceClient($transport);

        $result = $client->status();

        $this->assertSame(856064, $result['dbSizeInUse']);
        $this->assertTrue($result['isLearner']);
    }

    #[Test]
    public function statusReportsErrorsInsteadOfThrowing(): void
    {
        $transport = new FakeTransport();
        $transport->addResponse([
            'version' => '3.5.17',
            'errors'  => ['db is corrupted', 'snapshot count mismatch'],
        ]);
        $client = new MaintenanceClient($transport);

        // etcd answers 200 with the problem list; a caller asking for health has
        // to be able to read it, so `errors` cannot be a thrown exception.
        $result = $client->status();

        $this->assertSame(['db is corrupted', 'snapshot count mismatch'], $result['errors']);
        $this->assertSame('3.5.17', $result['version']);
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
    public function alarmAcceptsAnEnumNameAndAStringMemberId(): void
    {
        $transport = new FakeTransport();
        $transport->addResponse([]);
        $client = new MaintenanceClient($transport);

        // jsonpb takes either notation; a uint64 member id may only exist as a string.
        $client->alarm(1, 'NOSPACE', self::MEMBER_ID);

        $this->assertSame(
            ['/v3/maintenance/alarm', ['action' => 1, 'memberID' => self::MEMBER_ID, 'alarm' => 'NOSPACE']],
            $transport->sent[0]
        );
    }

    #[Test]
    public function alarmNormalisesTheWireAlarmType(): void
    {
        $transport = new FakeTransport();
        // measured on 3.5.17: the enum travels as its name, memberID is omitted at 0
        $transport->addResponse([
            'header' => ['revision' => 5],
            'alarms' => [
                ['alarm' => 'NOSPACE'],
                ['memberID' => self::MEMBER_ID, 'alarm' => 'CORRUPT'],
                ['alarm' => 2],
                ['alarm' => 'FUTURE_ALARM'],
            ],
        ]);
        $client = new MaintenanceClient($transport);

        $result = $client->alarm();

        $this->assertSame(['revision' => 5], $result['header']);
        $this->assertSame([
            ['memberID' => 0, 'alarm' => 1, 'name' => 'NOSPACE'],
            ['memberID' => self::MEMBER_ID, 'alarm' => 2, 'name' => 'CORRUPT'],
            ['memberID' => 0, 'alarm' => 2, 'name' => 'CORRUPT'],
            ['memberID' => 0, 'alarm' => -1, 'name' => 'FUTURE_ALARM'],
        ], $result['alarms']);
    }

    #[Test]
    public function alarmWithoutAlarmsReturnsAnEmptyList(): void
    {
        $transport = new FakeTransport();
        $transport->addResponse(['header' => ['revision' => 1]]);
        $client = new MaintenanceClient($transport);

        $this->assertSame([], $client->alarm()['alarms']);
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
    public function slowCallsPassTheirTimeoutToTheTransport(): void
    {
        $transport = new class extends FakeTransport {
            /** @var list<list<mixed>> */
            public array $args = [];

            public function send(string $path, array $body, ?float $timeout = null): array
            {
                $this->args[] = func_get_args();
                return parent::send($path, $body);
            }

            public function sendRaw(string $path, ?float $timeout = null): string
            {
                $this->args[] = func_get_args();
                return parent::sendRaw($path);
            }
        };
        $client = new MaintenanceClient($transport);

        // a snapshot/defrag of a real database outlives the default timeout
        $client->defragment(120.0);
        $client->snapshot(600.0);

        $this->assertSame([120.0], array_slice($transport->args[0], 2));
        $this->assertSame([600.0], array_slice($transport->args[1], 1));

        // omitted: the 5s point-query timeout is wrong for a database dump
        $client->defragment();
        $client->snapshot();
        $this->assertSame([300.0], array_slice($transport->args[2], 2));
        $this->assertSame([300.0], array_slice($transport->args[3], 1));
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
