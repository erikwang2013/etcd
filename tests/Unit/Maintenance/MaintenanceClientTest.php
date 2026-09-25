<?php

declare(strict_types=1);

namespace Erikwang2013\Etcd\Tests\Unit\Maintenance;

use Erikwang2013\Etcd\Maintenance\MaintenanceClient;
use Erikwang2013\Etcd\Tests\Support\FakeTransport;
use Erikwang2013\Etcd\Exception\ConnectionException;
use Erikwang2013\Etcd\Exception\EtcdException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class MaintenanceClientTest extends TestCase
{
    /** Measured on etcd 3.5.17: larger than PHP_INT_MAX, so an (int) cast saturates it. */
    private const MEMBER_ID = '10276657743932975437';

    private ?string $dir = null;

    protected function tearDown(): void
    {
        if ($this->dir === null) {
            return;
        }
        foreach (scandir($this->dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                unlink($this->dir . '/' . $entry);
            }
        }
        rmdir($this->dir);
        $this->dir = null;
    }

    /** A directory that is emptied after each test, so leftovers are visible. */
    private function destinationDir(): string
    {
        $this->dir ??= sys_get_temp_dir() . '/etcd-snapshot-to-' . bin2hex(random_bytes(6));
        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0700);
        }

        return $this->dir;
    }

    private function destination(): string
    {
        return $this->destinationDir() . '/snap.db';
    }

    /** Every entry in the destination directory, hidden temp files included. */
    private function dirEntries(): array
    {
        return array_values(array_diff(scandir($this->destinationDir()) ?: [], ['.', '..']));
    }

    /**
     * Frames a database body the way the snapshot RPC streams it: the bytes, then
     * the sha256 of everything before them. Measured against etcd 3.5.17 — PHP's
     * own hash() of the body equals the stream's last 32 bytes.
     *
     * @return list<string>
     */
    private static function snapshotStream(string $body, int $frameSize = 0): array
    {
        $stream = $body . hash('sha256', $body, true);

        return $frameSize > 0 ? str_split($stream, $frameSize) : [$stream];
    }

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
        // snapshot() verifies the sha256 trailer, so the stub body must carry one
        $payload = str_repeat("db", 64);
        $transport->rawResponse = $payload . hash('sha256', $payload, true);
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
    // HashRequest declares no revision field (that belongs to HashKVRequest), so
    // the parameter is accepted for compatibility and never put on the wire: the
    // HTTP gateway would ignore it, and a typed message would reject it.
    public function hashNeverSendsTheUndeclaredRevisionField(): void
    {
        $transport = new FakeTransport();
        $transport->addResponse(['hash' => 42]);
        $transport->addResponse(['hash' => 42]);
        $client = new MaintenanceClient($transport);

        $client->hash();
        $client->hash(7);

        $this->assertSame(['/v3/maintenance/hash', []], $transport->sent[0]);
        $this->assertSame(['/v3/maintenance/hash', []], $transport->sent[1]);
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
        // a real snapshot ends with sha256(the payload before it) — snapshot()
        // verifies that trailer, so the fixture has to look like the real thing
        $payload = "raw-binary-data\x00\x01";
        $transport->rawResponse = $payload . hash('sha256', $payload, true);
        $client = new MaintenanceClient($transport);

        $result = $client->snapshot();

        $this->assertSame(['/v3/maintenance/snapshot', []], $transport->sent[0]);
        $this->assertSame($payload . hash('sha256', $payload, true), $result);
    }

    #[Test]
    public function snapshotToWritesEveryBlobAndReturnsTheFileSize(): void
    {
        $path = $this->destination();
        $transport = new FakeTransport();
        // Frames small enough that the trailer straddles blobs, as it does on the wire.
        $transport->streamBlobs = self::snapshotStream('bbolt-database-bytes', 7);
        $client = new MaintenanceClient($transport);

        $bytes = $client->snapshotTo($path);

        $all = implode('', $transport->streamBlobs);
        $this->assertSame(['/v3/maintenance/snapshot', []], $transport->sent[0]);
        $this->assertSame($all, file_get_contents($path));
        $this->assertSame(strlen($all), $bytes);
        $this->assertSame(filesize($path), $bytes);
    }

    #[Test]
    public function snapshotToWritesEachBlobAsItArrivesAndPublishesTheFileOnlyAtTheEnd(): void
    {
        $path = $this->destination();
        $transport = new class($path) extends FakeTransport {
            /** @var list<array{published: bool, tempBytes: int}> */
            public array $during = [];

            public function __construct(private string $dest)
            {
            }

            public function sendStream(string $path, callable $onBlob, ?float $timeout = null): void
            {
                $dir = \dirname($this->dest);
                foreach ($this->streamBlobs as $blob) {
                    $onBlob($blob);
                    $others = array_values(array_diff(scandir($dir) ?: [], ['.', '..', basename($this->dest)]));
                    $temp = $others === [] ? '' : $dir . '/' . $others[0];
                    clearstatcache();       // filesize() would replay its stat cache
                    $this->during[] = [
                        'published' => file_exists($this->dest),
                        'tempBytes' => $temp === '' ? 0 : (int) filesize($temp),
                    ];
                }
            }
        };
        $transport->streamBlobs = self::snapshotStream(str_repeat('x', 300), 100);
        $client = new MaintenanceClient($transport);

        $client->snapshotTo($path);

        // Nothing was held back in memory: after frame N only N frames were on
        // disk, and the destination only appears once the stream has ended.
        $this->assertSame([100, 200, 300, 332], array_column($transport->during, 'tempBytes'));
        $this->assertSame([false, false, false, false], array_column($transport->during, 'published'));
        $this->assertSame([basename($path)], $this->dirEntries());
    }

    #[Test]
    public function snapshotToLeavesNoBackupWhenTheStreamFails(): void
    {
        $path = $this->destination();
        $transport = new class extends FakeTransport {
            public function sendStream(string $path, callable $onBlob, ?float $timeout = null): void
            {
                $onBlob('half-a-database');
                throw new ConnectionException('stream failed: transfer closed with outstanding read data remaining');
            }
        };
        $client = new MaintenanceClient($transport);

        try {
            $client->snapshotTo($path);
            $this->fail('a failed snapshot must not return');
        } catch (ConnectionException $e) {
            $this->assertStringContainsString('outstanding read data', $e->getMessage());
        }

        // Not $path, and not a .temp file either: nothing a backup job could pick up.
        $this->assertSame([], $this->dirEntries());
    }

    #[Test]
    public function snapshotToKeepsThePreviousBackupWhenTheStreamFails(): void
    {
        $path = $this->destination();
        file_put_contents($path, 'yesterdays-good-backup');
        $transport = new class extends FakeTransport {
            public function sendStream(string $path, callable $onBlob, ?float $timeout = null): void
            {
                $onBlob('partial');
                throw new EtcdException('timed out');
            }
        };
        $client = new MaintenanceClient($transport);

        try {
            $client->snapshotTo($path);
            $this->fail('a failed snapshot must not return');
        } catch (EtcdException) {
            // expected
        }

        $this->assertSame('yesterdays-good-backup', file_get_contents($path));
        $this->assertSame([basename($path)], $this->dirEntries());
    }

    #[Test]
    public function snapshotToRefusesAStreamThatEndsWithoutAValidTrailer(): void
    {
        $path = $this->destination();
        $transport = new FakeTransport();
        // A stream cut at a frame boundary and closed cleanly: no transport error
        // to report, and the last 32 bytes are database content, not a hash.
        $transport->streamBlobs = self::snapshotStream(str_repeat('y', 200), 64);
        $transport->streamBlobs = array_slice($transport->streamBlobs, 0, 2);
        $client = new MaintenanceClient($transport);

        $this->expectException(EtcdException::class);
        $this->expectExceptionMessage('without a valid hash trailer');

        try {
            $client->snapshotTo($path);
        } finally {
            $this->assertSame([], $this->dirEntries());
        }
    }

    #[Test]
    public function snapshotToReplacesAnExistingBackup(): void
    {
        $path = $this->destination();
        file_put_contents($path, 'old');
        $transport = new FakeTransport();
        $transport->streamBlobs = self::snapshotStream('new-database');
        $client = new MaintenanceClient($transport);

        $client->snapshotTo($path);

        $this->assertSame(implode('', $transport->streamBlobs), file_get_contents($path));
        $this->assertSame([basename($path)], $this->dirEntries());
    }

    #[Test]
    public function snapshotToDefaultsToTheSameTimeoutsAsSnapshot(): void
    {
        $transport = new FakeTransport();
        $transport->streamBlobs = self::snapshotStream('db');
        $client = new MaintenanceClient($transport);

        $client->snapshotTo($this->destination());
        $client->snapshotTo($this->destination(), 900.0);

        $this->assertSame([300.0, 900.0], $transport->timeouts);
    }

    #[Test]
    public function snapshotToRefusesADestinationDirectoryThatIsNotThere(): void
    {
        $transport = new FakeTransport();
        $transport->streamBlobs = self::snapshotStream('db');
        $client = new MaintenanceClient($transport);

        $this->expectException(EtcdException::class);
        $this->expectExceptionMessage('destination directory is missing or not writable');

        $client->snapshotTo($this->destinationDir() . '/nope/snap.db');
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
