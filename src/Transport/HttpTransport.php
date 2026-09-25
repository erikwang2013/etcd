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

use Erikwang2013\Etcd\Exception\ConnectionException;
use Erikwang2013\Etcd\Exception\AuthException;
use Erikwang2013\Etcd\Exception\EtcdException;
use Erikwang2013\Etcd\Support\KeyValue;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

class HttpTransport implements TransportInterface
{
    /** Pick whatever the runtime has: cURL, then the stream wrapper, then fail with advice. */
    public const DRIVER_AUTO = 'auto';
    public const DRIVER_CURL = 'curl';
    public const DRIVER_STREAM = 'stream';

    private array $endpoints;
    private array $config;
    private ?ClientInterface $httpClient = null;
    private ?RequestFactoryInterface $requestFactory = null;
    private ?StreamFactoryInterface $streamFactory = null;
    /** @var array<string, \CurlHandle> */
    private array $curlHandles = [];
    private string $username = '';
    private string $password = '';
    /**
     * etcd v3 authenticates with a token from /v3/auth/authenticate, sent as a
     * bare `Authorization: <token>`. It does NOT accept HTTP Basic — that only
     * ever worked up to 3.4.
     */
    private ?string $token = null;

    public function __construct(array $endpoints, array $config = [])
    {
        // Accept "host:port", "http://host:port" and "[::1]:2379" alike; the
        // scheme comes from config['scheme'], so a scheme in here used to end up
        // as "http://https://host:2379" and fail DNS.
        $this->endpoints = array_values(array_map(
            static fn(string $e): string => preg_replace('#^[a-z][a-z0-9+.\-]*://#i', '', trim($e)),
            $endpoints
        ));
        $this->config = $config;
        if (!empty($config['auth']['user'] ?? null)) {
            $this->username = (string) $config['auth']['user'];
            $this->password = (string) ($config['auth']['password'] ?? '');
            if (($config['scheme'] ?? 'http') !== 'https') {
                throw new ConnectionException('Refusing to send etcd credentials over plaintext HTTP. Set scheme=https (ETCD_SCHEME=https) or leave auth empty.');
            }
        }
    }

    public function setHttpClient(ClientInterface $client, RequestFactoryInterface $requestFactory, StreamFactoryInterface $streamFactory): void
    {
        $this->httpClient = $client;
        $this->requestFactory = $requestFactory;
        $this->streamFactory = $streamFactory;
    }

    private function getHttpClient(): ClientInterface
    {
        if ($this->httpClient !== null) {
            return $this->httpClient;
        }
        throw new ConnectionException('No PSR-18 HTTP client configured. Call setHttpClient() or use a framework adapter.');
    }

    private function getRequestFactory(): RequestFactoryInterface
    {
        if ($this->requestFactory !== null) {
            return $this->requestFactory;
        }
        throw new ConnectionException('No PSR-17 request factory configured.');
    }

    private function getStreamFactory(): StreamFactoryInterface
    {
        if ($this->streamFactory !== null) {
            return $this->streamFactory;
        }
        throw new ConnectionException('No PSR-17 stream factory configured.');
    }

    /**
     * Read-only RPCs. Only these may be retried after a request that might have
     * reached the server: re-sending a compare-and-swap after a 500 or a read
     * timeout can apply it twice, and the retry then observes its own first
     * write and reports the wrong branch (measured: a CAS that won was reported
     * as failed).
     */
    /** Read timeout for the stream watch driver; it is the event latency there. */
    private const STREAM_READ_TIMEOUT_US = 200000;

    private const IDEMPOTENT = [
        '/v3/kv/range'          => true,
        '/v3/maintenance/status' => true,
        '/v3/maintenance/hash'  => true,
        '/v3/maintenance/alarm' => true,
        '/v3/cluster/member/list' => true,   // not /memberlist — that route is 404 (measured)
        '/v3/lease/timetolive'  => true,
        '/v3/lease/leases'      => true,
        '/v3/auth/status'       => true,
        '/v3/auth/authenticate' => true,
        '/v3/auth/user/get'     => true,
        '/v3/auth/user/list'    => true,
        '/v3/auth/role/get'     => true,
        '/v3/auth/role/list'    => true,
    ];

    private static function isIdempotent(string $path): bool
    {
        return isset(self::IDEMPOTENT[$path]);
    }

    /**
     * @param ?float $timeout per-call timeout in seconds; null = the configured default
     */
    public function send(string $path, array $body, ?float $timeout = null): array
    {
        // The gateway unmarshals into a Go struct: an empty PHP array encodes as
        // `[]` and is rejected with "cannot unmarshal array into Go value of
        // type map[string]json.RawMessage". Zero-argument RPCs need `{}`.
        $payload = $body === [] ? new \stdClass() : $body;
        $bodyJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $idempotent = self::isIdempotent($path);

        return $this->withRetry($path, $idempotent, function (string $url) use ($bodyJson, $path, $idempotent, $timeout): array {
            [$status, $responseBody] = $this->httpRequest($url, $bodyJson, $path, $timeout);
            try {
                $decoded = self::unwrapStream($this->decodeResponse($status, $responseBody, $idempotent));
                // An explicit authenticate() reuses whatever token this returns,
                // so AuthClient can expose the token flow without the transport
                // interface growing an HTTP-specific method.
                if ($path === '/v3/auth/authenticate' && isset($decoded['token'])) {
                    $this->token = (string) $decoded['token'];
                }
                return $decoded;
            } catch (\JsonException $e) {
                throw new EtcdException('Invalid JSON response from etcd: ' . substr($responseBody ?? '', 0, 200), previous: $e);
            }
        });
    }

    public function sendRaw(string $path, ?float $timeout = null): string
    {
        $idempotent = self::isIdempotent($path);

        return $this->withRetry($path, $idempotent, function (string $url) use ($path, $idempotent, $timeout): string {
            [$status, $responseBody] = $this->httpRequest($url, '{}', $path, $timeout);
            if ($status >= 300) {
                // 3xx means we asked the wrong address; following it silently
                // returns whatever the redirect target serves (measured: a 404
                // page returned as a successful snapshot).
                $this->throwForStatus($status, $responseBody, $path, $idempotent);
            }
            return self::assembleStream($responseBody);
        });
    }

    /**
     * Streaming RPCs that return bytes (maintenance/snapshot) arrive as NDJSON
     * frames of {"result":{"remaining_bytes":"<n>","blob":"<base64>"}}. Returning
     * that text as-is hands the caller a file `etcdctl snapshot restore` rejects,
     * so the blobs are concatenated into the real payload. The final chunk is
     * the sha256 trailer of the preceding bytes and must be included — stopping
     * when `remaining_bytes` reaches zero (it is omitted on the last two frames)
     * truncates the snapshot.
     *
     * A response that is not a stream of result frames is returned untouched.
     * ponytail: the whole payload is buffered in memory; a multi-GB snapshot
     * wants a streaming API, which the transport interface does not have yet.
     */
    private static function assembleStream(string $body): string
    {
        $trimmed = trim($body);
        if ($trimmed === '' || $trimmed[0] !== '{') {
            return $body;
        }
        $assembled = '';
        foreach (explode("\n", $trimmed) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $frame = json_decode($line, true);
            if (!is_array($frame) || !isset($frame['result'])) {
                return $body;      // not a stream of frames — hand it back as-is
            }
            $blob = $frame['result']['blob'] ?? null;
            if ($blob === null) {
                continue;          // header-only frame
            }
            $decoded = base64_decode((string) $blob, true);
            if ($decoded === false) {
                throw new EtcdException('Snapshot frame blob is not valid base64.');
            }
            $assembled .= $decoded;
        }
        if ($assembled === '') {
            throw new EtcdException('Snapshot response contained no blob frames.');
        }
        return $assembled;
    }

    /**
     * Streaming RPCs are wrapped one message per line as {"result": {...}} by
     * grpc-gateway's ForwardResponseStream. Unary replies are not wrapped, and
     * no etcd response message has a top-level `result` field, so unwrapping is
     * unambiguous.
     */
    private static function unwrapStream(array $decoded): array
    {
        $result = $decoded['result'] ?? null;
        return is_array($result) ? $result : $decoded;
    }

    /**
     * @param bool $idempotent whether re-sending this request is safe
     */
    private function withRetry(string $path, bool $idempotent, callable $fn): mixed
    {
        $retries = max(0, (int) ($this->config['retry'] ?? 2));
        $lastException = null;
        $lastEndpoint = null;

        for ($i = 0; $i <= $retries; $i++) {
            try {
                // Prefer a different endpoint than the one that just failed.
                $endpoint = $this->pickEndpoint($lastEndpoint);
                $lastEndpoint = $endpoint;
                return $fn($this->endpointUrl($path, $endpoint));
            } catch (AuthException $e) {
                throw $e;
            } catch (ConnectionException $e) {
                // Only retry when the request provably never reached etcd.
                if (!$idempotent && !$e->isRetryable()) {
                    throw $e;
                }
                $lastException = $e;
                if ($i < $retries) {
                    usleep(100000 * ($i + 1));
                }
            } catch (EtcdException $e) {
                if (!$e->isRetryable()) {
                    throw $e;
                }
                $lastException = $e;
                if ($i < $retries) {
                    usleep(100000 * ($i + 1));
                }
            }
        }

        // Preserve the last real failure — collapsing a retryable 5xx into a
        // non-retryable ConnectionException told callers the wrong thing.
        if ($lastException instanceof EtcdException) {
            throw $lastException;
        }
        $last = $lastException ? get_class($lastException) . ': ' . substr($lastException->getMessage(), 0, 120) : 'unknown error';
        throw new ConnectionException("etcd request failed after {$retries} retries ({$last})", previous: $lastException);
    }

    /**
     * Watch a key or prefix until the callback throws (that is how a caller
     * stops the loop).
     *
     * Frames are NDJSON, one `{"result": {...}}` per revision — grpc-gateway's
     * streaming envelope over a chunked response. That framing is exactly why
     * this cannot use stream_select(): PHP's http wrapper attaches a dechunk
     * filter to a chunked response, a filtered stream cannot be cast to a
     * selectable fd, so stream_select() drops it and PHP 8 throws
     * `ValueError: No stream arrays were passed` before the first event. Both
     * drivers below read blocking instead, and the driver chain is honoured —
     * watch used to bypass it and require allow_url_fopen even with curl loaded.
     */
    public function watch(string $key, string $rangeEnd, int $startRevision, callable $onEvent, array $options = []): void
    {
        $createRequest = ['key' => base64_encode($key)];
        if ($rangeEnd !== '') {
            $createRequest['range_end'] = base64_encode($rangeEnd);
        }
        if ($startRevision > 0) {
            $createRequest['start_revision'] = $startRevision;
        }
        if (!empty($options['prevKv'])) {
            $createRequest['prev_kv'] = true;
        }
        if (!empty($options['progressNotify'])) {
            $createRequest['progress_notify'] = true;
        }

        $lastRevision = $startRevision;
        $backoffMs = 100;
        $failures = 0;

        while (true) {
            $url = $this->endpointUrl('/v3/watch', $this->pickEndpoint());
            $payload = json_encode(['create_request' => $createRequest], JSON_THROW_ON_ERROR);
            $auth = $this->authHeader();
            $deliveredBefore = $lastRevision;

            try {
                if ($this->driver() === self::DRIVER_CURL) {
                    $this->watchCurl($url, $payload, $auth, $onEvent, $options, $lastRevision);
                } else {
                    $this->watchStream($url, $payload, $auth, $onEvent, $options, $lastRevision);
                }
                $failures = 0;
            } catch (AuthException | EtcdException $e) {
                // Auth failures, cancellations and compactions will not fix
                // themselves; a connection failure might.
                throw $e;
            } catch (ConnectionException $e) {
                if (++$failures > 5) {
                    throw $e;
                }
            }

            // start_revision is inclusive, so resuming at the last revision we
            // already delivered replays it. Resume one past it.
            if ($lastRevision > 0) {
                $createRequest['start_revision'] = $lastRevision + 1;
            }

            // Only back off when the reconnect delivered nothing; a long-lived
            // stream that keeps producing events reconnects immediately.
            if ($lastRevision === $deliveredBefore) {
                usleep($backoffMs * 1000);
                $backoffMs = min($backoffMs * 2, 2000);
            }
        }
    }

    /**
     * Watch over cURL: chunked responses, native streaming, no extension of the
     * stream wrapper involved.
     */
    private function watchCurl(string $url, string $payload, string $auth, callable $onEvent, array $options, int &$lastRevision): void
    {
        $headers = ['Content-Type: application/json'];
        if ($auth !== '') {
            $headers[] = $auth;
        }

        $buffer = '';
        // An exception thrown inside a curl write callback does not propagate
        // out of curl_exec() — the transfer simply keeps running, so a watch
        // could never be stopped. Capture it, abort the transfer by returning a
        // short count, and rethrow once curl_exec() returns.
        $pending = null;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => (float) ($this->config['timeout'] ?? 5.0),
            CURLOPT_TIMEOUT        => 0,        // a watch is long-lived by design
            CURLOPT_BUFFERSIZE     => 65536,
            CURLOPT_WRITEFUNCTION  => function ($handle, string $chunk) use (&$buffer, $onEvent, $options, &$lastRevision, &$pending): int {
                if ($pending !== null) {
                    return 0;
                }
                $buffer .= $chunk;
                try {
                    $this->consumeLines($buffer, $options, $onEvent, $lastRevision);
                } catch (\Throwable $e) {
                    $pending = $e;
                    return 0;   // short write aborts the transfer
                }
                return strlen($chunk);
            },
        ]);
        $this->applyCurlTlsOptions($ch);

        try {
            $result = curl_exec($ch);
            if ($pending !== null) {
                throw $pending;
            }
            if ($result === false) {
                throw new ConnectionException('watch stream failed: ' . curl_error($ch), retryable: true);
            }
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            if ($status === 401 || $status === 403) {
                throw new AuthException("Authentication failed: watch rejected with HTTP {$status}");
            }
            if ($status >= 400) {
                throw new EtcdException("Watch request failed with HTTP {$status}");
            }
        } finally {
            curl_close($ch);
        }
    }

    /**
     * Watch over the stream wrapper. `ignore_errors` is what makes a 401
     * readable here: without it fopen() returns false for any 4xx and the
     * status branch below is dead code, so bad credentials were reported as a
     * connection failure after five pointless retries.
     */
    private function watchStream(string $url, string $payload, string $auth, callable $onEvent, array $options, int &$lastRevision): void
    {
        $headers = "Content-Type: application/json\r\n";
        if ($auth !== '') {
            $headers .= $auth . "\r\n";
        }

        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => $headers,
                'content'       => $payload,
                'ignore_errors' => true,
            ],
            'ssl' => $this->config['options']['ssl'] ?? ['verify_peer' => true],
        ]);

        $stream = @fopen($url, 'r', false, $context);
        if ($stream === false) {
            $reason = error_get_last()['message'] ?? 'unknown error';
            throw new ConnectionException(
                'Failed to open watch stream to ' . $url . ': ' . $reason,
                retryable: self::isConnectFailure($reason)
            );
        }

        $status = self::statusFromHeaders($http_response_header ?? []);
        if ($status === 401 || $status === 403) {
            fclose($stream);
            throw new AuthException("Authentication failed: watch rejected with HTTP {$status}");
        }
        if ($status >= 400) {
            fclose($stream);
            throw new EtcdException("Watch request failed with HTTP {$status}");
        }

        // PHP's http wrapper buffers a chunked response and only hands over
        // what it has when the *read timeout* expires, so this value IS the
        // event latency on this driver (measured: 1s → 1.00s, 0.2s → 0.20s).
        // 200 ms trades five cheap wakeups per second while idle for sub-second
        // delivery; the cURL driver has no such latency. A connection that dies
        // without a FIN is only noticed by TCP itself.
        stream_set_blocking($stream, true);
        stream_set_timeout($stream, 0, self::STREAM_READ_TIMEOUT_US);

        $buffer = '';
        try {
            while (true) {
                $chunk = fread($stream, 65536);
                if ($chunk === false || $chunk === '') {
                    if (feof($stream)) {
                        break;      // server closed the stream → reconnect
                    }
                    continue;       // idle tick: nothing arrived within the timeout
                }
                $buffer .= $chunk;
                $this->consumeLines($buffer, $options, $onEvent, $lastRevision);
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * Split complete NDJSON frames out of the buffer and hand each to
     * handleFrame(). Only complete lines are consumed, so a frame split across
     * two reads is reassembled.
     */
    private function consumeLines(string &$buffer, array $options, callable $onEvent, int &$lastRevision): void
    {
        while (($pos = strpos($buffer, "\n")) !== false) {
            $line = trim(substr($buffer, 0, $pos));
            $buffer = substr($buffer, $pos + 1);
            if ($line === '') {
                continue;
            }
            $this->handleFrame($line, $options, $onEvent, $lastRevision);
        }
    }

    private function handleFrame(string $line, array $options, callable $onEvent, int &$lastRevision): void
    {
        $data = json_decode($line, true);
        if (!is_array($data)) {
            throw new EtcdException('Invalid watch frame: ' . substr($line, 0, 200));
        }
        if (isset($data['error'])) {
            throw new EtcdException('Watch terminated by server: ' . self::describeError($data['error']));
        }

        $result = self::unwrapStream($data);
        if (!empty($result['canceled'])) {
            throw new EtcdException(
                'Watch canceled by server'
                . (isset($result['compact_revision']) ? ' (compaction at revision ' . $result['compact_revision'] . ')' : '')
            );
        }
        if (isset($result['header']['revision'])) {
            $lastRevision = (int) $result['header']['revision'];
        }

        $events = [];
        foreach ($result['events'] ?? [] as $event) {
            $events[] = self::normalizeEvent($event);
        }

        // A frame with no events is the "created" / progress notification.
        if ($events !== [] || !empty($options['progressNotify'])) {
            $onEvent($events);
        }
    }

    /**
     * Same shape as a KV read: base64 decoded, int64 cast, so
     * `$event['kv']['version'] === 2` behaves like it does after get().
     *
     * @param array<string, mixed> $event
     * @return array{type: string, kv: array, prev_kv: ?array}
     */
    private static function normalizeEvent(array $event): array
    {
        // The gateway emits enum names ("DELETE") and omits PUT entirely (0).
        $rawType = $event['type'] ?? 'PUT';
        $prevKv = isset($event['prev_kv']) ? KeyValue::decode($event['prev_kv']) : null;

        return [
            'type'    => ($rawType === 'DELETE' || $rawType === 1) ? 'DELETE' : 'PUT',
            'kv'      => KeyValue::decode($event['kv'] ?? []),
            'prev_kv' => $prevKv,
        ];
    }

    /** Server errors may be objects; never let "(string) Array" reach the user. */
    private static function describeError(mixed $error): string
    {
        if (is_string($error)) {
            return $error;
        }
        $encoded = json_encode($error);
        return $encoded !== false ? $encoded : var_export($error, true);
    }

    /**
     * Which native HTTP driver this runtime can use.
     *
     * The two flags are injectable so the choice can be tested without
     * disabling a real extension.
     *
     * @return string self::DRIVER_CURL, self::DRIVER_STREAM, or 'none'
     */
    public static function detectDriver(?bool $hasCurl = null, ?bool $hasStreams = null): string
    {
        $hasCurl ??= \function_exists('curl_init');
        $hasStreams ??= (bool) ini_get('allow_url_fopen');

        if ($hasCurl) {
            return self::DRIVER_CURL;
        }
        if ($hasStreams) {
            return self::DRIVER_STREAM;
        }

        return 'none';
    }

    /**
     * Resolve the configured driver, honouring an explicit `driver` override
     * ('curl' or 'stream') and reporting clearly when it is not available.
     * Public so callers can log which path is in use.
     */
    public function driver(): string
    {
        $preferred = $this->config['driver'] ?? self::DRIVER_AUTO;

        if ($preferred === self::DRIVER_CURL) {
            if (!\function_exists('curl_init')) {
                throw new ConnectionException('Transport driver "curl" requested but ext-curl is not loaded.');
            }
            return self::DRIVER_CURL;
        }
        if ($preferred === self::DRIVER_STREAM) {
            if (!ini_get('allow_url_fopen')) {
                throw new ConnectionException('Transport driver "stream" requested but allow_url_fopen is disabled.');
            }
            return self::DRIVER_STREAM;
        }

        return self::detectDriver();
    }

    /**
     * Exchange credentials for an etcd v3 auth token. etcd does NOT accept HTTP
     * Basic; the token is returned by /v3/auth/authenticate and must be sent
     * bare in the Authorization header (no "Bearer" prefix).
     *
     * @return array{0: int, 1: string} [HTTP status, response body]
     */
    public function authenticate(): string
    {
        if ($this->username === '') {
            throw new AuthException('No credentials configured (auth.user / auth.password).');
        }
        $payload = json_encode(['name' => $this->username, 'password' => $this->password], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        [$status, $body] = $this->performRequest($this->endpointUrl('/v3/auth/authenticate', $this->pickEndpoint()), $payload, '');

        $data = json_decode($body, true);
        $token = is_array($data) ? ($data['token'] ?? '') : '';
        if ($status !== 200 || $token === '') {
            $message = is_array($data) ? ($data['message'] ?? $data['error'] ?? "HTTP {$status}") : "HTTP {$status}";
            throw new AuthException("etcd authentication failed: {$message}");
        }

        return $this->token = (string) $token;
    }

    private function ensureToken(): string
    {
        return $this->token ?? $this->authenticate();
    }

    /**
     * Bare token, as etcd v3 expects. Never Basic.
     *
     * Credentials configured → authenticate lazily. No credentials but a token
     * was obtained through an explicit authenticate() → attach it, otherwise
     * "authenticate once, then use the client" would silently send nothing.
     */
    private function authHeader(): string
    {
        $token = $this->token ?? ($this->username === '' ? null : $this->ensureToken());

        return $token === null ? '' : 'Authorization: ' . $token;
    }

    /**
     * @return array{0: int, 1: string} [HTTP status, response body]
     */
    private function httpRequest(string $url, string $bodyJson, string $path, ?float $timeout = null): array
    {
        [$status, $body] = $this->performRequest($url, $bodyJson, $this->authHeader(), $timeout);

        // A cached token can go stale (etcd restarted, auth revision changed).
        // Re-authenticate once and replay, but never for the auth call itself.
        if ($status === 401 && $this->username !== '' && $path !== '/v3/auth/authenticate') {
            $this->token = null;
            [$status, $body] = $this->performRequest($url, $bodyJson, $this->authHeader(), $timeout);
        }

        return [$status, $body];
    }

    /**
     * One request over whichever driver is active; no retrying, no auth
     * bookkeeping. $authHeader is '' or a complete "Authorization: ..." line.
     *
     * @return array{0: int, 1: string} [HTTP status, response body]
     */
    private function performRequest(string $url, string $bodyJson, string $authHeader, ?float $timeout = null): array
    {
        if ($this->httpClient !== null) {
            $request = $this->getRequestFactory()->createRequest('POST', $url)
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->getStreamFactory()->createStream($bodyJson));
            if ($authHeader !== '') {
                // PSR-18 callers own TLS (options.ssl does not apply here), but
                // the auth header is ours to get right.
                $request = $request->withHeader('Authorization', substr($authHeader, strlen('Authorization: ')));
            }
            try {
                $response = $this->getHttpClient()->sendRequest($request);
            } catch (NetworkExceptionInterface $e) {
                // PSR-18's network-level failure: provably nothing was served.
                throw new ConnectionException('HTTP client network failure: ' . $e->getMessage(), previous: $e, retryable: true);
            } catch (ClientExceptionInterface $e) {
                throw new ConnectionException('HTTP client failure: ' . $e->getMessage(), previous: $e);
            }
            return [$response->getStatusCode(), (string) $response->getBody()];
        }

        return match ($this->driver()) {
            self::DRIVER_CURL   => $this->curlRequest($url, $bodyJson, $authHeader, $timeout),
            self::DRIVER_STREAM => $this->streamRequest($url, $bodyJson, $authHeader, $timeout),
            default             => throw new ConnectionException(
                'No HTTP transport available: load ext-curl, set allow_url_fopen=1, '
                . 'or pass a PSR-18 client to setHttpClient().'
            ),
        };
    }

    /**
     * @return array{0: int, 1: string} [HTTP status, response body]
     */
    private function curlRequest(string $url, string $bodyJson, string $authHeader, ?float $timeout = null): array
    {
        $ch = $this->getCurlHandle($url);
        $headers = ['Content-Type: application/json'];
        if ($authHeader !== '') {
            $headers[] = $authHeader;
        }
        $timeout ??= (float) ($this->config['timeout'] ?? 5.0);
        // Milliseconds, not seconds: CURLOPT_TIMEOUT is an integer number of
        // seconds, so a sub-second timeout was silently truncated to 0, which
        // cURL reads as "no timeout at all" — the opposite of the request.
        $timeoutMs = max(1, (int) round($timeout * 1000));

        curl_setopt_array($ch, [
            CURLOPT_URL               => $url,
            CURLOPT_POSTFIELDS        => $bodyJson,
            CURLOPT_HTTPHEADER        => $headers,
            CURLOPT_RETURNTRANSFER    => true,
            CURLOPT_FOLLOWLOCATION    => false,   // a redirect means we asked the wrong address
            CURLOPT_TIMEOUT_MS        => $timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => $timeoutMs,
        ]);
        $this->applyCurlTlsOptions($ch);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $errno = curl_errno($ch);
            // Only failures that prove the request never reached etcd may be
            // retried; a timeout may have been applied server-side already.
            $retryable = in_array($errno, [CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_CONNECT], true);
            throw new ConnectionException('cURL error: ' . curl_error($ch), retryable: $retryable);
        }
        return [(int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $raw];
    }

    /**
     * Map options.ssl onto cURL. Without this the key silently did nothing on
     * the default driver, which pushed private-CA users toward disabling TLS.
     */
    private function applyCurlTlsOptions(\CurlHandle $ch): void
    {
        $ssl = $this->config['options']['ssl'] ?? null;
        if (!is_array($ssl)) {
            return;
        }
        if (array_key_exists('verify_peer', $ssl)) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, (bool) $ssl['verify_peer']);
        }
        if (array_key_exists('verify_peer_name', $ssl)) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $ssl['verify_peer_name'] ? 2 : 0);
        }
        foreach (['cafile' => CURLOPT_CAINFO, 'capath' => CURLOPT_CAPATH,
                  'local_cert' => CURLOPT_SSLCERT, 'local_pk' => CURLOPT_SSLKEY] as $key => $option) {
            if (!empty($ssl[$key])) {
                curl_setopt($ch, $option, $ssl[$key]);
            }
        }
        if (!empty($ssl['passphrase'])) {
            curl_setopt($ch, CURLOPT_KEYPASSWD, $ssl['passphrase']);
        }
    }

    /**
     * Native path for runtimes without ext-curl: PHP's own HTTP stream wrapper.
     * Same contract as curlRequest().
     *
     * @return array{0: int, 1: string} [HTTP status, response body]
     */
    private function streamRequest(string $url, string $bodyJson, string $authHeader, ?float $timeout = null): array
    {
        $headers = "Content-Type: application/json\r\n";
        if ($authHeader !== '') {
            $headers .= $authHeader . "\r\n";
        }

        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => $headers,
                'content'       => $bodyJson,
                'timeout'       => $timeout ?? (float) ($this->config['timeout'] ?? 5.0),
                // 4xx/5xx carry an etcd error message in the body; capture it
                // instead of letting file_get_contents() return false.
                'ignore_errors' => true,
            ],
            'ssl' => $this->config['options']['ssl'] ?? ['verify_peer' => true],
        ]);

        $raw = @file_get_contents($url, false, $context);
        if ($raw === false) {
            $reason = error_get_last()['message'] ?? 'unknown error';
            throw new ConnectionException(
                'Stream request to ' . $url . ' failed: ' . $reason,
                retryable: self::isConnectFailure($reason)
            );
        }

        return [self::statusFromHeaders($http_response_header ?? []), $raw];
    }

    /**
     * True only for failures that prove nothing was sent: refused/resolve/
     * unreachable. A read timeout or a TLS/cert problem is not in this set.
     */
    private static function isConnectFailure(string $reason): bool
    {
        foreach (['Connection refused', 'getaddrinfo', 'Could not resolve', 'Network is unreachable', 'Failed to connect'] as $needle) {
            if (stripos($reason, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    /** @param list<string> $headers */
    private static function statusFromHeaders(array $headers): int
    {
        foreach ($headers as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $header, $m)) {
                return (int) $m[1];
            }
        }
        return 0;
    }

    private function getCurlHandle(string $url): \CurlHandle
    {
        $host = parse_url($url, PHP_URL_HOST) . ':' . (parse_url($url, PHP_URL_PORT) ?? '');
        if (!isset($this->curlHandles[$host])) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_TCP_NODELAY, true);
            $this->curlHandles[$host] = $ch;
        }
        return $this->curlHandles[$host];
    }

    private function decodeResponse(int $status, string $responseBody, bool $idempotent = false): array
    {
        if ($status !== 200) {
            $this->throwForStatus($status, $responseBody, '', $idempotent);
        }
        return json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Single place where an HTTP status becomes a typed exception.
     */
    private function throwForStatus(int $status, string $responseBody, string $path, bool $idempotent): never
    {
        $suffix = $path === '' ? '' : ': ' . $path;
        $errData = json_decode($responseBody, true);
        if (!is_array($errData)) {
            $message = "HTTP {$status}: " . substr($responseBody, 0, 200);
        } else {
            // `error` is usually a string but the gateway can nest an object
            // there; interpolating an array produced "Array to string conversion".
            $message = self::describeError($errData['message'] ?? $errData['error'] ?? "HTTP {$status}");
        }

        if ($status === 401 || $status === 403) {
            throw new AuthException("Authentication failed{$suffix}: {$message}");
        }
        if ($status >= 500) {
            // 5xx may have been applied server-side: only replay read-only calls.
            throw new EtcdException("etcd server error{$suffix}: {$message}", retryable: $idempotent);
        }
        if ($status >= 400) {
            throw new EtcdException("etcd error{$suffix}: {$message}");
        }
        // 3xx and anything else unexpected: never treat as success.
        throw new EtcdException("unexpected HTTP {$status}{$suffix}: {$message}");
    }

    private function endpointUrl(string $path, ?string $endpoint = null): string
    {
        $scheme = $this->config['scheme'] ?? 'http';
        $host = $endpoint ?? $this->pickEndpoint();
        return "{$scheme}://{$host}{$path}";
    }

    private static function decodeB64(string $s): string
    {
        $decoded = base64_decode($s, true);
        return $decoded !== false ? $decoded : $s;
    }

    /**
     * Random pick, never repeating the endpoint that just failed when there is
     * an alternative (a memoryless random pick hits a dead node again ~12.5% of
     * the time with 2 nodes and the default 2 retries).
     */
    private function pickEndpoint(?string $avoid = null): string
    {
        if ($avoid !== null && count($this->endpoints) > 1) {
            $candidates = array_values(array_diff($this->endpoints, [$avoid]));
            if ($candidates !== []) {
                return $candidates[array_rand($candidates)];
            }
        }
        return $this->endpoints[array_rand($this->endpoints)];
    }
}
