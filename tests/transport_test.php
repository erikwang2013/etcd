<?php

declare(strict_types=1);

/**
 * Integration test for HttpTransport against a local php -S router.
 * Covers: watch streaming with partial lines, curl fallback send/sendRaw,
 * plaintext-auth guard, prefixToRangeEnd edge case.
 *
 * Run: php -d zend.assertions=1 -d assert.exception=1 tests/transport_test.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Erikwang2013\Etcd\EtcdClient;
use Erikwang2013\Etcd\Exception\ConnectionException;
use Erikwang2013\Etcd\Transport\HttpTransport;

// --- unit: prefixToRangeEnd all-0xFF prefix ---
assert(EtcdClient::prefixToRangeEnd("\xff\xff") === "\x00");

// --- unit: plaintext auth guard ---
try {
    new HttpTransport(['127.0.0.1:1'], ['auth' => ['user' => 'u', 'password' => 'p']]);
    assert(false, 'auth over http should throw');
} catch (ConnectionException) {
    // expected
}

// --- start php -S router ---
$port = random_int(20000, 40000);
$proc = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__ . '/watch_router.php'],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes
);
if (!is_resource($proc)) {
    exit("failed to start php -S\n");
}
$deadline = microtime(true) + 5;
$ready = false;
while (microtime(true) < $deadline) {
    $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
    if ($conn) {
        fclose($conn);
        $ready = true;
        break;
    }
    usleep(50000);
}
if (!$ready) {
    proc_terminate($proc);
    exit('php -S failed to start: ' . stream_get_contents($pipes[2]));
}

$transport = new HttpTransport(["127.0.0.1:{$port}"], ['scheme' => 'http', 'timeout' => 3.0, 'retry' => 0]);

// --- watch: 5 events streamed in 7-byte chunks must all arrive, in order ---
$events = [];
$stop = new class extends \Exception {};
try {
    $transport->watch('k', '', 0, function (array $evts) use (&$events, $stop) {
        $events = array_merge($events, $evts);
        if (count($events) >= 5) {
            throw $stop;
        }
    });
} catch (\Exception $e) {
    if (!$e instanceof $stop) {
        throw $e;
    }
}

assert(count($events) === 5, 'expected 5 events, got ' . count($events));
$keys = array_column(array_column($events, 'kv'), 'key');
assert($keys === ['k1', 'k2', 'k3', 'k4', 'k5'], 'keys out of order or lost: ' . json_encode($keys));
$values = array_column(array_column($events, 'kv'), 'value');
assert($values === ['v1', 'v2', 'v3', 'v4', 'v5'], 'values wrong: ' . json_encode($values));
assert(array_column($events, 'type') === ['PUT', 'PUT', 'DELETE', 'PUT', 'PUT']);

// --- send via curl fallback (no PSR-18 client): raw JSON passthrough ---
$result = $transport->send('/v3/kv/range', ['key' => base64_encode('a')]);
assert(($result['kvs'][0]['key'] ?? null) === base64_encode('a'), 'send() curl fallback decode failed: ' . json_encode($result));
assert(($result['kvs'][0]['value'] ?? null) === base64_encode('b'));

// --- full stack via EtcdClient + KvClient (base64 decoded at client layer) ---
$client = new EtcdClient(['endpoints' => ["127.0.0.1:{$port}"], 'scheme' => 'http', 'timeout' => 3.0, 'retry' => 0]);
$kv = $client->kv()->get('a');
assert(($kv['kvs'][0]['key'] ?? null) === 'a', 'KvClient decode failed: ' . json_encode($kv));
assert(($kv['kvs'][0]['value'] ?? null) === 'b');

// --- sendRaw via curl fallback ---
assert($transport->sendRaw('/v3/maintenance/snapshot') === 'SNAPSHOT-BINARY-DATA-123');

proc_terminate($proc);
echo "OK: watch events, curl fallback send/sendRaw, auth guard, prefix edge all pass\n";
