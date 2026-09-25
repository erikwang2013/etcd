<?php

declare(strict_types=1);

/**
 * Integration test for HttpTransport against tests/watch_router.php, a fake etcd
 * v3 gateway whose behaviour was measured against real etcd 3.5.17.
 *
 * This file asserts CORRECT client behaviour, so cases covering paths that are
 * still broken are expected to FAIL — that is the point. It is the regression
 * suite for those fixes: run it and watch the FAIL list shrink to zero.
 *
 * Run:
 *   php -d zend.assertions=1 -d assert.exception=1 tests/transport_test.php
 *
 * Optional differential check: point it at a real etcd and the same framing
 * probe runs against both, and the two must agree.
 *   ETCD_REAL=127.0.0.1:23791 php -d zend.assertions=1 -d assert.exception=1 tests/transport_test.php
 */

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/Support/FakeGateway.php';

use Erikwang2013\Etcd\EtcdClient;
use Erikwang2013\Etcd\Exception\ConnectionException;
use Erikwang2013\Etcd\Exception\EtcdException;
use Erikwang2013\Etcd\Tests\Support\Cases;
use Erikwang2013\Etcd\Tests\Support\FakeGateway;
use Erikwang2013\Etcd\Transport\HttpTransport;

// ---------------------------------------------------------------------------
// unit cases (no fixture)
// ---------------------------------------------------------------------------
Cases::run('unit: prefixToRangeEnd on an all-0xFF prefix', function () {
    assert(EtcdClient::prefixToRangeEnd("\xff\xff") === "\x00");
});

Cases::run('unit: credentials over plaintext http are refused', function () {
    try {
        new HttpTransport(['127.0.0.1:1'], ['auth' => ['user' => 'u', 'password' => 'p']]);
        assert(false, 'auth over http should throw');
    } catch (ConnectionException) {
        // expected
    }
});

Cases::run('unit: detectDriver reports a usable driver', function () {
    assert(in_array(HttpTransport::detectDriver(), [HttpTransport::DRIVER_CURL, HttpTransport::DRIVER_STREAM], true));
});

// ---------------------------------------------------------------------------
// fixture fidelity: if these fail, the fixture — not the client — is wrong
// ---------------------------------------------------------------------------
[$proc, $endpoint] = FakeGateway::start();
$procs = [$proc];

$cfg = ['scheme' => 'http', 'timeout' => 5.0, 'retry' => 0];
$transport = new HttpTransport([$endpoint], $cfg);
$streamTransport = new HttpTransport([$endpoint], $cfg + ['driver' => 'stream']);
$client = new EtcdClient(['endpoints' => [$endpoint]] + $cfg);

Cases::run('fixture: streaming RPCs are chunked, enveloped, with no Content-Length', function () use ($endpoint) {
    [$status, $headers, $body] = FakeGateway::rawSocket($endpoint, '/v3/watch', '{"create_request":{"key":"aw=="}}');
    assert($status === 200, "watch status {$status}");
    assert(($headers['transfer-encoding'] ?? '') === 'chunked', 'watch is not chunked: ' . json_encode($headers));
    assert(!isset($headers['content-length']), 'a chunked response must not carry Content-Length');

    $lines = array_values(array_filter(explode("\n", FakeGateway::dechunk($body)), static fn($l) => trim($l) !== ''));
    assert(count($lines) === 4, 'expected 4 frames (created + 3 events), got ' . count($lines));
    foreach ($lines as $line) {
        $decoded = json_decode($line, true);
        assert(is_array($decoded) && isset($decoded['result']), 'frame not wrapped in {"result":...}: ' . substr($line, 0, 90));
    }
    assert((json_decode($lines[0], true)['result']['created'] ?? null) === true, 'first frame should be the created frame');
});

Cases::run('fixture: unary RPCs carry Content-Length and no envelope', function () use ($endpoint) {
    [$status, $headers, $body] = FakeGateway::rawSocket($endpoint, '/v3/kv/range', '{"key":"YQ=="}');
    assert($status === 200, "range status {$status}");
    assert(isset($headers['content-length']), 'unary response should carry Content-Length: ' . json_encode($headers));
    assert(!isset($headers['transfer-encoding']), 'unary response must not be chunked');
    assert(!isset(json_decode($body, true)['result']), 'unary response must not be enveloped');
});

Cases::run('fixture: a top-level JSON array body is rejected the way Go rejects it', function () use ($endpoint) {
    [$status, , $body] = FakeGateway::rawSocket($endpoint, '/v3/maintenance/status', '[]');
    assert($status === 400, "expected 400 for `[]`, got {$status}");
    assert(str_contains($body, 'cannot unmarshal array into Go value'), 'unexpected 400 body: ' . $body);

    [$streamStatus, , ] = FakeGateway::rawSocket($endpoint, '/v3/watch', '[]');
    assert($streamStatus === 500, "streaming unmarshal failure is a 500 (status already sent), got {$streamStatus}");
});

Cases::run('fixture: int64 fields are JSON strings and zero values are omitted', function () use ($endpoint) {
    [, , $body] = FakeGateway::rawSocket($endpoint, '/v3/kv/range', '{"key":"YQ=="}');
    $decoded = json_decode($body, true);
    assert(is_string($decoded['count'] ?? null), 'count must be a string: ' . $body);
    assert(is_string($decoded['header']['revision'] ?? null), 'revision must be a string');
    assert(!array_key_exists('lease', $decoded['kvs'][0] ?? []), 'a fresh key must omit lease entirely');

    [, , $missing] = FakeGateway::rawSocket($endpoint, '/v3/kv/range', '{"key":"bm9wZQ=="}');
    $missingDecoded = json_decode($missing, true);
    assert(!isset($missingDecoded['kvs']) && !isset($missingDecoded['count']), 'a missing key must omit kvs and count: ' . $missing);
});

Cases::run('fixture: unknown route is a plain-text 404', function () use ($endpoint) {
    [$status, , $body] = FakeGateway::rawSocket($endpoint, '/v3/auth/auth/status', '{}');
    assert($status === 404, "expected 404, got {$status}");
    assert(str_contains($body, 'Not Found'), 'unexpected 404 body: ' . $body);
});

// ---------------------------------------------------------------------------
// watch — the chunked-stream P0
// ---------------------------------------------------------------------------
Cases::run('watch: consumes the chunked stream and delivers every event in order', function () use ($transport) {
    $events = [];
    $stop = new class extends \Exception {};
    try {
        $transport->watch('k', '', 0, function (array $batch) use (&$events, $stop) {
            $events = array_merge($events, $batch);
            if (count($events) >= 3) {
                throw $stop;
            }
        });
    } catch (\Throwable $e) {
        if (!$e instanceof $stop) {
            throw $e;
        }
    }
    assert(count($events) === 3, 'expected 3 events, got ' . count($events) . ': ' . json_encode($events));
    assert(array_map(static fn($e) => $e['kv']['key'] ?? null, $events) === ['k1', 'k2', 'k3'], 'keys lost or out of order: ' . json_encode($events));
    assert(array_map(static fn($e) => $e['type'] ?? null, $events) === ['PUT', 'PUT', 'DELETE'], 'types wrong: ' . json_encode($events));
    assert(($events[0]['kv']['value'] ?? null) === 'v1', 'first value wrong: ' . json_encode($events[0]));
    assert(($events[1]['kv']['value'] ?? null) === 'v2', 'second value wrong: ' . json_encode($events[1]));
    assert((string) ($events[2]['kv']['value'] ?? '') === '', 'a DELETE kv must carry no value: ' . json_encode($events[2]));
});

Cases::run('watch: prev_kv comes through when the caller asks for it', function () use ($transport) {
    $events = [];
    $stop = new class extends \Exception {};
    try {
        $transport->watch('k', '', 0, function (array $batch) use (&$events, $stop) {
            $events = array_merge($events, $batch);
            if (count($events) >= 3) {
                throw $stop;
            }
        }, ['prevKv' => true]);
    } catch (\Throwable $e) {
        if (!$e instanceof $stop) {
            throw $e;
        }
    }
    assert(count($events) === 3, 'expected 3 events, got ' . count($events));
    assert(($events[0]['prev_kv'] ?? null) === null, 'a create has no prev_kv');
    assert(($events[1]['prev_kv']['value'] ?? null) === 'v1', 'update prev_kv wrong: ' . json_encode($events[1]['prev_kv'] ?? null));
    assert(($events[2]['prev_kv']['value'] ?? null) === 'v2', 'delete prev_kv wrong: ' . json_encode($events[2]['prev_kv'] ?? null));
});

// ---------------------------------------------------------------------------
// unary RPCs
// ---------------------------------------------------------------------------
Cases::run('kv: get() decodes key and value and reads the string count', function () use ($client) {
    $range = $client->kv()->get('a');
    assert(($range['kvs'][0]['key'] ?? null) === 'a', 'key not decoded: ' . json_encode($range));
    assert(($range['kvs'][0]['value'] ?? null) === 'b', 'value not decoded: ' . json_encode($range));
    assert($range['count'] === 1, 'count wrong: ' . var_export($range['count'], true));
    assert((string) ($range['header']['revision'] ?? '') === '243', 'revision wrong: ' . json_encode($range['header'] ?? null));
});

Cases::run('kv: a missing key yields no kvs and count 0 instead of a warning', function () use ($client) {
    $range = $client->kv()->get('nope');
    assert(($range['kvs'] ?? null) === [], 'expected an empty kv list: ' . json_encode($range));
    assert($range['count'] === 0, 'expected count 0: ' . var_export($range['count'], true));
});

Cases::run('kv: put, delete and txn all report the server result', function () use ($client) {
    $put = $client->kv()->put('a', 'b');
    assert(isset($put['header']), 'put returned no header: ' . json_encode($put));

    $deleted = $client->kv()->delete('a');
    assert((int) ($deleted['deleted'] ?? 0) === 1, 'delete did not report the deletion: ' . json_encode($deleted));

    $txn = $client->kv()->txn([], []);
    assert(($txn['succeeded'] ?? null) === true, 'txn succeeded wrong: ' . json_encode($txn));
});

Cases::run('maintenance/lease: hash, alarm, compact and revoke', function () use ($client) {
    // hash is uint32, so unlike the int64 fields it is a plain JSON number
    $hash = $client->maintenance()->hash();
    assert($hash['hash'] === 36253916, 'hash wrong: ' . var_export($hash['hash'], true));

    $alarm = $client->maintenance()->alarm();
    assert(($alarm['alarms'] ?? null) === [], 'no alarms expected: ' . json_encode($alarm));

    assert(isset($client->kv()->compact(1000)['header']), 'compact failed');
    assert(isset($client->lease()->revoke(FakeGateway::LEASE_ID)['header']), 'revoke failed');
});

Cases::run('lease: grant() reads ID and TTL out of string int64s', function () use ($client) {
    $grant = $client->lease()->grant(60);
    assert($grant['ID'] === FakeGateway::LEASE_ID, 'ID wrong: ' . var_export($grant['ID'], true));
    assert($grant['TTL'] === 60, 'TTL wrong: ' . var_export($grant['TTL'], true));
});

Cases::run('lease: timeToLive() reads TTL and grantedTTL', function () use ($client) {
    $ttl = $client->lease()->timeToLive(FakeGateway::LEASE_ID);
    assert($ttl['TTL'] === 44, 'TTL wrong: ' . var_export($ttl['TTL'], true));
    assert($ttl['grantedTTL'] === 60, 'grantedTTL wrong: ' . var_export($ttl['grantedTTL'], true));
});

Cases::run('lease: keepAlive() unwraps the streamed envelope', function () use ($client) {
    $keep = $client->lease()->keepAlive(FakeGateway::LEASE_ID);
    assert($keep['ID'] === FakeGateway::LEASE_ID, 'ID wrong (envelope still wrapped?): ' . var_export($keep['ID'], true));
    assert($keep['TTL'] === 60, 'TTL wrong (envelope still wrapped?): ' . var_export($keep['TTL'], true));
});

Cases::run('zero-arg: maintenance()->status() sends {} and reads string int64s', function () use ($client) {
    $status = $client->maintenance()->status();
    assert($status['version'] === '3.5.17', 'version wrong: ' . json_encode($status));
    assert($status['dbSize'] === 876544, 'dbSize wrong: ' . var_export($status['dbSize'], true));
    // uint64 on the wire: the member id is 10276657743932975437, larger than
    // PHP_INT_MAX, so an (int) cast saturates it to 9223372036854775807.
    assert((string) $status['leader'] === FakeGateway::MEMBER_ID, 'leader lost to an int cast: ' . var_export($status['leader'], true));
});

Cases::run('zero-arg: cluster()->memberList()', function () use ($client) {
    $list = $client->cluster()->memberList();
    assert(count($list['members']) === 1, 'members wrong: ' . json_encode($list));
    assert((string) ($list['members'][0]['ID'] ?? '') === FakeGateway::MEMBER_ID, 'member ID wrong: ' . json_encode($list['members'][0] ?? null));
    assert(!isset($list['header']['revision']), 'member RPCs omit revision; the client must not invent one');
});

Cases::run('zero-arg: lease()->list()', function () use ($client) {
    assert(isset($client->lease()->list()['header']), 'lease list failed');
});

Cases::run('zero-arg: auth()->enable() calls /v3/auth/enable', function () use ($client) {
    assert(isset($client->auth()->enable()['header']), 'auth enable failed');
});

Cases::run('zero-arg: auth()->disable() calls /v3/auth/disable', function () use ($client) {
    assert(isset($client->auth()->disable()['header']), 'auth disable failed');
});

Cases::run('zero-arg: auth()->user()->list()', function () use ($client) {
    assert(($client->auth()->user()->list()['users'] ?? null) === ['root'], 'user list wrong');
});

Cases::run('zero-arg: auth()->role()->list()', function () use ($client) {
    assert(isset($client->auth()->role()->list()['header']), 'role list failed');
});

Cases::run('zero-arg: auth()->status() calls /v3/auth/status', function () use ($client) {
    $status = $client->auth()->status();
    assert($status['enabled'] === true, 'enabled wrong: ' . json_encode($status));
    assert($status['authRevision'] === 7, 'authRevision wrong: ' . var_export($status['authRevision'], true));
});

// ---------------------------------------------------------------------------
// snapshot — the streamed-binary P0
// ---------------------------------------------------------------------------
Cases::run('snapshot: sendRaw() reassembles the db bytes instead of returning frame JSON', function () use ($transport) {
    $snapshot = $transport->sendRaw('/v3/maintenance/snapshot');
    assert(!str_contains($snapshot, '"result"'), 'the {"result":...} envelope was not stripped');
    assert(!str_contains($snapshot, '"blob"'), 'frame JSON leaked into the snapshot bytes');
    assert(strlen($snapshot) > 32, 'snapshot is too short: ' . strlen($snapshot) . ' bytes');
    assert(substr($snapshot, -32) === hash('sha256', substr($snapshot, 0, -32), true), 'the 32-byte SHA-256 trailer does not match the payload');
});

Cases::run('snapshot: the stream driver returns the same bytes', function () use ($streamTransport) {
    $snapshot = $streamTransport->sendRaw('/v3/maintenance/snapshot');
    assert(strlen($snapshot) > 32, 'snapshot is too short: ' . strlen($snapshot) . ' bytes');
    assert(substr($snapshot, -32) === hash('sha256', substr($snapshot, 0, -32), true), 'stream driver trailer mismatch');
});

Cases::run('snapshot: collection over the stream driver behaves like the curl driver', function () use ($streamTransport) {
    $range = $streamTransport->send('/v3/kv/range', ['key' => base64_encode('a')]);
    assert(($range['kvs'][0]['key'] ?? null) === base64_encode('a'), 'stream send() decode failed: ' . json_encode($range));
});

// ---------------------------------------------------------------------------
// errors
// ---------------------------------------------------------------------------
Cases::run('errors: a 404 becomes an EtcdException naming the status', function () use ($streamTransport) {
    try {
        $streamTransport->send('/v3/does-not-exist', []);
        assert(false, 'send() should raise on 404');
    } catch (EtcdException $e) {
        assert(str_contains($e->getMessage(), '404'), 'message should mention 404: ' . $e->getMessage());
    }
});

// ---------------------------------------------------------------------------
// auth mode: the fixture runs again with a bare-token requirement
// ---------------------------------------------------------------------------
[$authProc, $authEndpoint] = FakeGateway::start(['ETCD_FAKE_AUTH' => '1']);
$procs[] = $authProc;

Cases::run('auth: missing credentials, bad tokens and Bearer are all refused', function () use ($authEndpoint) {
    [$status, , $body] = FakeGateway::rawSocket($authEndpoint, '/v3/kv/range', '{"key":"YQ=="}');
    assert($status === 400 && str_contains($body, 'user name is empty'), "no credentials should be 400 user name is empty, got {$status}: {$body}");

    [$status, , $body] = FakeGateway::rawSocket($authEndpoint, '/v3/kv/range', '{"key":"YQ=="}', ['Authorization' => 'garbage']);
    assert($status === 401 && str_contains($body, 'invalid auth token'), "bad token should be 401, got {$status}: {$body}");

    // etcd wants the bare token; a Bearer prefix is not a token
    [$status, , ] = FakeGateway::rawSocket($authEndpoint, '/v3/kv/range', '{"key":"YQ=="}', ['Authorization' => 'Bearer fake-root-token']);
    assert($status === 401, "a Bearer prefix must be rejected, got {$status}");
});

Cases::run('auth: authenticate issues a token that then authorises a call', function () use ($authEndpoint) {
    [$status, , $body] = FakeGateway::rawSocket($authEndpoint, '/v3/auth/authenticate', '{"name":"root","password":"rootpw"}');
    assert($status === 200, "authenticate failed: {$status} {$body}");
    $token = json_decode($body, true)['token'] ?? '';
    assert($token !== '', 'no token issued: ' . $body);

    [$status, , $body] = FakeGateway::rawSocket($authEndpoint, '/v3/kv/range', '{"key":"YQ=="}', ['Authorization' => $token]);
    assert($status === 200, "a valid bare token should authorise, got {$status}: {$body}");

    // measured: wrong credentials are a 400 on authenticate, not a 401
    [$status, , $body] = FakeGateway::rawSocket($authEndpoint, '/v3/auth/authenticate', '{"name":"root","password":"wrong"}');
    assert($status === 400 && str_contains($body, 'invalid user ID or password'), "bad password should be 400, got {$status}: {$body}");
});

Cases::run('auth: the client surfaces the server error text for a refused request', function () use ($authEndpoint) {
    $transport = new HttpTransport([$authEndpoint], ['scheme' => 'http', 'timeout' => 5.0, 'retry' => 0]);
    try {
        $transport->send('/v3/kv/range', ['key' => base64_encode('a')]);
        assert(false, 'a request without credentials should be refused');
    } catch (EtcdException $e) {
        assert(str_contains($e->getMessage(), 'user name is empty'), 'expected etcd error text, got: ' . $e->getMessage());
    }
});

// ---------------------------------------------------------------------------
// optional differential check against a real etcd
// ---------------------------------------------------------------------------
$real = getenv('ETCD_REAL') ?: '';
if ($real !== '') {
    Cases::run("differential: framing against real etcd at {$real} matches the fixture", function () use ($real, $endpoint) {
        $mine = FakeGateway::framingProbe($endpoint);
        $theirs = FakeGateway::framingProbe($real);
        assert($mine['select'] === $theirs['select'], 'stream_select differs — fixture: "' . $mine['select'] . '" real: "' . $theirs['select'] . '"');
        assert($mine['garbage'] === 0, 'fixture leaked chunk framing into the frames: ' . json_encode($mine));
        assert($theirs['garbage'] === 0, 'real etcd leaked chunk framing into the frames: ' . json_encode($theirs));
        assert($mine['frames'] > 0 && $theirs['frames'] > 0, 'no frames decoded: ' . json_encode([$mine, $theirs]));
    });
}

// ---------------------------------------------------------------------------
foreach ($procs as $p) {
    proc_terminate($p);
}

exit(Cases::summary());
