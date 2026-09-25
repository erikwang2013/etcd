<?php

declare(strict_types=1);

namespace Erikwang2013\Etcd\Tests\Unit\Transport;

use Erikwang2013\Etcd\Exception\AuthException;
use Erikwang2013\Etcd\Exception\ConnectionException;
use Erikwang2013\Etcd\Exception\EtcdException;
use Erikwang2013\Etcd\Auth\AuthClient;
use Erikwang2013\Etcd\Cluster\ClusterClient;
use Erikwang2013\Etcd\Kv\KvClient;
use Erikwang2013\Etcd\Lease\LeaseClient;
use Erikwang2013\Etcd\Maintenance\MaintenanceClient;
use Erikwang2013\Etcd\Transport\GrpcTransport;
use Erikwang2013\Etcd\Transport\TransportInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What the transport does without a channel: which paths it takes, which it
 * refuses and how loudly, and how a gRPC status becomes the exception the same
 * etcd error raises over HTTP.
 *
 * The channel itself is not testable here — no machine this was written on has
 * ext-grpc — so nothing in this file claims a call has ever completed. See the
 * class docblock: the message-building half is in GeneratedMessagesTest.
 */
class GrpcTransportTest extends TestCase
{
    #[Test]
    public function theEndpointLosesItsSchemeAndPath(): void
    {
        // A channel resolves "host:port"; "http://host:2379/v3" is not one.
        $transport = new GrpcTransport(['http://a:2379/v3', 'b:2379']);

        self::assertSame('a:2379', $transport->getCurrentEndpoint());
    }

    #[Test]
    public function sendSaysWhatIsMissingWithoutExtGrpc(): void
    {
        if (extension_loaded('grpc')) {
            self::markTestSkipped('grpc extension loaded here; the missing-extension path is not reachable');
        }

        $transport = new GrpcTransport(['127.0.0.1:2379']);

        try {
            $transport->send('/v3/kv/put', ['key' => base64_encode('k'), 'value' => base64_encode('v')]);
            self::fail('send() should not have succeeded without ext-grpc');
        } catch (ConnectionException $e) {
            self::assertStringContainsString('gRPC extension not available', $e->getMessage());
            self::assertStringContainsString('pecl install grpc', $e->getMessage());
            self::assertStringContainsString('HTTP transport', $e->getMessage());
        }
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function unsupportedPaths(): array
    {
        return [
            '/v3/watch' => ['/v3/watch', 'Watch/Watch'],
            '/v3/lease/keepalive' => ['/v3/lease/keepalive', 'Lease/LeaseKeepAlive'],
            '/v3/maintenance/snapshot' => ['/v3/maintenance/snapshot', 'Maintenance/Snapshot'],
            '/v3/election/campaign' => ['/v3/election/campaign', 'v3election'],
        ];
    }

    #[Test]
    #[DataProvider('unsupportedPaths')]
    public function aPathWithoutAUnaryRpcIsRefusedByName(string $path, string $what): void
    {
        $transport = new GrpcTransport(['127.0.0.1:2379']);

        foreach ([
            'send' => static fn () => $transport->send($path, []),
            'sendRaw' => static fn () => $transport->sendRaw($path),
            'sendStream' => static fn () => $transport->sendStream($path, static fn () => null),
        ] as $method => $call) {
            try {
                $call();
                self::fail("{$method}('{$path}') should have been refused");
            } catch (ConnectionException $e) {
                self::assertStringContainsString($what, $e->getMessage(), "{$method}: {$path}");
                self::assertStringContainsString("cannot send {$path}", $e->getMessage(), "{$method}: {$path}");
                self::assertStringContainsString('transport: http', $e->getMessage(), "{$method}: {$path}");
            }
        }
    }

    #[Test]
    public function watchNamesItsOwnStreamingRpc(): void
    {
        $transport = new GrpcTransport(['127.0.0.1:2379']);

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessageMatches('/etcdserverpb\.Watch\/Watch is a bidirectional stream.*transport: http/');
        $transport->watch('k', '', 0, static fn () => null);
    }

    #[Test]
    public function anUnknownPathSaysItIsUnmappedRatherThanUnsupported(): void
    {
        $transport = new GrpcTransport(['127.0.0.1:2379']);

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessageMatches('/no method mapped for \/v3\/nope; see GrpcTransport::UNARY/');
        $transport->send('/v3/nope', []);
    }

    #[Test]
    public function sendRawIsOnlyForTheStreamingPaths(): void
    {
        // /v3/lease/leases is a unary RPC: send() takes it, sendRaw() must not
        // quietly fetch its bytes.
        $transport = new GrpcTransport(['127.0.0.1:2379']);

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessageMatches('/no method mapped for \/v3\/lease\/leases/');
        $transport->sendRaw('/v3/lease/leases');
    }

    #[Test]
    public function aBodyTheMessageCannotHoldNamesBothThePathAndTheClass(): void
    {
        if (extension_loaded('grpc')) {
            self::markTestSkipped('with ext-grpc loaded this would reach the channel first');
        }

        $transport = new GrpcTransport(['127.0.0.1:2379']);

        $this->expectException(EtcdException::class);
        $this->expectExceptionMessageMatches('/\/v3\/kv\/put.*PutRequest/');
        $transport->send('/v3/kv/put', ['nope' => 1]);
    }

    #[Test]
    public function aBodyIsRefusedBeforeTheChannelIsOpened(): void
    {
        // The precision check runs before ext-grpc is looked for, so this machine
        // can prove it: the message is about the number, not about the extension.
        $transport = new GrpcTransport(['127.0.0.1:2379']);

        $this->expectException(EtcdException::class);
        $this->expectExceptionMessageMatches('/beyond 2\^53/');
        $transport->send('/v3/cluster/member/remove', ['ID' => 9007199254740993]);
    }

    #[Test]
    public function getChannelThrowsWithoutTheExtension(): void
    {
        if (extension_loaded('grpc')) {
            self::markTestSkipped('grpc extension loaded on this machine; missing-extension path not testable');
        }
        $transport = new GrpcTransport(['127.0.0.1:2379']);
        $this->expectException(ConnectionException::class);
        $transport->getChannel('127.0.0.1:2379');
    }

    /**
     * The mapping this cannot run against a server is the one that decides what a
     * caller catches, so it is checked directly. Codes and their names:
     * 7 PERMISSION_DENIED, 16 UNAUTHENTICATED, 4 DEADLINE_EXCEEDED,
     * 14 UNAVAILABLE, 2 UNKNOWN.
     *
     * @return array<string, array{0: int, 1: class-string<EtcdException>, 2: bool}>
     */
    public static function statusCodes(): array
    {
        return [
            'permission denied' => [7, AuthException::class, false],
            'unauthenticated' => [16, AuthException::class, false],
            'deadline exceeded' => [4, ConnectionException::class, true],
            'unavailable' => [14, ConnectionException::class, true],
            'unknown' => [2, EtcdException::class, false],
        ];
    }

    #[Test]
    #[DataProvider('statusCodes')]
    public function aGrpcStatusBecomesTheSameExceptionFamilyAsOverHttp(int $code, string $class, bool $retryable): void
    {
        $method = new \ReflectionMethod(GrpcTransport::class, 'statusToException');
        $exception = $method->invoke(null, (object) ['code' => $code, 'details' => 'etcdserver: requested lease not found']);

        self::assertInstanceOf($class, $exception);
        self::assertSame($retryable, $exception->isRetryable());
        self::assertStringContainsString('requested lease not found', $exception->getMessage());
    }

    #[Test]
    public function aStatusWithoutUsableDetailsDoesNotSayArray(): void
    {
        $method = new \ReflectionMethod(GrpcTransport::class, 'statusToException');
        $exception = $method->invoke(null, (object) ['code' => 2, 'details' => ['a' => 1]]);

        self::assertStringContainsString('{"a":1}', $exception->getMessage());
    }

    /**
     * The clients, driven for real, with every body they build handed to the real
     * request builder: this is what says the option keys the six clients use are
     * the fields etcd's messages actually have. No server and no channel needed —
     * GrpcTransport::request() throws if a body does not fit its descriptor, and a
     * key spelled differently ("prevKv" where the proto says "prev_kv", a string
     * where an int64 is expected) shows up here rather than as an etcd 400.
     */
    #[Test]
    public function everyBodyTheClientsBuildFitsEtcdsMessages(): void
    {
        $transport = new MessageBuildingTransport();

        $kv = new KvClient($transport);
        $kv->put('k', 'v', ['lease' => 7, 'prevKv' => true, 'ignoreValue' => true, 'ignoreLease' => true]);
        $kv->get('k', [
            'rangeEnd' => 'l', 'limit' => 5, 'revision' => 3,
            'minModRevision' => 1, 'maxModRevision' => 9, 'minCreateRevision' => 1, 'maxCreateRevision' => 9,
            'sortOrder' => 'descend', 'sortTarget' => 'mod',
            'serializable' => true, 'keysOnly' => true, 'countOnly' => true,
        ]);
        $kv->getByPrefix('p/');
        $kv->delete('k', ['rangeEnd' => 'l', 'prevKv' => true]);
        $kv->deleteByPrefix('p/');
        $kv->txn(
            [
                ['result' => 1, 'target' => 1, 'key' => 'k', 'create_revision' => 6],
                ['result' => 3, 'target' => 3, 'key' => 'k2', 'value' => 'cmp'],
                ['result' => 0, 'target' => 4, 'key' => 'k3', 'lease' => 8],
            ],
            [
                ['request_put' => ['key' => 'pk', 'value' => 'pv', 'lease' => 9, 'prevKv' => true]],
                ['request_range' => ['key' => 'rk', 'range_end' => 're']],
                ['request_delete_range' => ['key' => 'dk', 'range_end' => 'de', 'prevKv' => true]],
            ],
            [['request_range' => ['key' => 'fk']]]
        );
        $kv->compact(5, true);

        $lease = new LeaseClient($transport);
        $lease->grant(60);
        $lease->grant(60, 999);
        $lease->revoke(1);
        $lease->keepAlive(1);
        $lease->timeToLive(1);
        $lease->timeToLive(1, true);
        $lease->list();

        $auth = new AuthClient($transport);
        $auth->authenticate('u', 'p');
        $auth->enable();
        $auth->disable();
        $auth->status();
        $auth->user()->add('alice', 'pw');
        $auth->user()->get('alice');
        $auth->user()->list();
        $auth->user()->changePassword('alice', 'np');
        $auth->user()->grantRole('alice', 'admin');
        $auth->user()->revokeRole('alice', 'admin');
        $auth->user()->delete('alice');
        $auth->role()->add('reader');
        $auth->role()->get('reader');
        $auth->role()->list();
        $auth->role()->grantPermission('reader', 2, 'k', 'l');
        $auth->role()->revokePermission('reader', 'k', 'l');
        $auth->role()->delete('reader');

        $cluster = new ClusterClient($transport);
        $cluster->memberAdd(['http://10.0.0.2:2380'], true);
        $cluster->memberRemove('10276657743932975437');
        $cluster->memberUpdate(5, ['http://10.0.0.2:2380']);
        $cluster->memberList();
        $cluster->memberPromote(5);

        $maintenance = new MaintenanceClient($transport);
        $maintenance->status();
        $maintenance->alarm();
        $maintenance->alarm(1, 'NOSPACE', '10276657743932975437');
        $maintenance->defragment();
        $maintenance->hash();
        $maintenance->snapshot();

        $exercised = array_values(array_unique($transport->paths));
        self::assertSame([], array_diff(array_keys(self::constant('UNARY')), $exercised), 'no client call reaches these mapped RPCs');
        self::assertSame([], array_diff($exercised, array_keys(self::constant('UNARY')), array_keys(self::constant('UNSUPPORTED'))), 'a client sent a path the transport cannot name');
    }

    /**
     * The one client body that does not fit its message, pinned so it is not
     * mistaken for a bug in the parser.
     *
     * MaintenanceClient::hash($revision) sends "revision", and etcd's HashRequest
     * is empty in v3.5 — revision belongs to HashKVRequest, a different RPC. The
     * gateway ignores unknown fields (that client docblock measured it), a
     * descriptor-typed message cannot, so this is the one call that works over
     * HTTP and fails over gRPC. Reported to the maintainer: either the client drops
     * the field, or hash() moves to Maintenance/HashKV.
     */
    #[Test]
    public function maintenanceHashNoLongerSendsAnUndeclaredRevision(): void
    {
        // Regression guard for the one call that used to work over HTTP and fail
        // over gRPC: HashRequest has no revision field, so the client must not
        // send one. It builds cleanly against the generated message now.
        $transport = new MessageBuildingTransport();
        $result = (new MaintenanceClient($transport))->hash(5);
        self::assertArrayHasKey('hash', $result);

        // Without a revision it is a plain empty request, and goes through.
        self::assertSame(42, (new MaintenanceClient($transport))->hash()['hash']);
    }

    private static function constant(string $name): array
    {
        return (new \ReflectionClass(GrpcTransport::class))->getConstant($name);
    }
}

/**
 * A TransportInterface the clients talk to normally, except that every body goes
 * through the real request builder first — so a call that does not fit etcd's
 * descriptors fails here, in the test, instead of at the server.
 */
final class MessageBuildingTransport implements TransportInterface
{
    /** @var list<string> */
    public array $paths = [];

    public function send(string $path, array $body, ?float $timeout = null): array
    {
        try {
            GrpcTransport::request($path, $body);
        } catch (ConnectionException $e) {
            // A path the transport refuses by name (a stream) has no request
            // message to check. An unmapped path, or a body that does not fit its
            // descriptor, is the failure this test exists to catch: rethrow.
            if (!str_contains($e->getMessage(), "cannot send {$path}")) {
                throw $e;
            }
        }
        $this->paths[] = $path;

        return match ($path) {
            // The responses a client refuses to continue without. 64-bit fields
            // travel as the strings a gateway would send.
            '/v3/auth/authenticate' => ['header' => [], 'token' => 'test-token'],
            '/v3/maintenance/hash' => ['header' => [], 'hash' => 42],
            '/v3/lease/keepalive' => ['header' => [], 'ID' => '10276657743932975437', 'TTL' => '60'],
            default => ['header' => []],
        };
    }

    public function sendRaw(string $path, ?float $timeout = null): string
    {
        $this->paths[] = $path;

        // A payload with the sha256 trailer snapshot() insists on.
        $payload = 'bbolt-test-dump' . str_repeat("\x00", 32);

        return $payload . hash('sha256', $payload, true);
    }

    public function sendStream(string $path, callable $onBlob, ?float $timeout = null): void
    {
        $this->paths[] = $path;
    }

    public function watch(string $key, string $rangeEnd, int $startRevision, callable $onEvent, array $options = []): void
    {
        $this->paths[] = '/v3/watch';
    }
}
