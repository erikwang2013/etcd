<?php

declare(strict_types=1);

/**
 * Faithful fake etcd v3 grpc-gateway for HttpTransport integration tests.
 *
 * Every behaviour here was measured against real etcd 3.5.17:
 *   127.0.0.1:23791 (auth off) and 127.0.0.1:23792 (auth on).
 *
 * The two properties that matter most, because the shipped bugs depend on them:
 *
 *   1. Streaming RPCs (/v3/watch, /v3/maintenance/snapshot, /v3/lease/keepalive)
 *      answer `Transfer-Encoding: chunked` with no Content-Length. PHP's http
 *      wrapper then installs a dechunk filter, and stream_select() on the
 *      resulting stream fails with
 *      "Cannot cast a filtered stream on this system" — byte-for-byte identical
 *      to how it fails against real etcd.
 *   2. Streaming frames are wrapped `{"result":{...}}\n`; unary responses are not.
 *
 * Also mirrored: int64/uint64 as JSON strings, proto3 zero values omitted,
 * enums as names, `400` on a top-level JSON array body, plain-text 404.
 *
 * Run:
 *   php -S 127.0.0.1:8080 tests/watch_router.php
 *   ETCD_FAKE_AUTH=1 php -S 127.0.0.1:8080 tests/watch_router.php   # auth on
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$raw  = (string) file_get_contents('php://input');

/** Identities copied from the real cluster so tests can assert on them. */
const CLUSTER_ID = '14841639068965178418';
const MEMBER_ID  = '10276657743932975437';
const RAFT_TERM  = '2';
const FAKE_TOKEN = 'fake-root-token';

/** Snapshot geometry, mirroring the real stream: 32768-byte-ish blocks + 32-byte trailer. */
const SNAP_BLOCK  = 4096;
const SNAP_BLOCKS = 3;

/** Routes that answer chunked with no Content-Length (measured). */
const STREAMING = ['/v3/watch', '/v3/maintenance/snapshot', '/v3/lease/keepalive'];

/**
 * ResponseHeader. Member RPCs omit `revision` (proto3 zero value) — measured.
 */
function header_arr(?string $revision = '243'): array
{
    $h = ['cluster_id' => CLUSTER_ID, 'member_id' => MEMBER_ID];
    if ($revision !== null) {
        $h['revision'] = $revision;
    }
    $h['raft_term'] = RAFT_TERM;

    return $h;
}

/** int64/uint64 travel as JSON strings (measured on every numeric field). */
function rev(int $n): string
{
    return (string) $n;
}

function json_out(array $payload, int $status = 200): void
{
    // real etcd does not escape `/` inside base64 payloads
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    http_response_code($status);
    header('Content-Type: application/json');
    // Unary RPCs answer with Content-Length and no chunking (measured).
    // php -S will not add it for us once anything has been flushed, so be explicit.
    header('Content-Length: ' . strlen($json));
    echo $json;
}

/**
 * grpc-gateway error body. Measured codes:
 *   3  InvalidArgument (bad JSON body, empty user name)
 *   2  Unknown (streaming unmarshal failure, status line already sent)
 *  16  Unauthenticated (bad auth token)
 */
function fail(int $status, string $message, int $code): void
{
    json_out(['error' => $message, 'code' => $code, 'message' => $message], $status);
}

/**
 * Chunked + no Content-Length: what makes PHP install the dechunk filter, which
 * is why stream_select() then fails exactly as it does against real etcd.
 * Setting the header also stops php -S from adding a Content-Length.
 *
 * php -S writes whatever we echo verbatim while honouring this header, so the
 * chunk framing below is ours to write — and it has to be REAL framing, not
 * just the header. A client that dechunks (curl, PHP's own http wrapper) sees
 * `8f\r\n{...}\n\r\n ... 0\r\n\r\n`, byte-shaped like etcd's gateway output;
 * a body that merely *claims* chunked makes such a client fail with
 * "chunk hex-length char not a hex digit".
 */
function stream_start(): void
{
    header('Content-Type: application/json');
    header('Transfer-Encoding: chunked');
}

/** Write one HTTP chunk. */
function chunk(string $data): void
{
    if ($data === '') {
        return;
    }
    echo dechex(strlen($data)), "\r\n", $data, "\r\n";
    flush();
}

/** Terminating chunk, so a dechunking client sees a clean end of body. */
function stream_end(): void
{
    echo "0\r\n\r\n";
    flush();
}

/**
 * Emit one `{"result":{...}}` NDJSON frame as a chunk.
 *
 * @param int $pieceSize when > 0 the line is sent in several chunks, so the
 *                       client has to reassemble partial lines as it must over TCP
 */
function frame(array $result, int $pieceSize = 0): void
{
    $line = json_encode(['result' => $result], JSON_UNESCAPED_SLASHES) . "\n";
    foreach ($pieceSize > 0 ? str_split($line, $pieceSize) : [$line] as $piece) {
        chunk($piece);
        if ($pieceSize > 0) {
            usleep(2000);
        }
    }
}

$isStreaming = in_array($path, STREAMING, true);

// --- request body -----------------------------------------------------------
// The gateway unmarshals every body into map[string]json.RawMessage, so a
// top-level JSON array is a hard error. This is what kills the zero-argument
// client methods, which today json_encode an empty PHP array as `[]`.
if (trim($raw) !== '') {
    $decoded = json_decode($raw, true);
    if (trim($raw)[0] === '[') {
        $msg = 'json: cannot unmarshal array into Go value of type map[string]json.RawMessage';
        fail($isStreaming ? 500 : 400, $msg, $isStreaming ? 2 : 3);
        exit;
    }
    if (!is_array($decoded)) {
        fail($isStreaming ? 500 : 400, 'invalid character looking for beginning of value', 3);
        exit;
    }
    $body = $decoded;
} else {
    $body = [];
}

// --- auth -------------------------------------------------------------------
// Enabled only on request so the default fixture stays usable without a token.
// A bare token is required: `Authorization: Bearer <token>` is rejected (measured).
$authEnabled = getenv('ETCD_FAKE_AUTH') === '1';
if ($authEnabled && $path !== '/v3/auth/authenticate') {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($auth === '') {
        // measured: no credentials at all is a *different* error from a bad token
        fail(400, 'etcdserver: user name is empty', 3);
        exit;
    }
    if ($auth !== FAKE_TOKEN) {
        fail(401, 'etcdserver: invalid auth token', 16);
        exit;
    }
}

// --- authenticate -----------------------------------------------------------
// Exempt from the token check above, and it validates the credentials itself.
// Measured: wrong or unknown credentials answer 400 (not 401) with this text.
if ($path === '/v3/auth/authenticate') {
    if (($body['name'] ?? '') !== 'root' || ($body['password'] ?? '') !== 'rootpw') {
        fail(400, 'etcdserver: authentication failed, invalid user ID or password', 3);
        exit;
    }
    json_out(['header' => header_arr(), 'token' => FAKE_TOKEN]);
    exit;
}

// --- streaming routes -------------------------------------------------------
if ($path === '/v3/watch') {
    stream_start();
    frame(['header' => header_arr(), 'created' => true]);

    // prev_kv only comes back when the client asks for it (measured).
    $wantPrev = !empty($body['create_request']['prev_kv']);
    $k = static fn(string $s): string => base64_encode($s);

    // PUT: `type` is omitted entirely (PUT is the proto3 zero value) and a kv
    // with no lease has no `lease` key at all (measured).
    // Sent in 7-byte chunks so the client has to reassemble a line that arrives
    // split — the event must survive that, so this frame carries a real event.
    frame(['header' => header_arr(rev(244)), 'events' => [[
        'kv' => ['key' => $k('k1'), 'create_revision' => rev(244), 'mod_revision' => rev(244), 'version' => rev(1), 'value' => $k('v1')],
    ]]], 7);

    $update = [
        'kv' => ['key' => $k('k2'), 'create_revision' => rev(244), 'mod_revision' => rev(245), 'version' => rev(2), 'value' => $k('v2')],
    ];
    if ($wantPrev) {
        $update['prev_kv'] = ['key' => $k('k2'), 'create_revision' => rev(244), 'mod_revision' => rev(244), 'version' => rev(1), 'value' => $k('v1')];
    }
    frame(['header' => header_arr(rev(245)), 'events' => [$update]]);

    // DELETE: enum name as string; the kv carries key + mod_revision only
    // (no value, no create_revision, no version) — measured.
    $delete = ['type' => 'DELETE', 'kv' => ['key' => $k('k3'), 'mod_revision' => rev(246)]];
    if ($wantPrev) {
        $delete['prev_kv'] = ['key' => $k('k3'), 'create_revision' => rev(244), 'mod_revision' => rev(245), 'version' => rev(1), 'value' => $k('v2')];
    }
    frame(['header' => header_arr(rev(246)), 'events' => [$delete]]);

    // Real etcd holds a watch open; the fixture ends it after these frames so
    // tests terminate. A client with a reconnect loop simply reconnects.
    stream_end();
    usleep(150000);
    exit;
}

if ($path === '/v3/maintenance/snapshot') {
    stream_start();

    // Deterministic, binary-looking payload so the trailer can be verified
    // without the test and the fixture sharing a constant.
    $payload = '';
    for ($i = 0; $i < SNAP_BLOCKS; $i++) {
        $payload .= hash('sha256', 'fake-snapshot-block-' . $i, true) . str_repeat("\x00", SNAP_BLOCK - 32);
    }
    $payloadLen = strlen($payload);
    // real stream ends in a 32-byte SHA-256 trailer (measured: last blob is 32 bytes)
    $chunks = array_merge(str_split($payload, SNAP_BLOCK), [hash('sha256', $payload, true)]);

    $last = count($chunks) - 1;
    foreach ($chunks as $i => $chunk) {
        $result = [];
        // measured: remaining_bytes is omitted on the last TWO frames, and it
        // counts payload only — the 32-byte trailer is not included
        if ($i < $last - 1) {
            $result['remaining_bytes'] = rev($payloadLen - ($i + 1) * SNAP_BLOCK);
        }
        $result['blob'] = base64_encode($chunk);
        frame($result);
    }
    stream_end();
    exit;
}

if ($path === '/v3/lease/keepalive') {
    stream_start();
    frame(['header' => header_arr(), 'ID' => (string) ($body['ID'] ?? '7587897800912359541'), 'TTL' => rev(60)]);
    stream_end();
    exit;
}

// --- unary routes -----------------------------------------------------------
// A key that is not in this tiny keyspace behaves like a real missing key:
// header only, no `kvs` and no `count` (both proto3 zero values) — measured.
$store = ['a' => 'b'];

$payload = match ($path) {
    '/v3/kv/range' => (static function () use ($body, $store): array {
        $key = base64_decode((string) ($body['key'] ?? ''), true);
        if ($key === false || !isset($store[$key])) {
            return ['header' => header_arr()];
        }

        return [
            'header' => header_arr(),
            'kvs'    => [[
                'key'             => base64_encode($key),
                'create_revision' => rev(243),
                'mod_revision'    => rev(243),
                'version'         => rev(1),
                'value'           => base64_encode($store[$key]),
            ]],
            'count'  => rev(1),
        ];
    })(),

    '/v3/kv/put' => (static function () use ($body): array {
        // no prev_kv unless asked; real put body is just the header (measured)
        $out = ['header' => header_arr(rev(244))];
        if (!empty($body['prev_kv'])) {
            $out['prev_kv'] = ['key' => (string) ($body['key'] ?? ''), 'mod_revision' => rev(243), 'version' => rev(1)];
        }

        return $out;
    })(),

    '/v3/kv/deleterange' => (static function () use ($body, $store): array {
        $key = base64_decode((string) ($body['key'] ?? ''), true);
        $out = ['header' => header_arr(rev(245))];
        if ($key !== false && isset($store[$key])) {
            $out['deleted'] = rev(1);
        }
        if (!empty($body['prev_kv'])) {
            $out['prev_kvs'] = [];
        }

        return $out;
    })(),

    // succeeded is a plain bool, not a string (measured)
    '/v3/kv/txn'         => ['header' => header_arr(rev(245)), 'succeeded' => true],
    '/v3/kv/compaction'  => ['header' => header_arr(rev(245))],

    '/v3/lease/grant'      => ['header' => header_arr(rev(245)), 'ID' => '7587897800912359541', 'TTL' => rev(60)],
    '/v3/lease/revoke'     => ['header' => header_arr(rev(245))],
    '/v3/lease/timetolive' => ['header' => header_arr(rev(245)), 'ID' => (string) ($body['ID'] ?? ''), 'TTL' => rev(44), 'grantedTTL' => rev(60)],
    // empty repeated field is omitted, not []
    '/v3/lease/leases'     => ['header' => header_arr()],

    '/v3/maintenance/status' => [
        'header'           => header_arr(),
        'version'          => '3.5.17',
        'dbSize'           => rev(876544),
        'leader'           => MEMBER_ID,
        'raftIndex'        => rev(316),
        'raftTerm'         => rev(2),
        'raftAppliedIndex' => rev(316),
        'dbSizeInUse'      => rev(856064),
    ],
    '/v3/maintenance/alarm'      => ['header' => header_arr()],
    // hash is uint32 -> plain JSON number, NOT a string (measured)
    '/v3/maintenance/hash'       => ['header' => header_arr(), 'hash' => 36253916],
    '/v3/maintenance/defragment' => ['header' => header_arr()],

    '/v3/cluster/member/list' => [
        'header'  => header_arr(null),
        'members' => [[
            'ID'         => MEMBER_ID,
            'name'       => 'default',
            'peerURLs'   => ['http://localhost:2380'],
            'clientURLs' => ['http://127.0.0.1:23791'],
        ]],
    ],

    '/v3/auth/status'       => ['header' => header_arr(), 'enabled' => true, 'authRevision' => rev(7)],
    '/v3/auth/enable'       => ['header' => header_arr()],
    '/v3/auth/disable'      => ['header' => header_arr()],
    '/v3/auth/user/list'    => ['header' => header_arr(), 'users' => ['root']],
    '/v3/auth/role/list'    => ['header' => header_arr()],

    default => null,
};

if ($payload === null) {
    // real etcd serves the gateway's 404 as plain text, not JSON
    // (measured: Content-Length 10 for the 9-byte "Not Found" + Go's trailing \n)
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Not Found\n";
    exit;
}

json_out($payload);
