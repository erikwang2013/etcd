<?php

declare(strict_types=1);

namespace Erikwang2013\Etcd\Tests\Unit\Transport;

use Erikwang2013\Etcd\Exception\ConnectionException;
use Erikwang2013\Etcd\Exception\EtcdException;
use Erikwang2013\Etcd\Proto\Etcdserverpb\AlarmRequest;
use Erikwang2013\Etcd\Proto\Etcdserverpb\Member;
use Erikwang2013\Etcd\Proto\Etcdserverpb\MemberListResponse;
use Erikwang2013\Etcd\Proto\Etcdserverpb\PutRequest;
use Erikwang2013\Etcd\Proto\Etcdserverpb\RangeResponse;
use Erikwang2013\Etcd\Proto\Etcdserverpb\TxnRequest;
use Erikwang2013\Etcd\Support\Int64;
use Erikwang2013\Etcd\Support\KeyValue;
use Erikwang2013\Etcd\Transport\GrpcTransport;
use Google\Protobuf\Internal\Message;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The protoc output (protos/generated) and the gateway-path => RPC map: the part
 * of gRPC that bit this project before. The old hand-written src/Protobuf/ stubs
 * parsed nothing and typed 64-bit fields as int, so a uint64 member id came back
 * truncated; everything here runs without ext-grpc, which is why it is worth
 * asserting separately from the transport.
 */
class GeneratedMessagesTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!class_exists(Message::class)) {
            self::markTestSkipped('no protobuf runtime (ext-protobuf or google/protobuf) installed');
        }
    }

    #[Test]
    public function aGeneratedMessageRoundTripsThroughTheWire(): void
    {
        $key = "k\x00\xFFbinary-\x80";
        $value = str_repeat('v', 4096);

        // Built the way the transport builds it, from the JSON the clients send:
        // int64 max has to survive as a string (a JSON number would not).
        $request = GrpcTransport::request('/v3/kv/put', [
            'key' => base64_encode($key),
            'value' => base64_encode($value),
            'lease' => '9223372036854775807',
            'prev_kv' => true,
        ]);
        self::assertInstanceOf(PutRequest::class, $request);

        $bytes = $request->serializeToString();
        self::assertNotSame('', $bytes);

        $decoded = new PutRequest();
        $decoded->mergeFromString($bytes);

        self::assertSame($key, $decoded->getKey());
        self::assertSame($value, $decoded->getValue());
        self::assertTrue($decoded->getPrevKv());
        self::assertSame([
            'key' => base64_encode($key),
            'value' => base64_encode($value),
            'lease' => '9223372036854775807',
            'prev_kv' => true,
        ], GrpcTransport::response($decoded));
    }

    #[Test]
    public function aSixtyFourBitUint64SurvivesAsAString(): void
    {
        // 10276657743932975437 is the member id tests/watch_router.php serves — a
        // uint64 that does not fit a PHP int, which is what the hand-written stubs
        // this transport replaced used to truncate.
        $member = new Member();
        $member->mergeFromJsonString('{"ID":"10276657743932975437","name":"n1"}');
        $response = new MemberListResponse();
        $response->setMembers([$member]);

        $decoded = new MemberListResponse();
        $decoded->mergeFromString($response->serializeToString());
        $out = GrpcTransport::response($decoded);

        self::assertSame('10276657743932975437', $out['members'][0]['ID']);
        self::assertSame('n1', $out['members'][0]['name']);
        // The project's own decoder passes it through untouched (a 20-digit PHP
        // int literal would have become a float before it ever reached the wire).
        self::assertSame('10276657743932975437', Int64::decode($out['members'][0]['ID']));
        self::assertSame(7, Int64::decode(7));

        // The runtime's own getter cannot hold it: ext-protobuf 4.31.1 returns
        // uint64 as a PHP int and wraps (-8170086329776576179 is
        // 10276657743932975437 - 2^64). response() never reads a getter — it
        // serializes — which is exactly why it is written that way. If this
        // assertion ever fails on a newer runtime, the runtime got better: delete
        // the assertion, keep the serializer.
        self::assertSame(-8170086329776576179, $decoded->getMembers()[0]->getID());
    }

    #[Test]
    public function aBodyThatWouldLoseADigitIsRefused(): void
    {
        // Both of these reach the protobuf JSON parser as numbers, and it parses a
        // 64-bit field through a double: measured, 9007199254740993 comes back as
        // ...992 and the float 1.0276657743932979e+19 lands on member
        // 10276657743932979200. That is the wrong member, so it never leaves here.
        try {
            GrpcTransport::request('/v3/cluster/member/remove', ['ID' => 9007199254740993]);
            self::fail('a number beyond 2^53 should be refused');
        } catch (EtcdException $e) {
            self::assertStringContainsString('beyond 2^53', $e->getMessage());
            self::assertStringContainsString('as a string', $e->getMessage());
        }

        try {
            GrpcTransport::request('/v3/cluster/member/remove', ['ID' => 1.0276657743932979e+19]);
            self::fail('a float should be refused');
        } catch (EtcdException $e) {
            self::assertStringContainsString('float', $e->getMessage());
        }

        // What Int64::decode() hands back goes through untouched: a small id stays
        // an int, a big one is a string, and both serialise back to the decimal
        // string the gateway sends.
        foreach ([7, '10276657743932975437'] as $id) {
            $request = GrpcTransport::request('/v3/cluster/member/remove', ['ID' => Int64::decode($id)]);
            self::assertSame(['ID' => (string) $id], GrpcTransport::response($request));
        }
    }

    #[Test]
    public function everyMappedPathHasItsGeneratedMessageClasses(): void
    {
        foreach (self::table() as $path => [$service, $method, $request, $response]) {
            foreach ([$request, $response] as $class) {
                $fqcn = 'Erikwang2013\\Etcd\\Proto\\Etcdserverpb\\' . $class;
                self::assertTrue(class_exists($fqcn), "{$path}: {$fqcn} does not autoload");
                self::assertTrue(is_subclass_of($fqcn, Message::class), "{$path}: {$fqcn} is not a protobuf message");
                // Constructing runs GPBMetadata::initOnce(), which is what puts the
                // descriptor in the pool the wire format is built from.
                new $fqcn();
            }
        }
        self::assertGreaterThanOrEqual(30, count(self::table()));
    }

    #[Test]
    public function theMapAgreesWithRpcProto(): void
    {
        [$services, $messages] = self::parseProto();

        foreach (self::table() as $path => [$service, $method, $request, $response]) {
            self::assertArrayHasKey($service, $services, "{$path}: rpc.proto has no service {$service}");
            self::assertArrayHasKey($method, $services[$service]['methods'], "{$path}: no RPC {$service}.{$method}");
            $rpc = $services[$service]['methods'][$method];
            self::assertFalse($rpc['streaming'], "{$path}: {$service}.{$method} is a stream, not a unary RPC");
            self::assertSame($request, $rpc['request'], "{$path}: {$method} takes {$rpc['request']}");
            self::assertSame($response, $rpc['response'], "{$path}: {$method} returns {$rpc['response']}");
            self::assertArrayHasKey($request, $messages, "{$path}: rpc.proto defines no message {$request}");
            self::assertArrayHasKey($response, $messages, "{$path}: rpc.proto defines no message {$response}");
        }
    }

    #[Test]
    public function streamingPathsAreTheStreamingRpcsAndAreRefusedByName(): void
    {
        [$services] = self::parseProto();
        $unsupported = self::constant('UNSUPPORTED');

        $expected = [
            '/v3/watch' => ['Watch', 'Watch'],
            '/v3/lease/keepalive' => ['Lease', 'LeaseKeepAlive'],
            '/v3/maintenance/snapshot' => ['Maintenance', 'Snapshot'],
        ];

        foreach ($expected as $path => [$service, $method]) {
            self::assertArrayHasKey($service, $services, "rpc.proto has no service {$service}");
            self::assertArrayHasKey($method, $services[$service]['methods'], "rpc.proto has no RPC {$service}.{$method}");
            self::assertTrue($services[$service]['methods'][$method]['streaming'], "{$service}.{$method} is a stream in rpc.proto");
            self::assertArrayHasKey($path, $unsupported, "{$path} should be listed as unsupported");

            try {
                GrpcTransport::request($path, []);
                self::fail("{$path} should not build a unary request");
            } catch (ConnectionException $e) {
                self::assertStringContainsString('unary RPCs only', $e->getMessage());
                self::assertStringContainsString($service . '/' . $method, $e->getMessage());
            }
        }
    }

    #[Test]
    public function electionPathsBelongToOneApiThisTransportDoesNotSpeak(): void
    {
        // etcd's Election service is not in rpc.proto at all — it is the separate
        // v3electionpb proto, which this transport does not vendor. If rpc.proto
        // ever grows it, this test says so instead of quietly 404ing.
        [$services] = self::parseProto();
        self::assertArrayNotHasKey('Election', $services, 'rpc.proto has an Election service now: vendor v3electionpb or map these paths');

        foreach (['/v3/election/campaign', '/v3/election/proclaim', '/v3/election/leader', '/v3/election/resign'] as $path) {
            try {
                GrpcTransport::request($path, ['name' => base64_encode('n')]);
                self::fail("{$path} should not build a request");
            } catch (ConnectionException $e) {
                self::assertStringContainsString('v3election', $e->getMessage(), $path);
                self::assertStringContainsString("cannot send {$path}", $e->getMessage(), $path);
            }
        }
    }

    #[Test]
    public function everyPathTheClientsSendIsMappedOrRefusedByName(): void
    {
        $paths = self::pathsSentByClients();
        self::assertGreaterThanOrEqual(40, count($paths), 'the source scan stopped finding client paths');

        foreach ($paths as $path) {
            try {
                GrpcTransport::request($path, []);
            } catch (ConnectionException $e) {
                // A path the transport forgot would say "has no method mapped"
                // instead; a deliberate refusal says "cannot send {path}".
                self::assertStringContainsString("cannot send {$path}", $e->getMessage(), "{$path} is sent by a client but mapped to no gRPC method");
            }
        }
    }

    #[Test]
    public function aPutBodyLandsOnTheFieldsItNames(): void
    {
        $request = GrpcTransport::request('/v3/kv/put', [
            'key' => base64_encode('中文键'),
            'value' => base64_encode("\x00\x01\xFFbinary"),
            'lease' => '9223372036854775807',
            'prev_kv' => true,
            'ignore_value' => true,
        ]);

        self::assertInstanceOf(PutRequest::class, $request);
        self::assertSame('中文键', $request->getKey());
        self::assertSame("\x00\x01\xFFbinary", $request->getValue());
        self::assertSame(9223372036854775807, $request->getLease());
        self::assertTrue($request->getPrevKv());
        self::assertTrue($request->getIgnoreValue());
        self::assertFalse($request->getIgnoreLease());
    }

    #[Test]
    public function aTxnBodyLandsOnItsNestedOperations(): void
    {
        $request = GrpcTransport::request('/v3/kv/txn', [
            'compare' => [['result' => 'GREATER', 'target' => 'VERSION', 'key' => base64_encode('k'), 'version' => 3]],
            'success' => [['request_put' => ['key' => base64_encode('k'), 'value' => base64_encode('v')]]],
            'failure' => [['request_range' => ['key' => base64_encode('k'), 'range_end' => base64_encode('l'), 'limit' => 5]]],
        ]);

        self::assertInstanceOf(TxnRequest::class, $request);
        self::assertSame(1, $request->getCompare()[0]->getResult());
        self::assertSame(3, (int) $request->getCompare()[0]->getVersion());
        self::assertSame('k', $request->getSuccess()[0]->getRequestPut()->getKey());
        self::assertSame('l', $request->getFailure()[0]->getRequestRange()->getRangeEnd());
        self::assertSame(5, (int) $request->getFailure()[0]->getRequestRange()->getLimit());
    }

    #[Test]
    public function aRoleGrantBodyLandsOnThePermissionMessage(): void
    {
        $request = GrpcTransport::request('/v3/auth/role/grant', [
            'name' => 'reader',
            'perm' => ['permType' => 'READWRITE', 'key' => base64_encode('k'), 'range_end' => base64_encode('l')],
        ]);

        self::assertSame('reader', $request->getName());
        self::assertSame(2, $request->getPerm()->getPermType());
        self::assertSame('k', $request->getPerm()->getKey());
        self::assertSame('l', $request->getPerm()->getRangeEnd());
    }

    #[Test]
    public function anAlarmAcceptsEitherTheEnumNameOrItsNumber(): void
    {
        foreach (['NOSPACE' => 1, 1 => 1] as $alarm => $expected) {
            $request = GrpcTransport::request('/v3/maintenance/alarm', ['action' => 'ACTIVATE', 'alarm' => $alarm]);
            self::assertInstanceOf(AlarmRequest::class, $request);
            self::assertSame($expected, $request->getAlarm());
            self::assertSame(1, $request->getAction());
        }
    }

    #[Test]
    public function camelCaseFieldsTheGatewayUsesAreAccepted(): void
    {
        $request = GrpcTransport::request('/v3/cluster/member/add', [
            'peerURLs' => ['http://10.0.0.2:2380'],
            'isLearner' => true,
        ]);

        self::assertSame(['http://10.0.0.2:2380'], iterator_to_array($request->getPeerURLs()));
        self::assertTrue($request->getIsLearner());
    }

    #[Test]
    public function anEmptyBodyIsAZeroArgumentRequest(): void
    {
        // "{}" not "[]": protojson rejects an array where a message is expected.
        $request = GrpcTransport::request('/v3/lease/leases', []);

        self::assertSame('', $request->serializeToString());
    }

    #[Test]
    public function aFieldTheMessageDoesNotHaveIsReportedWithBothNames(): void
    {
        $this->expectException(EtcdException::class);
        $this->expectExceptionMessageMatches('/\/v3\/kv\/put.*PutRequest/');
        GrpcTransport::request('/v3/kv/put', ['key' => base64_encode('k'), 'nope' => 1]);
    }

    #[Test]
    public function aResponseConvertsIntoTheShapeTheClientsDecode(): void
    {
        $response = new RangeResponse();
        $response->mergeFromJsonString(json_encode([
            'header' => ['revision' => '245', 'member_id' => '10276657743932975437'],
            'kvs' => [['key' => base64_encode('k2'), 'value' => base64_encode('v2'), 'create_revision' => '244', 'mod_revision' => '245', 'version' => '2']],
            'count' => '1',
        ]));

        $array = GrpcTransport::response($response);

        // snake_case keys and 64-bit numbers as strings — the gateway's shape,
        // which KeyValue::decode() and every client decoder are written against.
        // Key order is field-number order (member_id=2 before revision=3, version=4
        // before value=5), which is what jsonpb emits on the gateway side too.
        self::assertSame(['member_id', 'revision'], array_keys($array['header']));
        self::assertSame('10276657743932975437', $array['header']['member_id']);
        self::assertSame('245', $array['header']['revision']);
        self::assertSame('1', $array['count']);
        self::assertSame(['key', 'create_revision', 'mod_revision', 'version', 'value'], array_keys($array['kvs'][0]));
        self::assertSame(base64_encode('k2'), $array['kvs'][0]['key']);
        self::assertSame(base64_encode('v2'), $array['kvs'][0]['value']);
        self::assertArrayNotHasKey('rangeEnd', $array);

        // and the project's own decoders accept it unchanged
        self::assertSame('k2', KeyValue::decode($array['kvs'][0])['key']);
        self::assertSame(245, KeyValue::decode($array['kvs'][0])['mod_revision']);
    }

    #[Test]
    public function theResponseDeserializerIsWhatTheChannelWouldCall(): void
    {
        $sent = new PutRequest();
        $sent->setKey('k');
        $sent->setValue('v');

        $deserialize = GrpcTransport::deserializer('Erikwang2013\\Etcd\\Proto\\Etcdserverpb\\PutRequest');
        $back = $deserialize($sent->serializeToString());

        self::assertInstanceOf(PutRequest::class, $back);
        self::assertSame('v', $back->getValue());
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: string}> */
    private static function table(): array
    {
        return self::constant('UNARY');
    }

    /** A private class constant, read rather than duplicated. */
    private static function constant(string $name): array
    {
        return (new \ReflectionClass(GrpcTransport::class))->getConstant($name);
    }

    /**
     * rpc.proto read straight: service says which RPCs exist, the RPC says which
     * messages it takes and whether it is a stream. That makes the transport's
     * table checkable against etcd rather than against a second copy of itself.
     *
     * @return array{0: array<string, array{methods: array<string, array{request: string, response: string, streaming: bool}>}>, 1: array<string, true>}
     */
    private static function parseProto(): array
    {
        $proto = (string) file_get_contents(dirname(__DIR__, 3) . '/protos/etcd/api/etcdserverpb/rpc.proto');
        $services = [];
        $messages = [];
        $service = null;

        foreach (explode("\n", $proto) as $line) {
            if (preg_match('/^service (\w+) \{/', $line, $m)) {
                $service = $m[1];
                $services[$service] = ['methods' => []];
            } elseif (preg_match('/^message (\w+)\s*\{/', $line, $m)) {
                $messages[$m[1]] = true;
            } elseif ($service !== null && preg_match('/^\s+rpc (\w+)\((stream )?(\w+)\) returns \((stream )?(\w+)\)/', $line, $m)) {
                $services[$service]['methods'][$m[1]] = [
                    'request' => $m[3],
                    'response' => $m[5],
                    'streaming' => $m[2] !== '' || $m[4] !== '',
                ];
            }
        }

        return [$services, $messages];
    }

    /** @return list<string> */
    private static function pathsSentByClients(): array
    {
        $paths = [];
        foreach (glob(dirname(__DIR__, 3) . '/src/*/*.php') ?: [] as $file) {
            preg_match_all("/->(?:send|sendRaw|sendStream)\('([^']+)'/", (string) file_get_contents($file), $matches);
            $paths = array_merge($paths, $matches[1]);
        }

        return array_values(array_unique($paths));
    }
}
