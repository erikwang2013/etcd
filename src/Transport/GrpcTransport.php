<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 *
 * This file is part of erikwang2013/etcd.
 *
 * SPDX-License-Identifier: MIT
 */

namespace Erikwang2013\Etcd\Transport;

use Erikwang2013\Etcd\Exception\AuthException;
use Erikwang2013\Etcd\Exception\ConnectionException;
use Erikwang2013\Etcd\Exception\EtcdException;
use Google\Protobuf\Internal\Message;

/**
 * etcd v3 over gRPC, on messages generated from etcd's own protos
 * (protos/etcd/api/**, regenerated with protoc --php_out; see protos/README.md).
 *
 * Unary RPCs only, and that boundary is the interesting part of this class:
 *
 * VERIFIED (tests/Unit/Transport/GeneratedMessagesTest.php and
 * GrpcTransportTest.php, run on a machine with protoc 28.3 + ext-protobuf 4.31.1
 * and no ext-grpc): the generated messages round-trip through the wire format,
 * every body the six clients build parses into the message for its path (their
 * option keys checked against etcd's descriptors, RPC by RPC), a response message
 * converts back into exactly the JSON shape the clients decode, and every
 * unusable path fails with a ConnectionException that says what is missing.
 * Nothing there needs a socket.
 *
 * One deliberate difference from the gateway: a body carrying a field the
 * message does not declare is refused here and ignored by the gateway. That is
 * how MaintenanceClient::hash($revision) came to light — HashRequest is empty in
 * v3.5, revision belongs to HashKV — and it is the one client call that works
 * over HTTP and fails over gRPC (pinned by a test rather than papered over). A
 * body that would lose a digit on a 64-bit field is refused for the same reason:
 * see checkPrecision().
 *
 * NOT VERIFIED: the channel itself — TLS/insecure credentials, _simpleRequest,
 * the wait() timeout unit, status-code mapping, the `token` metadata flow, the
 * per-call endpoint pick and any retry behaviour. There is no ext-grpc on the
 * machine this was written on, so not one call has ever completed. Do not read
 * "implemented" here as "measuring well"; the HTTP transport is the one that has
 * been run against a real etcd, which is why TransportSelector still picks it
 * for `auto`.
 */
class GrpcTransport implements TransportInterface
{
    /** Where protoc's output lives (composer psr-4: Erikwang2013\Etcd\Proto\). */
    private const PROTO = 'Erikwang2013\\Etcd\\Proto\\';

    /** Every etcd RPC in rpc.proto is in package etcdserverpb. */
    private const PACKAGE = self::PROTO . 'Etcdserverpb\\';

    /**
     * serializeToJsonString() flag 1 = keep the proto field names.
     *
     * Measured on ext-protobuf 4.31.1: flag 0 emits `rangeEnd`/`prevKv`, flag 1
     * emits `range_end`/`prev_kv`. The snake_case shape is the one every caller
     * decodes — KeyValue::decode() and its siblings were written against the
     * gateway's JSON, and `$kv['mod_revision']` has to mean the same thing on
     * both transports. Enum values stay names ("DELETE", "WRITE"), also what the
     * gateway sends; flag 2 would turn them into ints.
     */
    private const JSON_PROTO_FIELD_NAMES = 1;

    /** grpc\STATUS_OK, as a literal: the constant only exists with ext-grpc. */
    private const STATUS_OK = 0;

    /**
     * 2^53: the last integer a JSON number can carry through the protobuf JSON
     * parser unharmed. Measured on ext-protobuf 4.31.1 — 9007199254740993 in,
     * 9007199254740992 out, and no error. Every 64-bit field is also accepted as
     * a string, and strings are exact, so a body that would lose a digit is
     * refused rather than sent to the wrong key.
     */
    private const MAX_EXACT_JSON_INT = 9007199254740992;

    /**
     * Gateway path => [service, method, request class, response class].
     *
     * The paths are the grpc-gateway routes the subsystem clients already send;
     * the RPC names are etcd's. They are not derivable from each other —
     * /v3/kv/deleterange is DeleteRange, /v3/cluster/member/promote is
     * MemberPromote, /v3/auth/user/changepw is UserChangePassword — so the table
     * is the honest form, and GeneratedMessagesTest checks every row against
     * rpc.proto itself: service, RPC, and the message each RPC takes and returns.
     */
    private const UNARY = [
        '/v3/kv/range' => ['KV', 'Range', 'RangeRequest', 'RangeResponse'],
        '/v3/kv/put' => ['KV', 'Put', 'PutRequest', 'PutResponse'],
        '/v3/kv/deleterange' => ['KV', 'DeleteRange', 'DeleteRangeRequest', 'DeleteRangeResponse'],
        '/v3/kv/txn' => ['KV', 'Txn', 'TxnRequest', 'TxnResponse'],
        '/v3/kv/compaction' => ['KV', 'Compact', 'CompactionRequest', 'CompactionResponse'],
        '/v3/lease/grant' => ['Lease', 'LeaseGrant', 'LeaseGrantRequest', 'LeaseGrantResponse'],
        '/v3/lease/revoke' => ['Lease', 'LeaseRevoke', 'LeaseRevokeRequest', 'LeaseRevokeResponse'],
        '/v3/lease/timetolive' => ['Lease', 'LeaseTimeToLive', 'LeaseTimeToLiveRequest', 'LeaseTimeToLiveResponse'],
        '/v3/lease/leases' => ['Lease', 'LeaseLeases', 'LeaseLeasesRequest', 'LeaseLeasesResponse'],
        '/v3/cluster/member/add' => ['Cluster', 'MemberAdd', 'MemberAddRequest', 'MemberAddResponse'],
        '/v3/cluster/member/remove' => ['Cluster', 'MemberRemove', 'MemberRemoveRequest', 'MemberRemoveResponse'],
        '/v3/cluster/member/update' => ['Cluster', 'MemberUpdate', 'MemberUpdateRequest', 'MemberUpdateResponse'],
        '/v3/cluster/member/list' => ['Cluster', 'MemberList', 'MemberListRequest', 'MemberListResponse'],
        '/v3/cluster/member/promote' => ['Cluster', 'MemberPromote', 'MemberPromoteRequest', 'MemberPromoteResponse'],
        '/v3/maintenance/alarm' => ['Maintenance', 'Alarm', 'AlarmRequest', 'AlarmResponse'],
        '/v3/maintenance/status' => ['Maintenance', 'Status', 'StatusRequest', 'StatusResponse'],
        '/v3/maintenance/defragment' => ['Maintenance', 'Defragment', 'DefragmentRequest', 'DefragmentResponse'],
        '/v3/maintenance/hash' => ['Maintenance', 'Hash', 'HashRequest', 'HashResponse'],
        '/v3/auth/authenticate' => ['Auth', 'Authenticate', 'AuthenticateRequest', 'AuthenticateResponse'],
        '/v3/auth/enable' => ['Auth', 'AuthEnable', 'AuthEnableRequest', 'AuthEnableResponse'],
        '/v3/auth/disable' => ['Auth', 'AuthDisable', 'AuthDisableRequest', 'AuthDisableResponse'],
        '/v3/auth/status' => ['Auth', 'AuthStatus', 'AuthStatusRequest', 'AuthStatusResponse'],
        '/v3/auth/user/add' => ['Auth', 'UserAdd', 'AuthUserAddRequest', 'AuthUserAddResponse'],
        '/v3/auth/user/get' => ['Auth', 'UserGet', 'AuthUserGetRequest', 'AuthUserGetResponse'],
        '/v3/auth/user/list' => ['Auth', 'UserList', 'AuthUserListRequest', 'AuthUserListResponse'],
        '/v3/auth/user/delete' => ['Auth', 'UserDelete', 'AuthUserDeleteRequest', 'AuthUserDeleteResponse'],
        '/v3/auth/user/changepw' => ['Auth', 'UserChangePassword', 'AuthUserChangePasswordRequest', 'AuthUserChangePasswordResponse'],
        '/v3/auth/user/grant' => ['Auth', 'UserGrantRole', 'AuthUserGrantRoleRequest', 'AuthUserGrantRoleResponse'],
        '/v3/auth/user/revoke' => ['Auth', 'UserRevokeRole', 'AuthUserRevokeRoleRequest', 'AuthUserRevokeRoleResponse'],
        '/v3/auth/role/add' => ['Auth', 'RoleAdd', 'AuthRoleAddRequest', 'AuthRoleAddResponse'],
        '/v3/auth/role/get' => ['Auth', 'RoleGet', 'AuthRoleGetRequest', 'AuthRoleGetResponse'],
        '/v3/auth/role/list' => ['Auth', 'RoleList', 'AuthRoleListRequest', 'AuthRoleListResponse'],
        '/v3/auth/role/delete' => ['Auth', 'RoleDelete', 'AuthRoleDeleteRequest', 'AuthRoleDeleteResponse'],
        '/v3/auth/role/grant' => ['Auth', 'RoleGrantPermission', 'AuthRoleGrantPermissionRequest', 'AuthRoleGrantPermissionResponse'],
        '/v3/auth/role/revoke' => ['Auth', 'RoleRevokePermission', 'AuthRoleRevokePermissionRequest', 'AuthRoleRevokePermissionResponse'],
    ];

    /**
     * Paths the clients send that this transport cannot serve, and why, each in
     * words that name the RPC. Refusing by name beats half-building one: the three
     * stream RPCs need Stub methods (ServerStreamingCall/BidiStreamingCall) nobody
     * can run here, and the election paths are not etcdserverpb at all — they are
     * etcd's separate v3election API (package v3electionpb, a proto this transport
     * does not vendor), whose Campaign and Leader are streams besides.
     *
     * That last part is the reason not to vendor it just to serve two unary calls:
     * ElectionClient is written against gateway behaviour it documents as measured
     * (Campaign answered as one buffered response, "election: no leader" as an
     * empty answer), and a real streaming channel would not behave that way.
     */
    private const UNSUPPORTED = [
        '/v3/watch' => 'etcdserverpb.Watch/Watch is a bidirectional stream and this transport implements unary RPCs only',
        '/v3/lease/keepalive' => 'etcdserverpb.Lease/LeaseKeepAlive is a bidirectional stream and this transport implements unary RPCs only',
        '/v3/maintenance/snapshot' => 'etcdserverpb.Maintenance/Snapshot is a server stream and this transport implements unary RPCs only',
        '/v3/election/campaign' => 'that is etcd\'s v3election API (package v3electionpb), which this transport does not vendor, and its Campaign is a stream anyway',
        '/v3/election/proclaim' => 'that is etcd\'s v3election API (package v3electionpb), which this transport does not vendor',
        '/v3/election/leader' => 'that is etcd\'s v3election API (package v3electionpb), which this transport does not vendor, and its Leader is a stream anyway',
        '/v3/election/resign' => 'that is etcd\'s v3election API (package v3electionpb), which this transport does not vendor',
    ];

    private array $endpoints;
    private array $config;
    private string $currentEndpoint;
    private string $username = '';
    private string $password = '';
    private ?string $token = null;
    private bool $authenticating = false;

    public function __construct(array $endpoints, array $config = [])
    {
        // A channel addresses "host:port". A scheme in front of it (configs tend
        // to carry "http://host:2379", the HTTP transport strips it) leaves gRPC
        // resolving a name that does not exist.
        $this->endpoints = array_values(array_map(
            static fn (string $e): string => preg_replace('#^(?:[a-z][a-z0-9+.\-]*://)|/.*$#i', '', trim($e)),
            $endpoints
        ));
        $this->config = $config;
        $this->currentEndpoint = $this->endpoints[0];
        if (!empty($config['auth']['user'] ?? null)) {
            $this->username = (string) $config['auth']['user'];
            $this->password = (string) ($config['auth']['password'] ?? '');
        }
    }

    /**
     * @param ?float $timeout per-call timeout in seconds; null = the configured default
     */
    public function send(string $path, array $body, ?float $timeout = null): array
    {
        $request = self::request($path, $body);
        [$service, $method, , $responseClass] = self::UNARY[$path];

        $response = $this->invoke(
            '/etcdserverpb.' . $service . '/' . $method,
            self::PACKAGE . $responseClass,
            $request,
            $timeout
        );
        $decoded = self::response($response);

        // An explicit authenticate() reuses whatever token this returns, exactly
        // as the HTTP transport caches it, so AuthClient can expose the token
        // flow without the transport interface growing an HTTP-specific method.
        if ($path === '/v3/auth/authenticate' && isset($decoded['token'])) {
            $this->token = (string) $decoded['token'];
        }

        return $decoded;
    }

    /**
     * The only caller is MaintenanceClient::snapshot(), whose RPC is a stream.
     */
    public function sendRaw(string $path, ?float $timeout = null): string
    {
        throw new ConnectionException(self::unsupported($path));
    }

    public function sendStream(string $path, callable $onBlob, ?float $timeout = null): void
    {
        throw new ConnectionException(self::unsupported($path));
    }

    public function watch(string $key, string $rangeEnd, int $startRevision, callable $onEvent, array $options = []): void
    {
        throw new ConnectionException(self::unsupported('/v3/watch'));
    }

    /**
     * The generated request message for a gateway path, with the client's body
     * mapped onto it: the whole path => message translation, callable without a
     * channel so it can be asserted (and logged) on a machine without ext-grpc.
     *
     * @param array<string, mixed> $body as the clients build it: the gateway's JSON
     *                                   form of the same message (base64 bytes,
     *                                   64-bit fields as string), which is also
     *                                   proto3 JSON — so the descriptors do the
     *                                   mapping instead of a hand-written one that
     *                                   rots the moment etcd adds a field.
     * @throws ConnectionException on a path with no unary RPC, or a class that is
     *                             not on the autoloader (missing ext-protobuf etc.)
     */
    public static function request(string $path, array $body): Message
    {
        if (!isset(self::UNARY[$path])) {
            throw new ConnectionException(self::unsupported($path));
        }
        $class = self::PACKAGE . self::UNARY[$path][2];

        try {
            $loadable = class_exists($class);
        } catch (\Throwable $e) {
            // A class whose parent is missing throws while being declared; that
            // is the "ext-protobuf is not installed" case, and it must read like
            // advice rather than like a fatal.
            $loadable = false;
        }
        if (!$loadable) {
            throw new ConnectionException(
                "gRPC transport: {$class} is not loadable. Load ext-protobuf (or require google/protobuf, "
                . 'which ships the same classes), and re-run composer dump-autoload if the protos were '
                . 'generated after the autoloader was built.'
            );
        }

        self::checkPrecision($body, $path);

        $message = new $class();
        try {
            // An empty body must be `{}`, not `[]`: protojson rejects an array
            // where a message is expected, exactly as the gateway's Go struct does.
            $json = json_encode($body === [] ? new \stdClass() : $body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $message->mergeFromJsonString($json);
        } catch (\Throwable $e) {
            throw new EtcdException(
                "gRPC request for {$path} does not match {$class}: {$e->getMessage()}",
                previous: $e
            );
        }

        return $message;
    }

    /**
     * Refuse a body whose 64-bit numbers already lost a digit on the way in.
     *
     * Two ways that happens, both measured: a PHP float (PHP turns a literal
     * above PHP_INT_MAX into one, and protojson then rounds it onto a neighbouring
     * member id), and a JSON number above 2^53. No field in rpc.proto, kv.proto or
     * auth.proto is a float, so a float here is always a value that was already
     * damaged; a big number can still be fixed by the caller, by passing it as a
     * string — which is what Int64::decode() hands out and what every client in
     * this package sends back.
     *
     * @param array<string, mixed>|list<mixed> $body
     */
    private static function checkPrecision(array $body, string $path): void
    {
        foreach ($body as $field => $value) {
            if (is_array($value)) {
                self::checkPrecision($value, $path);
            } elseif (is_float($value)) {
                throw new EtcdException(
                    "gRPC request for {$path}: {$field} is the float {$value}. No etcd field is a float — this is a "
                    . '64-bit id or revision that PHP turned into one and it has already lost precision. '
                    . 'Pass it as a string, as Int64::decode() returns it.'
                );
            } elseif (is_int($value) && ($value > self::MAX_EXACT_JSON_INT || $value < -self::MAX_EXACT_JSON_INT)) {
                throw new EtcdException(
                    "gRPC request for {$path}: {$field} = {$value} is a JSON number beyond 2^53, which the protobuf "
                    . 'JSON parser rounds to a neighbouring value (measured). Pass it as a string, as Int64::decode() '
                    . 'returns it.'
                );
            }
        }
    }

    /**
     * A response message in the shape the clients decode — the other half of
     * request(): base64 bytes, 64-bit numbers as strings, enum names, defaults
     * omitted. That is what the gateway serves, so the two transports are
     * interchangeable from the callers' point of view.
     *
     * @return array<string, mixed>
     */
    public static function response(Message $message): array
    {
        $json = $message->serializeToJsonString(self::JSON_PROTO_FIELD_NAMES);

        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * One unary call on the current endpoint.
     */
    private function invoke(string $method, string $responseClass, Message $request, ?float $timeout): Message
    {
        if (!extension_loaded('grpc') || !class_exists('\Grpc\BaseStub')) {
            throw new ConnectionException(
                'gRPC extension not available (pecl install grpc / extension=grpc.so). '
                . 'Use the HTTP transport, which needs no extension.'
            );
        }

        $endpoint = $this->pickEndpoint();
        $stub = new GrpcStub($endpoint, $this->channelCredentials(), $this->getChannel($endpoint));
        [$response, $status] = $stub->unary(
            $method,
            $request,
            self::deserializer($responseClass),
            $this->metadata(),
            $timeout ?? (float) ($this->config['timeout'] ?? 5.0)
        );

        if ((int) ($status->code ?? self::STATUS_OK) !== self::STATUS_OK) {
            throw self::statusToException($status);
        }

        return $response;
    }

    /**
     * etcd's gRPC server reads the auth token from the `token` metadata key; the
     * HTTP gateway takes the same token as a bare Authorization header. Same lazy
     * flow as the HTTP transport: authenticate on first use when credentials are
     * configured, and remember a token that an explicit Authenticate returned.
     *
     * @return array<string, string>
     */
    private function metadata(): array
    {
        if ($this->token === null && $this->username !== '' && !$this->authenticating) {
            $this->authenticating = true;
            try {
                $this->send('/v3/auth/authenticate', [
                    'name' => $this->username,
                    'password' => $this->password,
                ]);
            } finally {
                $this->authenticating = false;
            }
        }

        return $this->token === null ? [] : ['token' => $this->token];
    }

    /**
     * gRPC status => the exception the same etcd error raises over HTTP. Codes
     * that prove nothing was applied are marked retryable; etcd's own error text
     * travels in `details`.
     */
    private static function statusToException(object $status): EtcdException
    {
        $code = (int) ($status->code ?? 0);
        $message = self::describeError($status->details ?? '');

        return match ($code) {
            16, 7 => new AuthException("Authentication failed: {$message}"),      // UNAUTHENTICATED, PERMISSION_DENIED
            14, 4 => new ConnectionException("etcd unreachable: {$message}", retryable: true), // UNAVAILABLE, DEADLINE_EXCEEDED
            default => new EtcdException("etcd error (gRPC status {$code}): {$message}"),
        };
    }

    /** details is a string unless grpc put a structure there; never "Array". */
    private static function describeError(mixed $details): string
    {
        if (is_string($details)) {
            return $details;
        }
        $encoded = json_encode($details);

        return $encoded !== false ? $encoded : var_export($details, true);
    }

    /**
     * One place that says why a path cannot go over this transport, naming the
     * RPC so it is clear whether the path or the transport is at fault.
     */
    private static function unsupported(string $path): string
    {
        if (isset(self::UNSUPPORTED[$path])) {
            return "gRPC transport cannot send {$path}: " . self::UNSUPPORTED[$path]
                . '. Use the HTTP transport (transport: http) for this call.';
        }

        return "gRPC transport has no method mapped for {$path}; see GrpcTransport::UNARY.";
    }

    /**
     * scheme=https gets TLS with the system trust store, anything else plaintext —
     * the HTTP transport's default too.
     *
     * ponytail: no CA/client-cert knobs (options.ssl) on this path yet. Nobody has
     * asked for mTLS over gRPC, and none of it could be tried out here.
     */
    private function channelCredentials(): object
    {
        return ($this->config['scheme'] ?? 'http') === 'https'
            ? \Grpc\ChannelCredentials::createSsl()
            : \Grpc\ChannelCredentials::createInsecure();
    }

    /**
     * What the channel hands each reply's bytes to.
     *
     * A closure rather than the `[Message::class, 'decode']` array that
     * grpc_php_plugin emits: ext-protobuf 4.x has no static Message::decode()
     * (measured — is_callable() says no), so the array form would be a fatal
     * "call to undefined method" the moment the first reply arrived.
     *
     * Public because it is the one piece of the channel wiring that can be
     * exercised without a channel: hand it serialized bytes and a message comes
     * back.
     */
    public static function deserializer(string $responseClass): callable
    {
        return static function (string $bytes) use ($responseClass): Message {
            /** @var Message $message */
            $message = new $responseClass();
            $message->mergeFromString($bytes);

            return $message;
        };
    }

    /**
     * @return mixed \Grpc\Channel
     */
    public function getChannel(string $endpoint)
    {
        static $channels = [];
        if (!isset($channels[$endpoint])) {
            if (!class_exists('\Grpc\Channel')) {
                throw new ConnectionException('gRPC extension not available. Install grpc/grpc or use HTTP transport.');
            }
            $channels[$endpoint] = new \Grpc\Channel($endpoint, ['credentials' => $this->channelCredentials()]);
        }

        return $channels[$endpoint];
    }

    public function getCurrentEndpoint(): string
    {
        return $this->currentEndpoint;
    }

    private function pickEndpoint(): string
    {
        $this->currentEndpoint = $this->endpoints[array_rand($this->endpoints)];

        return $this->currentEndpoint;
    }
}
