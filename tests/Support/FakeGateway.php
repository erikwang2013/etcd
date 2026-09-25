<?php

declare(strict_types=1);

namespace Erikwang2013\Etcd\Tests\Support;

/**
 * Test scaffolding for drive-by-assertion integration tests that run against
 * tests/watch_router.php, the fake etcd v3 gateway.
 */
final class FakeGateway
{
    /**
     * Values the fake gateway hands out, mirrored from tests/watch_router.php so
     * the tests can name them. The `fixture:` cases assert the gateway really
     * emits them, so drift between the two shows up as a failure rather than a
     * silently weakened assertion.
     */
    public const LEASE_ID = 7587897800912359541;

    public const MEMBER_ID = '10276657743932975437';

    /**
     * Start tests/watch_router.php under php -S and wait until it accepts.
     *
     * @param  array<string, string> $env extra environment, e.g. ETCD_FAKE_AUTH
     * @return array{0: resource, 1: string} [process, "host:port"]
     */
    public static function start(array $env = []): array
    {
        $port = random_int(20000, 40000);
        $router = dirname(__DIR__) . '/watch_router.php';
        $proc = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", $router],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env === [] ? null : $env + getenv()
        );
        if (!is_resource($proc)) {
            fwrite(STDERR, "failed to start php -S\n");
            exit(2);
        }

        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($conn) {
                fclose($conn);

                return [$proc, "127.0.0.1:{$port}"];
            }
            usleep(50000);
        }

        proc_terminate($proc);
        fwrite(STDERR, 'php -S failed to start: ' . stream_get_contents($pipes[2]) . "\n");
        exit(2);
    }

    /**
     * Raw socket POST, bypassing PHP's http wrapper so the wire format itself is
     * visible. Needed because the wrapper strips Transfer-Encoding once it
     * decides to dechunk the response.
     *
     * @param  array<string, string> $extraHeaders
     * @return array{0: int, 1: array<string, string>, 2: string} [status, headers, body]
     */
    public static function rawSocket(
        string $endpoint,
        string $path,
        string $json,
        array $extraHeaders = [],
        float $timeout = 5.0
    ): array {
        [$host, $port] = explode(':', $endpoint);
        $fp = @fsockopen($host, (int) $port, $errno, $errstr, 3.0);
        if (!$fp) {
            throw new \RuntimeException("fsockopen {$endpoint} failed: {$errstr}");
        }

        $head = "POST {$path} HTTP/1.1\r\nHost: {$host}\r\nContent-Type: application/json\r\n"
            . 'Content-Length: ' . strlen($json) . "\r\nConnection: close\r\n";
        foreach ($extraHeaders as $name => $value) {
            $head .= "{$name}: {$value}\r\n";
        }
        fwrite($fp, $head . "\r\n" . $json);

        stream_set_timeout($fp, (int) $timeout);
        $raw = '';
        $deadline = microtime(true) + $timeout;
        while (!feof($fp) && microtime(true) < $deadline) {
            $chunk = fread($fp, 8192);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $raw .= $chunk;
        }
        fclose($fp);

        $split = strpos($raw, "\r\n\r\n");
        $head = $split === false ? $raw : substr($raw, 0, $split);
        $body = $split === false ? '' : substr($raw, $split + 4);

        $headers = [];
        foreach (array_slice(explode("\r\n", $head), 1) as $line) {
            if (str_contains($line, ':')) {
                $headers[strtolower(strtok($line, ':'))] = trim(substr($line, strpos($line, ':') + 1));
            }
        }

        $status = 0;
        if (preg_match('#^HTTP/\S+\s+(\d+)#', explode("\r\n", $head)[0] ?? '', $m)) {
            $status = (int) $m[1];
        }

        return [$status, $headers, $body];
    }

    /** Undo HTTP chunked framing, so a streamed body can be inspected literally. */
    public static function dechunk(string $body): string
    {
        $out = '';
        while (true) {
            $eol = strpos($body, "\r\n");
            if ($eol === false) {
                break;
            }
            $len = (int) hexdec(trim(substr($body, 0, $eol)));
            if ($len === 0) {
                break;
            }
            $out .= substr($body, $eol + 2, $len);
            $body = substr($body, $eol + 2 + $len + 2);
        }

        return $out;
    }

    /**
     * The two things any client must be able to do with a streaming RPC: select
     * on it, and get whole frames out of it. Used to compare the fixture against
     * a real etcd, so the comparison is a measurement rather than a claim.
     *
     * @return array{select: string, frames: int, garbage: int}
     */
    public static function framingProbe(string $endpoint): array
    {
        $ctx = stream_context_create(['http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/json\r\n",
            'content' => '{"create_request":{"key":"aw=="}}',
        ]]);
        $url = "http://{$endpoint}/v3/watch";

        // (a) stream_select on a chunked response
        $select = 'ok';
        $stream = @fopen($url, 'r', false, $ctx);
        if ($stream === false) {
            return ['select' => 'fopen failed', 'frames' => 0, 'garbage' => 0];
        }
        $read = [$stream];
        $write = null;
        $except = null;
        try {
            @stream_select($read, $write, $except, 0, 200000);
        } catch (\Throwable $e) {
            $select = $e->getMessage();
        }
        fclose($stream);

        // (b) a blocking read has to yield whole NDJSON frames, no framing leftovers
        $stream = fopen($url, 'r', false, $ctx);
        stream_set_blocking($stream, true);
        stream_set_timeout($stream, 1);
        $buffer = '';
        $deadline = microtime(true) + 2.5;
        while (microtime(true) < $deadline) {
            $chunk = fread($stream, 65536);
            if ($chunk !== false && $chunk !== '') {
                $buffer .= $chunk;
            }
            $meta = stream_get_meta_data($stream);
            if ($meta['timed_out'] || $meta['eof']) {
                break;
            }
        }
        fclose($stream);

        $frames = 0;
        $garbage = 0;
        foreach (array_filter(explode("\n", $buffer), static fn($l) => trim($l) !== '') as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded) && isset($decoded['result'])) {
                $frames++;
            } else {
                $garbage++;
            }
        }

        return ['select' => $select, 'frames' => $frames, 'garbage' => $garbage];
    }
}

/**
 * Minimal assert()-based case runner.
 *
 * Every case runs even when earlier ones fail, each result is printed as it
 * happens, and a summary is returned at the end — so a broken build shows the
 * whole failure list instead of dying on the first assertion.
 */
final class Cases
{
    /** @var array<string, string> */
    private static array $failed = [];

    private static int $total = 0;

    public static function run(string $name, callable $fn): void
    {
        self::$total++;
        try {
            $fn();
            fwrite(STDOUT, "PASS  {$name}\n");
        } catch (\Throwable $e) {
            self::$failed[$name] = get_class($e) . ': ' . preg_replace('/\s+/', ' ', $e->getMessage());
            fwrite(STDOUT, "FAIL  {$name}\n        " . self::$failed[$name] . "\n");
        }
    }

    public static function summary(): int
    {
        $failed = count(self::$failed);
        fwrite(STDOUT, str_repeat('-', 74) . "\n");
        fwrite(STDOUT, sprintf("%d cases: %d PASS, %d FAIL\n", self::$total, self::$total - $failed, $failed));
        if ($failed > 0) {
            fwrite(STDOUT, "\nfailing (each is a bug this suite exists to catch):\n");
            foreach (array_keys(self::$failed) as $name) {
                fwrite(STDOUT, "  - {$name}\n");
            }
        }

        return $failed > 0 ? 1 : 0;
    }
}
