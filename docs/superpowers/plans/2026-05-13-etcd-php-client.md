# etcd PHP Client — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a PHP 8.1+ etcd v3 client package with gRPC+HTTP dual transport, full API coverage, and adapters for Laravel/Hyperf/ThinkPHP/Webman.

**Architecture:** Single composer package `erikwang2013/etcd`. Core is a `TransportInterface` with two implementations (gRPC via compiled protobuf stubs, HTTP via JSON gRPC-gateway). Six subsystem clients (KV/Watch/Lease/Auth/Cluster/Maintenance) consume the transport. Four framework adapters register `EtcdClient` as a singleton via each framework's service container.

**Tech Stack:** PHP 8.1+, `google/protobuf` (runtime), `psr/http-client` + `psr/http-factory` (HTTP transport), `grpc/grpc` (suggested for gRPC transport), framework packages optional

---

### Task 1: Project scaffolding

**Files:**
- Create: `composer.json`
- Create: `.gitignore`
- Create: `README.md`
- Create: `src/Transport/TransportInterface.php`
- Create: `src/Exception/EtcdException.php`
- Create: `src/Exception/ConnectionException.php`
- Create: `src/Exception/AuthException.php`
- Create: `src/Exception/KeyNotFoundException.php`

- [ ] **Step 1: Write composer.json**

```json
{
    "name": "erikwang2013/etcd",
    "description": "PHP etcd v3 client with gRPC + HTTP dual transport. Supports Laravel, Hyperf, ThinkPHP, Webman.",
    "type": "library",
    "license": "MIT",
    "keywords": ["etcd", "etcd-client", "etcd-v3", "laravel", "hyperf", "thinkphp", "webman"],
    "require": {
        "php": ">=8.1",
        "psr/http-client": "^1.0",
        "psr/http-factory": "^1.0",
        "google/protobuf": "^3.0 | ^4.0"
    },
    "suggest": {
        "grpc/grpc": "For native gRPC transport (better performance, full watch streaming)"
    },
    "autoload": {
        "psr-4": {
            "Erikwang2013\\Etcd\\": "src/"
        }
    },
    "extra": {
        "laravel": {
            "providers": ["Erikwang2013\\Etcd\\Adapter\\Laravel\\ServiceProvider"],
            "aliases": {"Etcd": "Erikwang2013\\Etcd\\Adapter\\Laravel\\Facade"}
        },
        "hyperf": {
            "config": "Erikwang2013\\Etcd\\Adapter\\Hyperf\\ConfigProvider"
        }
    }
}
```

- [ ] **Step 2: Write .gitignore**

```
/vendor/
/composer.lock
/.phpunit.cache
/phpunit.xml
.DS_Store
```

- [ ] **Step 3: Write TransportInterface**

File: `src/Transport/TransportInterface.php`

```php
<?php

declare(strict_types=1);

namespace Erikwang2013\Etcd\Transport;

interface TransportInterface
{
    /**
     * Send a request to etcd server.
     *
     * @param string $path  gRPC-gateway path, e.g. '/v3/kv/put'
     * @param array  $body  JSON-encodable request body
     * @return array  JSON-decoded response body
     */
    public function send(string $path, array $body): array;

    /**
     * Open a streaming watch connection.
     *
     * @param string   $key       Key to watch (raw bytes, will be base64-encoded)
     * @param string   $rangeEnd  Range end for prefix watch (raw bytes) or ''
     * @param int      $startRevision  Revision to start watching from
     * @param callable $onEvent   Callback(array $events): void — each event: ['kv' => [...], 'type' => 'PUT'|'DELETE']
     */
    public function watch(string $key, string $rangeEnd, int $startRevision, callable $onEvent): void;
}
```

- [ ] **Step 4: Write exception classes**

File: `src/Exception/EtcdException.php`

```php
<?php

declare(strict_types=1);

namespace Erikwang2013\Etcd\Exception;

class EtcdException extends \RuntimeException
{
}
```

File: `src/Exception/ConnectionException.php`

```php
<?php

declare(strict_types=1);

namespace Erikwang2013\Etcd\Exception;

class ConnectionException extends EtcdException
{
}
```

File: `src/Exception/AuthException.php`

```php
<?php

declare(strict_types=1);

namespace Erikwang2013\Etcd\Exception;

class AuthException extends EtcdException
{
}
```

File: `src/Exception/KeyNotFoundException.php`

```php
<?php

declare(strict_types=1);

namespace Erikwang2013\Etcd\Exception;

class KeyNotFoundException extends EtcdException
{
}
```

- [ ] **Step 5: Write README.md (skeleton)**

```markdown
# erikwang2013/etcd

PHP etcd v3 client — gRPC + HTTP dual transport.

## Requirements

- PHP >= 8.1
- etcd v3.x server

## Install

composer require erikwang2013/etcd

## Quick Start

use Erikwang2013\Etcd\EtcdClient;

$etcd = new EtcdClient(['endpoints' => ['127.0.0.1:2379']]);

// Put
$etcd->kv()->put('/foo', 'bar');

// Get
$kv = $etcd->kv()->get('/foo');
echo $kv['value']; // "bar"

## Framework Integrations

See README for Laravel, Hyperf, ThinkPHP, Webman setup.
```

- [ ] **Step 6: Commit**

```bash
git add composer.json .gitignore README.md src/
git commit -m "feat: project scaffolding with interfaces and exceptions"
```

---

### Task 2: TransportSelector

**Files:**
- Create: `src/Transport/TransportSelector.php`

- [ ] **Step 1: Write TransportSelector**

```php
<?php

declare(strict_types=1);

namespace Erikwang2013\Etcd\Transport;

use Erikwang2013\Etcd\Exception\ConnectionException;

class TransportSelector
{
    /**
     * @param array{endpoints: list<string>, transport?: string, timeout?: float, retry?: int, auth?: array{user: string, password: string}, options?: array} $config
     */
    public static function select(array $config): TransportInterface
    {
        $transport = $config['transport'] ?? 'auto';
        $endpoints = $config['endpoints'] ?? ['127.0.0.1:2379'];

        if (empty($endpoints)) {
            throw new ConnectionException('No etcd endpoints configured');
        }

        if ($transport === 'grpc') {
            return new GrpcTransport($endpoints, $config);
        }

        if ($transport === 'http') {
            return new HttpTransport($endpoints, $config);
        }

        // auto: prefer gRPC if extension loaded
        if (\extension_loaded('grpc')) {
            return new GrpcTransport($endpoints, $config);
        }

        return new HttpTransport($endpoints, $config);
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add src/Transport/TransportSelector.php
git commit -m "feat: add TransportSelector with auto-detection logic"
```

---

### Task 3: HttpTransport

**Files:**
- Create: `src/Transport/HttpTransport.php`

- [ ] **Step 1: Write HttpTransport**

```php
<?php

declare(strict_types=1);

namespace Erikwang2013\Etcd\Transport;

use Erikwang2013\Etcd\Exception\ConnectionException;
use Erikwang2013\Etcd\Exception\AuthException;
use Erikwang2013\Etcd\Exception\EtcdException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

class HttpTransport implements TransportInterface
{
    private array $endpoints;
    private array $config;
    private ?ClientInterface $httpClient = null;
    private ?RequestFactoryInterface $requestFactory = null;
    private ?StreamFactoryInterface $streamFactory = null;

    public function __construct(array $endpoints, array $config = [])
    {
        $this->endpoints = $endpoints;
        $this->config = $config;
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

    public function send(string $path, array $body): array
    {
        $endpoint = $this->pickEndpoint();
        $url = "http://{$endpoint}{$path}";

        $bodyJson = \json_encode($body, JSON_UNESCAPED_SLASHES);
        $request = $this->getRequestFactory()->createRequest('POST', $url)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->getStreamFactory()->createStream($bodyJson));

        if (!empty($this->config['auth']['user'])) {
            $credentials = \base64_encode($this->config['auth']['user'] . ':' . ($this->config['auth']['password'] ?? ''));
            $request = $request->withHeader('Authorization', 'Basic ' . $credentials);
        }

        $timeout = $this->config['timeout'] ?? 5.0;
        $retries = $this->config['retry'] ?? 2;
        $lastException = null;

        for ($i = 0; $i <= $retries; $i++) {
            try {
                $response = $this->getHttpClient()->sendRequest($request);
                $responseBody = (string) $response->getBody();

                // Handle auth errors
                if ($response->getStatusCode() === 401) {
                    throw new AuthException('Authentication failed. Check credentials.');
                }

                if ($response->getStatusCode() >= 400) {
                    $errData = \json_decode($responseBody, true);
                    $message = $errData['message'] ?? $errData['error'] ?? "HTTP {$response->getStatusCode()}";
                    throw new EtcdException("etcd error: {$message}");
                }

                return \json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);

            } catch (AuthException $e) {
                throw $e; // don't retry auth failures
            } catch (EtcdException $e) {
                throw $e; // don't retry server errors
            } catch (\Throwable $e) {
                $lastException = $e;
                if ($i < $retries) {
                    \usleep(100000); // 100ms before retry
                }
            }
        }

        throw new ConnectionException(
            "Failed to connect to etcd after {$retries} retries: " . ($lastException ? $lastException->getMessage() : ''),
            previous: $lastException
        );
    }

    public function watch(string $key, string $rangeEnd, int $startRevision, callable $onEvent): void
    {
        $endpoint = $this->pickEndpoint();
        $url = "http://{$endpoint}/v3/watch";

        $createRequest = [
            'key' => \base64_encode($key),
        ];
        if ($rangeEnd !== '') {
            $createRequest['range_end'] = \base64_encode($rangeEnd);
        }
        if ($startRevision > 0) {
            $createRequest['start_revision'] = $startRevision;
        }

        $body = \json_encode(['create_request' => $createRequest]);

        $contextOpts = [
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => $body,
                'timeout' => 0, // no timeout for watch
            ],
        ];

        if (!empty($this->config['auth']['user'])) {
            $credentials = \base64_encode($this->config['auth']['user'] . ':' . ($this->config['auth']['password'] ?? ''));
            $contextOpts['http']['header'] .= "Authorization: Basic {$credentials}\r\n";
        }

        $context = \stream_context_create($contextOpts);
        $stream = @\fopen($url, 'r', false, $context);

        if (!$stream) {
            throw new ConnectionException("Failed to open watch stream to {$url}");
        }

        // Set stream to non-blocking so feof works with chunked responses
        \stream_set_blocking($stream, false);

        $buffer = '';
        $lastRevision = $startRevision;

        while (true) {
            $line = \fgets($stream);
            if ($line === false) {
                if (\feof($stream)) {
                    \fclose($stream);
                    // Reconnect from last known revision
                    $createRequest['start_revision'] = $lastRevision;
                    $body = \json_encode(['create_request' => $createRequest]);
                    $contextOpts['http']['content'] = $body;
                    $context = \stream_context_create($contextOpts);
                    $stream = @\fopen($url, 'r', false, $context);
                    if (!$stream) {
                        throw new ConnectionException("Watch reconnect failed");
                    }
                    \stream_set_blocking($stream, false);
                    continue;
                }
                \usleep(50000); // 50ms
                continue;
            }
            $line = \trim($line);
            if ($line === '') {
                continue;
            }

            $data = \json_decode($line, true);
            if ($data === null || !isset($data['result'])) {
                continue;
            }

            $result = $data['result'];

            // Track revision for reconnection
            if (isset($result['header']['revision'])) {
                $lastRevision = (int) $result['header']['revision'];
            }

            $events = [];
            foreach ($result['events'] ?? [] as $event) {
                $type = 'PUT';
                if (isset($event['type']) && $event['type'] === 1) {
                    $type = 'DELETE';
                }
                $kv = $event['kv'] ?? [];
                // Decode base64 key/value
                if (isset($kv['key'])) {
                    $kv['key'] = \base64_decode($kv['key'], true) ?: $kv['key'];
                }
                if (isset($kv['value'])) {
                    $kv['value'] = \base64_decode($kv['value'], true) ?: $kv['value'];
                }
                $events[] = ['type' => $type, 'kv' => $kv];
            }

            if (!empty($events)) {
                $onEvent($events);
            }
        }
    }

    private function pickEndpoint(): string
    {
        // Simple random for now; future: round-robin
        return $this->endpoints[\array_rand($this->endpoints)];
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add src/Transport/HttpTransport.php
git commit -m "feat: add HttpTransport with PSR-18 client and watch streaming"
```

---

### Task 4: GrpcTransport

**Files:**
- Create: `src/Transport/GrpcTransport.php`

- [ ] **Step 1: Write GrpcTransport (skeleton — requires protobuf stubs from Task 5)**

```php
<?php

declare(strict_types=1);

namespace Erikwang2013\Etcd\Transport;

use Erikwang2013\Etcd\Exception\ConnectionException;
use Erikwang2013\Etcd\Exception\AuthException;
use Erikwang2013\Etcd\Exception\EtcdException;

class GrpcTransport implements TransportInterface
{
    private array $endpoints;
    private array $config;
    private string $currentEndpoint;

    public function __construct(array $endpoints, array $config = [])
    {
        $this->endpoints = $endpoints;
        $this->config = $config;
        $this->currentEndpoint = $endpoints[0];
    }

    public function send(string $path, array $body): array
    {
        $endpoint = $this->pickEndpoint();

        // Map HTTP paths to gRPC service methods and construct protobuf messages
        // This mapping is handled by subsystem clients; here we just route
        // The actual gRPC call is made by each subsystem client using the
        // compiled stubs and a shared gRPC channel.

        $channel = $this->getChannel($endpoint);
        $timeout = (int)(($this->config['timeout'] ?? 5.0) * 1000000); // microseconds

        // Delegate to the appropriate stub client based on path prefix
        // The path determines which gRPC service to call
        // This is handled at the EtcdClient/subsystem level, not here

        return []; // Stub — actual calls are subsystem-specific
    }

    public function watch(string $key, string $rangeEnd, int $startRevision, callable $onEvent): void
    {
        // gRPC bidirectional streaming watch
        // Requires \Etcdserverpb\WatchClient from compiled stubs
        // Will be implemented once protobuf stubs are in place
    }

    /**
     * Get or create a gRPC channel to an endpoint.
     * @return mixed  \Grpc\Channel (no type hint — extension may not be loaded)
     */
    public function getChannel(string $endpoint)
    {
        static $channels = [];
        if (!isset($channels[$endpoint])) {
            $channels[$endpoint] = new \Grpc\Channel(
                $endpoint,
                ['credentials' => \Grpc\ChannelCredentials::createInsecure()]
            );
        }
        return $channels[$endpoint];
    }

    public function getCurrentEndpoint(): string
    {
        return $this->currentEndpoint;
    }

    private function pickEndpoint(): string
    {
        $this->currentEndpoint = $this->endpoints[\array_rand($this->endpoints)];
        return $this->currentEndpoint;
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add src/Transport/GrpcTransport.php
git commit -m "feat: add GrpcTransport skeleton with channel management"
```

---

### Task 5: Protobuf stubs (KV/Watch/Lease subset)

**Files:**
- Create: `src/Protobuf/Mvccpb/KeyValue.php`
- Create: `src/Protobuf/Mvccpb/Event.php`
- Create: `src/Protobuf/Etcdserverpb/PutRequest.php`
- Create: `src/Protobuf/Etcdserverpb/PutResponse.php`
- Create: `src/Protobuf/Etcdserverpb/RangeRequest.php`
- Create: `src/Protobuf/Etcdserverpb/RangeResponse.php`
- Create: `src/Protobuf/Etcdserverpb/DeleteRangeRequest.php`
- Create: `src/Protobuf/Etcdserverpb/DeleteRangeResponse.php`
- Create: `src/Protobuf/Etcdserverpb/TxnRequest.php`
- Create: `src/Protobuf/Etcdserverpb/TxnResponse.php`
- Create: `src/Protobuf/Etcdserverpb/CompactionRequest.php`
- Create: `src/Protobuf/Etcdserverpb/CompactionResponse.php`
- Create: `src/Protobuf/Etcdserverpb/WatchCreateRequest.php`
- Create: `src/Protobuf/Etcdserverpb/WatchRequest.php`
- Create: `src/Protobuf/Etcdserverpb/WatchResponse.php`
- Create: `src/Protobuf/Etcdserverpb/LeaseGrantRequest.php`
- Create: `src/Protobuf/Etcdserverpb/LeaseGrantResponse.php`
- Create: `src/Protobuf/Etcdserverpb/LeaseRevokeRequest.php`
- Create: `src/Protobuf/Etcdserverpb/LeaseRevokeResponse.php`
- Create: `src/Protobuf/Etcdserverpb/LeaseKeepAliveRequest.php`
- Create: `src/Protobuf/Etcdserverpb/LeaseKeepAliveResponse.php`
- Create: `src/Protobuf/Etcdserverpb/LeaseTimeToLiveRequest.php`
- Create: `src/Protobuf/Etcdserverpb/LeaseTimeToLiveResponse.php`
- Create: `src/Protobuf/Etcdserverpb/LeaseLeasesRequest.php`
- Create: `src/Protobuf/Etcdserverpb/LeaseLeasesResponse.php`
- Create: `src/Protobuf/Etcdserverpb/ResponseHeader.php`

- [ ] **Step 1: Write Mvccpb\KeyValue**

```php
<?php

declare(strict_types=1);

namespace Erikwang2013\Etcd\Protobuf\Mvccpb;

use Google\Protobuf\Internal\GPBType;
use Google\Protobuf\Internal\Message;
use Google\Protobuf\Internal\RepeatedField;

class KeyValue extends Message
{
    private $key;
    private $create_revision;
    private $mod_revision;
    private $version;
    private $value;
    private $lease;

    public function __construct()
    {
        $this->key = '';
        $this->create_revision = 0;
        $this->mod_revision = 0;
        $this->version = 0;
        $this->value = '';
        $this->lease = 0;
        parent::__construct();
    }

    public function getKey(): string { return $this->key; }
    public function setKey(string $var): void { $this->key = $var; }
    public function getCreateRevision(): int { return $this->create_revision; }
    public function setCreateRevision(int $var): void { $this->create_revision = $var; }
    public function getModRevision(): int { return $this->mod_revision; }
    public function setModRevision(int $var): void { $this->mod_revision = $var; }
    public function getVersion(): int { return $this->version; }
    public function setVersion(int $var): void { $this->version = $var; }
    public function getValue(): string { return $this->value; }
    public function setValue(string $var): void { $this->value = $var; }
    public function getLease(): int { return $this->lease; }
    public function setLease(int $var): void { $this->lease = $var; }
}
```

- [ ] **Step 2: Write Mvccpb\Event**

```php
<?php

declare(strict_types=1);

namespace Erikwang2013\Etcd\Protobuf\Mvccpb;

use Google\Protobuf\Internal\GPBType;
use Google\Protobuf\Internal\Message;

class Event extends Message
{
    const PUT = 0;
    const DELETE = 1;

    private $type;        // EventType
    private $kv;          // KeyValue
    private $prev_kv;     // KeyValue

    public function __construct()
    {
        $this->type = 0;
        $this->kv = null;
        $this->prev_kv = null;
        parent::__construct();
    }

    public function getType(): int { return $this->type; }
    public function setType(int $var): void { $this->type = $var; }
    public function getKv(): ?KeyValue { return $this->kv; }
    public function setKv(KeyValue $var): void { $this->kv = $var; }
    public function getPrevKv(): ?KeyValue { return $this->prev_kv; }
    public function setPrevKv(KeyValue $var): void { $this->prev_kv = $var; }
}
```

- [ ] **Step 3: Write ResponseHeader**

```php
<?php

declare(strict_types=1);

namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;

use Google\Protobuf\Internal\Message;

class ResponseHeader extends Message
{
    private $cluster_id;
    private $member_id;
    private $revision;
    private $raft_term;

    public function __construct()
    {
        $this->cluster_id = 0;
        $this->member_id = 0;
        $this->revision = 0;
        $this->raft_term = 0;
        parent::__construct();
    }

    public function getClusterId(): int { return $this->cluster_id; }
    public function setClusterId(int $var): void { $this->cluster_id = $var; }
    public function getMemberId(): int { return $this->member_id; }
    public function setMemberId(int $var): void { $this->member_id = $var; }
    public function getRevision(): int { return $this->revision; }
    public function setRevision(int $var): void { $this->revision = $var; }
    public function getRaftTerm(): int { return $this->raft_term; }
    public function setRaftTerm(int $var): void { $this->raft_term = $var; }
}
```

- [ ] **Step 4: Write KV request/response protobuf classes**

File: `src/Protobuf/Etcdserverpb/PutRequest.php`

```php
<?php
declare(strict_types=1);

namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;

use Google\Protobuf\Internal\Message;

class PutRequest extends Message
{
    private $key = '';
    private $value = '';
    private $lease = 0;
    private $prev_kv = false;
    private $ignore_value = false;
    private $ignore_lease = false;

    public function getKey(): string { return $this->key; }
    public function setKey(string $var): void { $this->key = $var; }
    public function getValue(): string { return $this->value; }
    public function setValue(string $var): void { $this->value = $var; }
    public function getLease(): int { return $this->lease; }
    public function setLease(int $var): void { $this->lease = $var; }
    public function getPrevKv(): bool { return $this->prev_kv; }
    public function setPrevKv(bool $var): void { $this->prev_kv = $var; }
    public function getIgnoreValue(): bool { return $this->ignore_value; }
    public function setIgnoreValue(bool $var): void { $this->ignore_value = $var; }
    public function getIgnoreLease(): bool { return $this->ignore_lease; }
    public function setIgnoreLease(bool $var): void { $this->ignore_lease = $var; }
}
```

File: `src/Protobuf/Etcdserverpb/PutResponse.php`

```php
<?php
declare(strict_types=1);

namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;

use Google\Protobuf\Internal\Message;
use Erikwang2013\Etcd\Protobuf\Mvccpb\KeyValue;

class PutResponse extends Message
{
    private $header;     // ResponseHeader
    private $prev_kv;    // KeyValue

    public function getHeader(): ?ResponseHeader { return $this->header; }
    public function setHeader(ResponseHeader $var): void { $this->header = $var; }
    public function getPrevKv(): ?KeyValue { return $this->prev_kv; }
    public function setPrevKv(KeyValue $var): void { $this->prev_kv = $var; }
}
```

File: `src/Protobuf/Etcdserverpb/RangeRequest.php`

```php
<?php
declare(strict_types=1);

namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;

use Google\Protobuf\Internal\Message;

class RangeRequest extends Message
{
    private $key = '';
    private $range_end = '';
    private $limit = 0;
    private $revision = 0;
    private $sort_order = 0;    // SortOrder: NONE=0, ASCEND=1, DESCEND=2
    private $sort_target = 0;   // SortTarget: KEY=0, VERSION=1, CREATE=2, MOD=3, VALUE=4
    private $serializable = false;
    private $keys_only = false;
    private $count_only = false;
    private $min_mod_revision = 0;
    private $max_mod_revision = 0;
    private $min_create_revision = 0;
    private $max_create_revision = 0;

    public function getKey(): string { return $this->key; }
    public function setKey(string $var): void { $this->key = $var; }
    public function getRangeEnd(): string { return $this->range_end; }
    public function setRangeEnd(string $var): void { $this->range_end = $var; }
    public function getLimit(): int { return $this->limit; }
    public function setLimit(int $var): void { $this->limit = $var; }
    public function getRevision(): int { return $this->revision; }
    public function setRevision(int $var): void { $this->revision = $var; }
    public function getSortOrder(): int { return $this->sort_order; }
    public function setSortOrder(int $var): void { $this->sort_order = $var; }
    public function getSortTarget(): int { return $this->sort_target; }
    public function setSortTarget(int $var): void { $this->sort_target = $var; }
    public function getSerializable(): bool { return $this->serializable; }
    public function setSerializable(bool $var): void { $this->serializable = $var; }
    public function getKeysOnly(): bool { return $this->keys_only; }
    public function setKeysOnly(bool $var): void { $this->keys_only = $var; }
    public function getCountOnly(): bool { return $this->count_only; }
    public function setCountOnly(bool $var): void { $this->count_only = $var; }
    public function getMinModRevision(): int { return $this->min_mod_revision; }
    public function setMinModRevision(int $var): void { $this->min_mod_revision = $var; }
    public function getMaxModRevision(): int { return $this->max_mod_revision; }
    public function setMaxModRevision(int $var): void { $this->max_mod_revision = $var; }
    public function getMinCreateRevision(): int { return $this->min_create_revision; }
    public function setMinCreateRevision(int $var): void { $this->min_create_revision = $var; }
    public function getMaxCreateRevision(): int { return $this->max_create_revision; }
    public function setMaxCreateRevision(int $var): void { $this->max_create_revision = $var; }
}
```

File: `src/Protobuf/Etcdserverpb/RangeResponse.php`

```php
<?php
declare(strict_types=1);

namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;

use Google\Protobuf\Internal\Message;
use Google\Protobuf\Internal\RepeatedField;
use Erikwang2013\Etcd\Protobuf\Mvccpb\KeyValue;

class RangeResponse extends Message
{
    private $header;     // ResponseHeader
    private $kvs;        // RepeatedField<KeyValue>
    private $more = false;
    private $count = 0;

    public function getHeader(): ?ResponseHeader { return $this->header; }
    public function setHeader(ResponseHeader $var): void { $this->header = $var; }
    /** @return KeyValue[] */
    public function getKvs(): RepeatedField { return $this->kvs ?? new RepeatedField(GPBType::MESSAGE, KeyValue::class); }
    public function setKvs(RepeatedField $var): void { $this->kvs = $var; }
    public function getMore(): bool { return $this->more; }
    public function setMore(bool $var): void { $this->more = $var; }
    public function getCount(): int { return $this->count; }
    public function setCount(int $var): void { $this->count = $var; }

    public function __construct()
    {
        $this->kvs = new RepeatedField(GPBType::MESSAGE, KeyValue::class);
        parent::__construct();
    }
}
```

File: `src/Protobuf/Etcdserverpb/DeleteRangeRequest.php`

```php
<?php
declare(strict_types=1);

namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;

use Google\Protobuf\Internal\Message;

class DeleteRangeRequest extends Message
{
    private $key = '';
    private $range_end = '';
    private $prev_kv = false;

    public function getKey(): string { return $this->key; }
    public function setKey(string $var): void { $this->key = $var; }
    public function getRangeEnd(): string { return $this->range_end; }
    public function setRangeEnd(string $var): void { $this->range_end = $var; }
    public function getPrevKv(): bool { return $this->prev_kv; }
    public function setPrevKv(bool $var): void { $this->prev_kv = $var; }
}
```

File: `src/Protobuf/Etcdserverpb/DeleteRangeResponse.php`

```php
<?php
declare(strict_types=1);

namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;

use Google\Protobuf\Internal\Message;
use Google\Protobuf\Internal\RepeatedField;
use Erikwang2013\Etcd\Protobuf\Mvccpb\KeyValue;

class DeleteRangeResponse extends Message
{
    private $header;
    private $deleted = 0;
    private $prev_kvs;

    public function __construct()
    {
        $this->prev_kvs = new RepeatedField(GPBType::MESSAGE, KeyValue::class);
        parent::__construct();
    }

    public function getHeader(): ?ResponseHeader { return $this->header; }
    public function setHeader(ResponseHeader $var): void { $this->header = $var; }
    public function getDeleted(): int { return $this->deleted; }
    public function setDeleted(int $var): void { $this->deleted = $var; }
    /** @return KeyValue[] */
    public function getPrevKvs(): RepeatedField { return $this->prev_kvs; }
}
```

- [ ] **Step 5: Write Txn and Compaction stubs**

File: `src/Protobuf/Etcdserverpb/TxnRequest.php`

```php
<?php
declare(strict_types=1);

namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;

use Google\Protobuf\Internal\Message;
use Google\Protobuf\Internal\RepeatedField;
use GPBMetadata\Google\Protobuf\Internal\GPBType;

class Compare extends Message
{
    private $result = 0;       // CompareResult: EQUAL=0, GREATER=1, LESS=2, NOT_EQUAL=3
    private $target = 0;       // CompareTarget: VERSION=0, CREATE=1, MOD=2, VALUE=3, LEASE=4
    private $key = '';
    private $range_end = '';
    // union: target_union
    private $version = 0;
    private $create_revision = 0;
    private $mod_revision = 0;
    private $value = '';
    private $lease = 0;

    public function getResult(): int { return $this->result; }
    public function setResult(int $var): void { $this->result = $var; }
    public function getTarget(): int { return $this->target; }
    public function setTarget(int $var): void { $this->target = $var; }
    public function getKey(): string { return $this->key; }
    public function setKey(string $var): void { $this->key = $var; }
    public function getRangeEnd(): string { return $this->range_end; }
    public function setRangeEnd(string $var): void { $this->range_end = $var; }
    public function getVersion(): int { return $this->version; }
    public function setVersion(int $var): void { $this->version = $var; }
    public function getCreateRevision(): int { return $this->create_revision; }
    public function setCreateRevision(int $var): void { $this->create_revision = $var; }
    public function getModRevision(): int { return $this->mod_revision; }
    public function setModRevision(int $var): void { $this->mod_revision = $var; }
    public function getValue(): string { return $this->value; }
    public function setValue(string $var): void { $this->value = $var; }
    public function getLease(): int { return $this->lease; }
    public function setLease(int $var): void { $this->lease = $var; }
}

class RequestOp extends Message
{
    // union: request
    const OPERAND_REQUEST_RANGE = 'request_range';
    const OPERAND_REQUEST_PUT = 'request_put';
    const OPERAND_REQUEST_DELETE_RANGE = 'request_delete_range';
    const OPERAND_REQUEST_TXN = 'request_txn';

    private $request_range;       // RangeRequest
    private $request_put;         // PutRequest
    private $request_delete_range;// DeleteRangeRequest
    private $request_txn;         // TxnRequest

    public function getRequestRange(): ?RangeRequest { return $this->request_range; }
    public function setRequestRange(RangeRequest $var): void { $this->request_range = $var; }
    public function getRequestPut(): ?PutRequest { return $this->request_put; }
    public function setRequestPut(PutRequest $var): void { $this->request_put = $var; }
    public function getRequestDeleteRange(): ?DeleteRangeRequest { return $this->request_delete_range; }
    public function setRequestDeleteRange(DeleteRangeRequest $var): void { $this->request_delete_range = $var; }
    public function getRequestTxn(): ?TxnRequest { return $this->request_txn; }
    public function setRequestTxn(TxnRequest $var): void { $this->request_txn = $var; }
}

class ResponseOp extends Message
{
    private $response_range;         // RangeResponse
    private $response_put;           // PutResponse
    private $response_delete_range;  // DeleteRangeResponse
    private $response_txn;           // TxnResponse

    public function getResponseRange(): ?RangeResponse { return $this->response_range; }
    public function setResponseRange(RangeResponse $var): void { $this->response_range = $var; }
    public function getResponsePut(): ?PutResponse { return $this->response_put; }
    public function setResponsePut(PutResponse $var): void { $this->response_put = $var; }
    public function getResponseDeleteRange(): ?DeleteRangeResponse { return $this->response_delete_range; }
    public function setResponseDeleteRange(DeleteRangeResponse $var): void { $this->response_delete_range = $var; }
    public function getResponseTxn(): ?TxnResponse { return $this->response_txn; }
    public function setResponseTxn(TxnResponse $var): void { $this->response_txn = $var; }
}

class TxnRequest extends Message
{
    private $compare;        // RepeatedField<Compare>
    private $success;        // RepeatedField<RequestOp>
    private $failure;        // RepeatedField<RequestOp>

    public function __construct()
    {
        $this->compare = new RepeatedField(GPBType::MESSAGE, Compare::class);
        $this->success = new RepeatedField(GPBType::MESSAGE, RequestOp::class);
        $this->failure = new RepeatedField(GPBType::MESSAGE, RequestOp::class);
        parent::__construct();
    }

    /** @return Compare[] */
    public function getCompare(): RepeatedField { return $this->compare; }
    /** @return RequestOp[] */
    public function getSuccess(): RepeatedField { return $this->success; }
    /** @return RequestOp[] */
    public function getFailure(): RepeatedField { return $this->failure; }
}
```

File: `src/Protobuf/Etcdserverpb/TxnResponse.php`

```php
<?php
declare(strict_types=1);

namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;

use Google\Protobuf\Internal\Message;
use Google\Protobuf\Internal\RepeatedField;

class TxnResponse extends Message
{
    private $header;
    private $succeeded = false;
    private $responses;     // RepeatedField<ResponseOp>

    public function __construct()
    {
        $this->responses = new RepeatedField(GPBType::MESSAGE, ResponseOp::class);
        parent::__construct();
    }

    public function getHeader(): ?ResponseHeader { return $this->header; }
    public function setHeader(ResponseHeader $var): void { $this->header = $var; }
    public function getSucceeded(): bool { return $this->succeeded; }
    public function setSucceeded(bool $var): void { $this->succeeded = $var; }
    /** @return ResponseOp[] */
    public function getResponses(): RepeatedField { return $this->responses; }
}
```

File: `src/Protobuf/Etcdserverpb/CompactionRequest.php`

```php
<?php
declare(strict_types=1);

namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;

use Google\Protobuf\Internal\Message;

class CompactionRequest extends Message
{
    private $revision = 0;
    private $physical = false;

    public function getRevision(): int { return $this->revision; }
    public function setRevision(int $var): void { $this->revision = $var; }
    public function getPhysical(): bool { return $this->physical; }
    public function setPhysical(bool $var): void { $this->physical = $var; }
}
```

File: `src/Protobuf/Etcdserverpb/CompactionResponse.php`

```php
<?php
declare(strict_types=1);

namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;

use Google\Protobuf\Internal\Message;

class CompactionResponse extends Message
{
    private $header;

    public function getHeader(): ?ResponseHeader { return $this->header; }
    public function setHeader(ResponseHeader $var): void { $this->header = $var; }
}
```

- [ ] **Step 6: Write Watch protobuf stubs**

File: `src/Protobuf/Etcdserverpb/WatchCreateRequest.php`

```php
<?php
declare(strict_types=1);

namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;

use Google\Protobuf\Internal\Message;

class WatchCreateRequest extends Message
{
    private $key = '';
    private $range_end = '';
    private $start_revision = 0;
    private $progress_notify = false;
    private $filters = [];     // FilterType enum values
    private $prev_kv = false;
    private $watch_id = 0;
    private $fragment = false;

    public function getKey(): string { return $this->key; }
    public function setKey(string $var): void { $this->key = $var; }
    public function getRangeEnd(): string { return $this->range_end; }
    public function setRangeEnd(string $var): void { $this->range_end = $var; }
    public function getStartRevision(): int { return $this->start_revision; }
    public function setStartRevision(int $var): void { $this->start_revision = $var; }
    public function getProgressNotify(): bool { return $this->progress_notify; }
    public function setProgressNotify(bool $var): void { $this->progress_notify = $var; }
    public function getPrevKv(): bool { return $this->prev_kv; }
    public function setPrevKv(bool $var): void { $this->prev_kv = $var; }
    public function getWatchId(): int { return $this->watch_id; }
    public function setWatchId(int $var): void { $this->watch_id = $var; }
    public function getFragment(): bool { return $this->fragment; }
    public function setFragment(bool $var): void { $this->fragment = $var; }
}
```

File: `src/Protobuf/Etcdserverpb/WatchRequest.php`

```php
<?php
declare(strict_types=1);

namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;

use Google\Protobuf\Internal\Message;

class WatchRequest extends Message
{
    private $create_request;     // WatchCreateRequest
    private $cancel_request;     // WatchCancelRequest
    private $progress_request;   // WatchProgressRequest

    public function getCreateRequest(): ?WatchCreateRequest { return $this->create_request; }
    public function setCreateRequest(WatchCreateRequest $var): void { $this->create_request = $var; }
}
```

File: `src/Protobuf/Etcdserverpb/WatchResponse.php`

```php
<?php
declare(strict_types=1);

namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;

use Google\Protobuf\Internal\Message;
use Google\Protobuf\Internal\RepeatedField;
use Erikwang2013\Etcd\Protobuf\Mvccpb\Event;

class WatchResponse extends Message
{
    private $header;
    private $watch_id = 0;
    private $created = false;
    private $canceled = false;
    private $compact_revision = 0;
    private $cancel_reason = '';
    private $fragment = false;
    private $events;        // RepeatedField<Event>

    public function __construct()
    {
        $this->events = new RepeatedField(GPBType::MESSAGE, Event::class);
        parent::__construct();
    }

    public function getHeader(): ?ResponseHeader { return $this->header; }
    public function setHeader(ResponseHeader $var): void { $this->header = $var; }
    public function getWatchId(): int { return $this->watch_id; }
    public function setWatchId(int $var): void { $this->watch_id = $var; }
    public function getCreated(): bool { return $this->created; }
    public function setCreated(bool $var): void { $this->created = $var; }
    public function getCanceled(): bool { return $this->canceled; }
    public function setCanceled(bool $var): void { $this->canceled = $var; }
    public function getCompactRevision(): int { return $this->compact_revision; }
    public function setCompactRevision(int $var): void { $this->compact_revision = $var; }
    public function getCancelReason(): string { return $this->cancel_reason; }
    public function setCancelReason(string $var): void { $this->cancel_reason = $var; }
    public function getFragment(): bool { return $this->fragment; }
    public function setFragment(bool $var): void { $this->fragment = $var; }
    /** @return Event[] */
    public function getEvents(): RepeatedField { return $this->events; }
}
```

- [ ] **Step 7: Write Lease protobuf stubs**

File: `src/Protobuf/Etcdserverpb/LeaseGrantRequest.php`

```php
<?php
declare(strict_types=1);

namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;

use Google\Protobuf\Internal\Message;

class LeaseGrantRequest extends Message
{
    private $TTL = 0;
    private $ID = 0;

    public function getTTL(): int { return $this->TTL; }
    public function setTTL(int $var): void { $this->TTL = $var; }
    public function getID(): int { return $this->ID; }
    public function setID(int $var): void { $this->ID = $var; }
}
```

File: `src/Protobuf/Etcdserverpb/LeaseGrantResponse.php`

```php
<?php
declare(strict_types=1);

namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;

use Google\Protobuf\Internal\Message;

class LeaseGrantResponse extends Message
{
    private $header;
    private $ID = 0;
    private $TTL = 0;
    private $error = '';

    public function getHeader(): ?ResponseHeader { return $this->header; }
    public function setHeader(ResponseHeader $var): void { $this->header = $var; }
    public function getID(): int { return $this->ID; }
    public function setID(int $var): void { $this->ID = $var; }
    public function getTTL(): int { return $this->TTL; }
    public function setTTL(int $var): void { $this->TTL = $var; }
    public function getError(): string { return $this->error; }
    public function setError(string $var): void { $this->error = $var; }
}
```

File: `src/Protobuf/Etcdserverpb/LeaseRevokeRequest.php`

```php
<?php
declare(strict_types=1);

namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;

use Google\Protobuf\Internal\Message;

class LeaseRevokeRequest extends Message
{
    private $ID = 0;
    public function getID(): int { return $this->ID; }
    public function setID(int $var): void { $this->ID = $var; }
}
```

File: `src/Protobuf/Etcdserverpb/LeaseRevokeResponse.php`

```php
<?php
declare(strict_types=1);

namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;

use Google\Protobuf\Internal\Message;

class LeaseRevokeResponse extends Message
{
    private $header;
    public function getHeader(): ?ResponseHeader { return $this->header; }
    public function setHeader(ResponseHeader $var): void { $this->header = $var; }
}
```

File: `src/Protobuf/Etcdserverpb/LeaseKeepAliveRequest.php`

```php
<?php
declare(strict_types=1);

namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;

use Google\Protobuf\Internal\Message;

class LeaseKeepAliveRequest extends Message
{
    private $ID = 0;
    public function getID(): int { return $this->ID; }
    public function setID(int $var): void { $this->ID = $var; }
}
```

File: `src/Protobuf/Etcdserverpb/LeaseKeepAliveResponse.php`

```php
<?php
declare(strict_types=1);

namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;

use Google\Protobuf\Internal\Message;

class LeaseKeepAliveResponse extends Message
{
    private $header;
    private $ID = 0;
    private $TTL = 0;

    public function getHeader(): ?ResponseHeader { return $this->header; }
    public function setHeader(ResponseHeader $var): void { $this->header = $var; }
    public function getID(): int { return $this->ID; }
    public function setID(int $var): void { $this->ID = $var; }
    public function getTTL(): int { return $this->TTL; }
    public function setTTL(int $var): void { $this->TTL = $var; }
}
```

File: `src/Protobuf/Etcdserverpb/LeaseTimeToLiveRequest.php`

```php
<?php
declare(strict_types=1);

namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;

use Google\Protobuf\Internal\Message;

class LeaseTimeToLiveRequest extends Message
{
    private $ID = 0;
    private $keys = false;
    public function getID(): int { return $this->ID; }
    public function setID(int $var): void { $this->ID = $var; }
    public function getKeys(): bool { return $this->keys; }
    public function setKeys(bool $var): void { $this->keys = $var; }
}
```

File: `src/Protobuf/Etcdserverpb/LeaseTimeToLiveResponse.php`

```php
<?php
declare(strict_types=1);

namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;

use Google\Protobuf\Internal\Message;
use Google\Protobuf\Internal\RepeatedField;
use GPBMetadata\Google\Protobuf\Internal\GPBType;

class LeaseTimeToLiveResponse extends Message
{
    private $header;
    private $ID = 0;
    private $TTL = 0;
    private $grantedTTL = 0;
    private $keys;

    public function __construct()
    {
        $this->keys = new RepeatedField(GPBType::BYTES, '');
        parent::__construct();
    }

    public function getHeader(): ?ResponseHeader { return $this->header; }
    public function setHeader(ResponseHeader $var): void { $this->header = $var; }
    public function getID(): int { return $this->ID; }
    public function setID(int $var): void { $this->ID = $var; }
    public function getTTL(): int { return $this->TTL; }
    public function setTTL(int $var): void { $this->TTL = $var; }
    public function getGrantedTTL(): int { return $this->grantedTTL; }
    public function setGrantedTTL(int $var): void { $this->grantedTTL = $var; }
    public function getKeys(): RepeatedField { return $this->keys; }
}
```

File: `src/Protobuf/Etcdserverpb/LeaseLeasesRequest.php`

```php
<?php
declare(strict_types=1);

namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;

use Google\Protobuf\Internal\Message;

class LeaseLeasesRequest extends Message
{
}
```

File: `src/Protobuf/Etcdserverpb/LeaseLeasesResponse.php`

```php
<?php
declare(strict_types=1);

namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;

use Google\Protobuf\Internal\Message;
use Google\Protobuf\Internal\RepeatedField;

class LeaseStatus extends Message
{
    private $ID = 0;
    public function getID(): int { return $this->ID; }
    public function setID(int $var): void { $this->ID = $var; }
}

class LeaseLeasesResponse extends Message
{
    private $header;
    private $leases;

    public function __construct()
    {
        $this->leases = new RepeatedField(GPBType::MESSAGE, LeaseStatus::class);
        parent::__construct();
    }

    public function getHeader(): ?ResponseHeader { return $this->header; }
    public function setHeader(ResponseHeader $var): void { $this->header = $var; }
    /** @return LeaseStatus[] */
    public function getLeases(): RepeatedField { return $this->leases; }
}
```

- [ ] **Step 8: Commit**

```bash
git add src/Protobuf/
git commit -m "feat: add protobuf stubs for KV, Watch, and Lease subsystems"
```

---

### Task 6: Auth, Cluster, Maintenance protobuf stubs

**Files:**
- Create: `src/Protobuf/Authpb/Permission.php`
- Create: `src/Protobuf/Authpb/User.php`
- Create: `src/Protobuf/Authpb/Role.php`
- Create: `src/Protobuf/Etcdserverpb/AuthEnableRequest.php`
- Create: `src/Protobuf/Etcdserverpb/AuthEnableResponse.php`
- Create: `src/Protobuf/Etcdserverpb/AuthDisableRequest.php`
- Create: `src/Protobuf/Etcdserverpb/AuthDisableResponse.php`
- Create: `src/Protobuf/Etcdserverpb/AuthStatusRequest.php`
- Create: `src/Protobuf/Etcdserverpb/AuthStatusResponse.php`
- Create: `src/Protobuf/Etcdserverpb/AuthUserAddRequest.php`
- Create: `src/Protobuf/Etcdserverpb/AuthUserAddResponse.php`
- Create: `src/Protobuf/Etcdserverpb/AuthUserGetRequest.php`
- Create: `src/Protobuf/Etcdserverpb/AuthUserGetResponse.php`
- Create: `src/Protobuf/Etcdserverpb/AuthUserListRequest.php`
- Create: `src/Protobuf/Etcdserverpb/AuthUserListResponse.php`
- Create: `src/Protobuf/Etcdserverpb/AuthUserDeleteRequest.php`
- Create: `src/Protobuf/Etcdserverpb/AuthUserDeleteResponse.php`
- Create: `src/Protobuf/Etcdserverpb/AuthUserChangePasswordRequest.php`
- Create: `src/Protobuf/Etcdserverpb/AuthUserChangePasswordResponse.php`
- Create: `src/Protobuf/Etcdserverpb/AuthUserGrantRoleRequest.php`
- Create: `src/Protobuf/Etcdserverpb/AuthUserGrantRoleResponse.php`
- Create: `src/Protobuf/Etcdserverpb/AuthUserRevokeRoleRequest.php`
- Create: `src/Protobuf/Etcdserverpb/AuthUserRevokeRoleResponse.php`
- Create: `src/Protobuf/Etcdserverpb/AuthRoleAddRequest.php`
- Create: `src/Protobuf/Etcdserverpb/AuthRoleAddResponse.php`
- Create: `src/Protobuf/Etcdserverpb/AuthRoleGetRequest.php`
- Create: `src/Protobuf/Etcdserverpb/AuthRoleGetResponse.php`
- Create: `src/Protobuf/Etcdserverpb/AuthRoleListRequest.php`
- Create: `src/Protobuf/Etcdserverpb/AuthRoleListResponse.php`
- Create: `src/Protobuf/Etcdserverpb/AuthRoleDeleteRequest.php`
- Create: `src/Protobuf/Etcdserverpb/AuthRoleDeleteResponse.php`
- Create: `src/Protobuf/Etcdserverpb/AuthRoleGrantPermissionRequest.php`
- Create: `src/Protobuf/Etcdserverpb/AuthRoleGrantPermissionResponse.php`
- Create: `src/Protobuf/Etcdserverpb/AuthRoleRevokePermissionRequest.php`
- Create: `src/Protobuf/Etcdserverpb/AuthRoleRevokePermissionResponse.php`
- Create: `src/Protobuf/Etcdserverpb/MemberAddRequest.php`
- Create: `src/Protobuf/Etcdserverpb/MemberAddResponse.php`
- Create: `src/Protobuf/Etcdserverpb/MemberRemoveRequest.php`
- Create: `src/Protobuf/Etcdserverpb/MemberRemoveResponse.php`
- Create: `src/Protobuf/Etcdserverpb/MemberUpdateRequest.php`
- Create: `src/Protobuf/Etcdserverpb/MemberUpdateResponse.php`
- Create: `src/Protobuf/Etcdserverpb/MemberListRequest.php`
- Create: `src/Protobuf/Etcdserverpb/MemberListResponse.php`
- Create: `src/Protobuf/Etcdserverpb/MemberPromoteRequest.php`
- Create: `src/Protobuf/Etcdserverpb/MemberPromoteResponse.php`
- Create: `src/Protobuf/Etcdserverpb/StatusRequest.php`
- Create: `src/Protobuf/Etcdserverpb/StatusResponse.php`
- Create: `src/Protobuf/Etcdserverpb/AlarmRequest.php`
- Create: `src/Protobuf/Etcdserverpb/AlarmResponse.php`
- Create: `src/Protobuf/Etcdserverpb/DefragmentRequest.php`
- Create: `src/Protobuf/Etcdserverpb/DefragmentResponse.php`
- Create: `src/Protobuf/Etcdserverpb/HashRequest.php`
- Create: `src/Protobuf/Etcdserverpb/HashResponse.php`

- [ ] **Step 1: Write Authpb stubs**

File: `src/Protobuf/Authpb/Permission.php`

```php
<?php
declare(strict_types=1);

namespace Erikwang2013\Etcd\Protobuf\Authpb;

use Google\Protobuf\Internal\Message;

class Permission extends Message
{
    const READ = 0;
    const WRITE = 1;
    const READWRITE = 2;

    private $permType = 0;
    private $key = '';
    private $range_end = '';

    public function getPermType(): int { return $this->permType; }
    public function setPermType(int $var): void { $this->permType = $var; }
    public function getKey(): string { return $this->key; }
    public function setKey(string $var): void { $this->key = $var; }
    public function getRangeEnd(): string { return $this->range_end; }
    public function setRangeEnd(string $var): void { $this->range_end = $var; }
}
```

File: `src/Protobuf/Authpb/User.php`

```php
<?php
declare(strict_types=1);

namespace Erikwang2013\Etcd\Protobuf\Authpb;

use Google\Protobuf\Internal\Message;
use Google\Protobuf\Internal\RepeatedField;
use GPBMetadata\Google\Protobuf\Internal\GPBType;

class User extends Message
{
    private $name = '';
    private $password = '';
    private $roles;

    public function __construct()
    {
        $this->roles = new RepeatedField(GPBType::STRING, '');
        parent::__construct();
    }

    public function getName(): string { return $this->name; }
    public function setName(string $var): void { $this->name = $var; }
    public function getPassword(): string { return $this->password; }
    public function setPassword(string $var): void { $this->password = $var; }
    public function getRoles(): RepeatedField { return $this->roles; }
    public function setRoles(RepeatedField $var): void { $this->roles = $var; }
}
```

File: `src/Protobuf/Authpb/Role.php`

```php
<?php
declare(strict_types=1);

namespace Erikwang2013\Etcd\Protobuf\Authpb;

use Google\Protobuf\Internal\Message;
use Google\Protobuf\Internal\RepeatedField;

class Role extends Message
{
    private $name = '';
    private $keyPermission;

    public function __construct()
    {
        $this->keyPermission = new RepeatedField(GPBType::MESSAGE, Permission::class);
        parent::__construct();
    }

    public function getName(): string { return $this->name; }
    public function setName(string $var): void { $this->name = $var; }
    /** @return Permission[] */
    public function getKeyPermission(): RepeatedField { return $this->keyPermission; }
    public function setKeyPermission(RepeatedField $var): void { $this->keyPermission = $var; }
}
```

- [ ] **Step 2: Write Auth enable/disable/status stubs**

File: `src/Protobuf/Etcdserverpb/AuthEnableRequest.php`
```php
<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;
use Google\Protobuf\Internal\Message;
class AuthEnableRequest extends Message {}
```

File: `src/Protobuf/Etcdserverpb/AuthEnableResponse.php`
```php
<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;
use Google\Protobuf\Internal\Message;
class AuthEnableResponse extends Message { private $header; public function getHeader(): ?ResponseHeader { return $this->header; } public function setHeader(ResponseHeader $var): void { $this->header = $var; } }
```

File: `src/Protobuf/Etcdserverpb/AuthDisableRequest.php`
```php
<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;
use Google\Protobuf\Internal\Message;
class AuthDisableRequest extends Message {}
```

File: `src/Protobuf/Etcdserverpb/AuthDisableResponse.php`
```php
<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;
use Google\Protobuf\Internal\Message;
class AuthDisableResponse extends Message { private $header; public function getHeader(): ?ResponseHeader { return $this->header; } public function setHeader(ResponseHeader $var): void { $this->header = $var; } }
```

File: `src/Protobuf/Etcdserverpb/AuthStatusRequest.php`
```php
<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;
use Google\Protobuf\Internal\Message;
class AuthStatusRequest extends Message {}
```

File: `src/Protobuf/Etcdserverpb/AuthStatusResponse.php`
```php
<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;
use Google\Protobuf\Internal\Message;
class AuthStatusResponse extends Message { private $header; private $enabled = false; private $authRevision = 0; public function getHeader(): ?ResponseHeader { return $this->header; } public function setHeader(ResponseHeader $var): void { $this->header = $var; } public function getEnabled(): bool { return $this->enabled; } public function setEnabled(bool $var): void { $this->enabled = $var; } public function getAuthRevision(): int { return $this->authRevision; } public function setAuthRevision(int $var): void { $this->authRevision = $var; } }
```

- [ ] **Step 3: Write Auth User CRUD stubs**

File: `src/Protobuf/Etcdserverpb/AuthUserAddRequest.php`
```php
<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;
use Google\Protobuf\Internal\Message;
class AuthUserAddRequest extends Message { private $name = ''; private $password = ''; private $options; public function getName(): string { return $this->name; } public function setName(string $var): void { $this->name = $var; } public function getPassword(): string { return $this->password; } public function setPassword(string $var): void { $this->password = $var; } }
```

File: `src/Protobuf/Etcdserverpb/AuthUserAddResponse.php`
```php
<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;
use Google\Protobuf\Internal\Message;
class AuthUserAddResponse extends Message { private $header; public function getHeader(): ?ResponseHeader { return $this->header; } public function setHeader(ResponseHeader $var): void { $this->header = $var; } }
```

File: `src/Protobuf/Etcdserverpb/AuthUserGetRequest.php`
```php
<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;
use Google\Protobuf\Internal\Message;
class AuthUserGetRequest extends Message { private $name = ''; public function getName(): string { return $this->name; } public function setName(string $var): void { $this->name = $var; } }
```

File: `src/Protobuf/Etcdserverpb/AuthUserGetResponse.php`
```php
<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;
use Google\Protobuf\Internal\Message;
use Google\Protobuf\Internal\RepeatedField;
class AuthUserGetResponse extends Message { private $header; private $roles; public function __construct() { $this->roles = new RepeatedField(9, ''); parent::__construct(); } public function getHeader(): ?ResponseHeader { return $this->header; } public function setHeader(ResponseHeader $var): void { $this->header = $var; } public function getRoles(): RepeatedField { return $this->roles; } }
```

File: `src/Protobuf/Etcdserverpb/AuthUserListRequest.php`
```php
<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;
use Google\Protobuf\Internal\Message;
class AuthUserListRequest extends Message {}
```

File: `src/Protobuf/Etcdserverpb/AuthUserListResponse.php`
```php
<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;
use Google\Protobuf\Internal\Message;
use Google\Protobuf\Internal\RepeatedField;
class AuthUserListResponse extends Message { private $header; private $users; public function __construct() { $this->users = new RepeatedField(9, ''); parent::__construct(); } public function getHeader(): ?ResponseHeader { return $this->header; } public function setHeader(ResponseHeader $var): void { $this->header = $var; } public function getUsers(): RepeatedField { return $this->users; } }
```

File: `src/Protobuf/Etcdserverpb/AuthUserDeleteRequest.php`
```php
<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;
use Google\Protobuf\Internal\Message;
class AuthUserDeleteRequest extends Message { private $name = ''; public function getName(): string { return $this->name; } public function setName(string $var): void { $this->name = $var; } }
```

File: `src/Protobuf/Etcdserverpb/AuthUserDeleteResponse.php`
```php
<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;
use Google\Protobuf\Internal\Message;
class AuthUserDeleteResponse extends Message { private $header; public function getHeader(): ?ResponseHeader { return $this->header; } public function setHeader(ResponseHeader $var): void { $this->header = $var; } }
```

File: `src/Protobuf/Etcdserverpb/AuthUserChangePasswordRequest.php`
```php
<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;
use Google\Protobuf\Internal\Message;
class AuthUserChangePasswordRequest extends Message { private $name = ''; private $password = ''; public function getName(): string { return $this->name; } public function setName(string $var): void { $this->name = $var; } public function getPassword(): string { return $this->password; } public function setPassword(string $var): void { $this->password = $var; } }
```

File: `src/Protobuf/Etcdserverpb/AuthUserChangePasswordResponse.php`
```php
<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;
use Google\Protobuf\Internal\Message;
class AuthUserChangePasswordResponse extends Message { private $header; public function getHeader(): ?ResponseHeader { return $this->header; } public function setHeader(ResponseHeader $var): void { $this->header = $var; } }
```

File: `src/Protobuf/Etcdserverpb/AuthUserGrantRoleRequest.php`
```php
<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;
use Google\Protobuf\Internal\Message;
class AuthUserGrantRoleRequest extends Message { private $user = ''; private $role = ''; public function getUser(): string { return $this->user; } public function setUser(string $var): void { $this->user = $var; } public function getRole(): string { return $this->role; } public function setRole(string $var): void { $this->role = $var; } }
```

File: `src/Protobuf/Etcdserverpb/AuthUserGrantRoleResponse.php`
```php
<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;
use Google\Protobuf\Internal\Message;
class AuthUserGrantRoleResponse extends Message { private $header; public function getHeader(): ?ResponseHeader { return $this->header; } public function setHeader(ResponseHeader $var): void { $this->header = $var; } }
```

File: `src/Protobuf/Etcdserverpb/AuthUserRevokeRoleRequest.php`
```php
<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;
use Google\Protobuf\Internal\Message;
class AuthUserRevokeRoleRequest extends Message { private $name = ''; private $role = ''; public function getName(): string { return $this->name; } public function setName(string $var): void { $this->name = $var; } public function getRole(): string { return $this->role; } public function setRole(string $var): void { $this->role = $var; } }
```

File: `src/Protobuf/Etcdserverpb/AuthUserRevokeRoleResponse.php`
```php
<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;
use Google\Protobuf\Internal\Message;
class AuthUserRevokeRoleResponse extends Message { private $header; public function getHeader(): ?ResponseHeader { return $this->header; } public function setHeader(ResponseHeader $var): void { $this->header = $var; } }
```

- [ ] **Step 4: Write Auth Role CRUD stubs**

File: `src/Protobuf/Etcdserverpb/AuthRoleAddRequest.php`
```php
<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;
use Google\Protobuf\Internal\Message;
class AuthRoleAddRequest extends Message { private $name = ''; public function getName(): string { return $this->name; } public function setName(string $var): void { $this->name = $var; } }
```

File: `src/Protobuf/Etcdserverpb/AuthRoleAddResponse.php`
```php
<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;
use Google\Protobuf\Internal\Message;
class AuthRoleAddResponse extends Message { private $header; public function getHeader(): ?ResponseHeader { return $this->header; } public function setHeader(ResponseHeader $var): void { $this->header = $var; } }
```

File: `src/Protobuf/Etcdserverpb/AuthRoleGetRequest.php`
```php
<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;
use Google\Protobuf\Internal\Message;
class AuthRoleGetRequest extends Message { private $role = ''; public function getRole(): string { return $this->role; } public function setRole(string $var): void { $this->role = $var; } }
```

File: `src/Protobuf/Etcdserverpb/AuthRoleGetResponse.php`
```php
<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;
use Google\Protobuf\Internal\Message;
use Google\Protobuf\Internal\RepeatedField;
use Erikwang2013\Etcd\Protobuf\Authpb\Permission;
class AuthRoleGetResponse extends Message {
    private $header;
    private $perm;
    public function __construct() { $this->perm = new RepeatedField(GPBType::MESSAGE, Permission::class); parent::__construct(); }
    public function getHeader(): ?ResponseHeader { return $this->header; }
    public function setHeader(ResponseHeader $var): void { $this->header = $var; }
    public function getPerm(): RepeatedField { return $this->perm; }
}
```

File: `src/Protobuf/Etcdserverpb/AuthRoleListRequest.php`
```php
<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;
use Google\Protobuf\Internal\Message;
class AuthRoleListRequest extends Message {}
```

File: `src/Protobuf/Etcdserverpb/AuthRoleListResponse.php`
```php
<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;
use Google\Protobuf\Internal\Message;
use Google\Protobuf\Internal\RepeatedField;
class AuthRoleListResponse extends Message { private $header; private $roles; public function __construct() { $this->roles = new RepeatedField(9, ''); parent::__construct(); } public function getHeader(): ?ResponseHeader { return $this->header; } public function setHeader(ResponseHeader $var): void { $this->header = $var; } public function getRoles(): RepeatedField { return $this->roles; } }
```

File: `src/Protobuf/Etcdserverpb/AuthRoleDeleteRequest.php`
```php
<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;
use Google\Protobuf\Internal\Message;
class AuthRoleDeleteRequest extends Message { private $role = ''; public function getRole(): string { return $this->role; } public function setRole(string $var): void { $this->role = $var; } }
```

File: `src/Protobuf/Etcdserverpb/AuthRoleDeleteResponse.php`
```php
<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;
use Google\Protobuf\Internal\Message;
class AuthRoleDeleteResponse extends Message { private $header; public function getHeader(): ?ResponseHeader { return $this->header; } public function setHeader(ResponseHeader $var): void { $this->header = $var; } }
```

File: `src/Protobuf/Etcdserverpb/AuthRoleGrantPermissionRequest.php`
```php
<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;
use Google\Protobuf\Internal\Message;
use Erikwang2013\Etcd\Protobuf\Authpb\Permission;
class AuthRoleGrantPermissionRequest extends Message { private $name = ''; private $perm; public function getName(): string { return $this->name; } public function setName(string $var): void { $this->name = $var; } public function getPerm(): ?Permission { return $this->perm; } public function setPerm(Permission $var): void { $this->perm = $var; } }
```

File: `src/Protobuf/Etcdserverpb/AuthRoleGrantPermissionResponse.php`
```php
<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;
use Google\Protobuf\Internal\Message;
class AuthRoleGrantPermissionResponse extends Message { private $header; public function getHeader(): ?ResponseHeader { return $this->header; } public function setHeader(ResponseHeader $var): void { $this->header = $var; } }
```

File: `src/Protobuf/Etcdserverpb/AuthRoleRevokePermissionRequest.php`
```php
<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;
use Google\Protobuf\Internal\Message;
class AuthRoleRevokePermissionRequest extends Message { private $role = ''; private $key = ''; private $range_end = ''; public function getRole(): string { return $this->role; } public function setRole(string $var): void { $this->role = $var; } public function getKey(): string { return $this->key; } public function setKey(string $var): void { $this->key = $var; } public function getRangeEnd(): string { return $this->range_end; } public function setRangeEnd(string $var): void { $this->range_end = $var; } }
```

File: `src/Protobuf/Etcdserverpb/AuthRoleRevokePermissionResponse.php`
```php
<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;
use Google\Protobuf\Internal\Message;
class AuthRoleRevokePermissionResponse extends Message { private $header; public function getHeader(): ?ResponseHeader { return $this->header; } public function setHeader(ResponseHeader $var): void { $this->header = $var; } }
```

- [ ] **Step 5: Write Cluster (Member) stubs**

File: `src/Protobuf/Etcdserverpb/Member.php` (included in MemberListResponse context)
```php
<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;
use Google\Protobuf\Internal\Message;
use Google\Protobuf\Internal\RepeatedField;
class Member extends Message {
    private $ID = 0; private $name = ''; private $peerURLs; private $clientURLs; private $isLearner = false;
    public function __construct() { $this->peerURLs = new RepeatedField(9, ''); $this->clientURLs = new RepeatedField(9, ''); parent::__construct(); }
    public function getID(): int { return $this->ID; } public function setID(int $var): void { $this->ID = $var; }
    public function getName(): string { return $this->name; } public function setName(string $var): void { $this->name = $var; }
    public function getPeerURLs(): RepeatedField { return $this->peerURLs; }
    public function getClientURLs(): RepeatedField { return $this->clientURLs; }
    public function getIsLearner(): bool { return $this->isLearner; } public function setIsLearner(bool $var): void { $this->isLearner = $var; }
}
```

File: `src/Protobuf/Etcdserverpb/MemberAddRequest.php`
```php
<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;
use Google\Protobuf\Internal\Message;
use Google\Protobuf\Internal\RepeatedField;
class MemberAddRequest extends Message {
    private $peerURLs; private $isLearner = false;
    public function __construct() { $this->peerURLs = new RepeatedField(9, ''); parent::__construct(); }
    public function getPeerURLs(): RepeatedField { return $this->peerURLs; }
    public function getIsLearner(): bool { return $this->isLearner; }
    public function setIsLearner(bool $var): void { $this->isLearner = $var; }
}
```

File: `src/Protobuf/Etcdserverpb/MemberAddResponse.php`
```php
<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;
use Google\Protobuf\Internal\Message;
use Google\Protobuf\Internal\RepeatedField;
class MemberAddResponse extends Message { private $header; private $member; private $members; public function __construct() { $this->members = new RepeatedField(GPBType::MESSAGE, Member::class); parent::__construct(); } public function getHeader(): ?ResponseHeader { return $this->header; } public function setHeader(ResponseHeader $var): void { $this->header = $var; } public function getMember(): ?Member { return $this->member; } public function setMember(Member $var): void { $this->member = $var; } public function getMembers(): RepeatedField { return $this->members; } }
```

File: `src/Protobuf/Etcdserverpb/MemberRemoveRequest.php`
```php
<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;
use Google\Protobuf\Internal\Message;
class MemberRemoveRequest extends Message { private $ID = 0; public function getID(): int { return $this->ID; } public function setID(int $var): void { $this->ID = $var; } }
```

File: `src/Protobuf/Etcdserverpb/MemberRemoveResponse.php`
```php
<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;
use Google\Protobuf\Internal\Message;
use Google\Protobuf\Internal\RepeatedField;
class MemberRemoveResponse extends Message { private $header; private $members; public function __construct() { $this->members = new RepeatedField(GPBType::MESSAGE, Member::class); parent::__construct(); } public function getHeader(): ?ResponseHeader { return $this->header; } public function setHeader(ResponseHeader $var): void { $this->header = $var; } public function getMembers(): RepeatedField { return $this->members; } }
```

File: `src/Protobuf/Etcdserverpb/MemberUpdateRequest.php`
```php
<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;
use Google\Protobuf\Internal\Message;
use Google\Protobuf\Internal\RepeatedField;
class MemberUpdateRequest extends Message { private $ID = 0; private $peerURLs; public function __construct() { $this->peerURLs = new RepeatedField(9, ''); parent::__construct(); } public function getID(): int { return $this->ID; } public function setID(int $var): void { $this->ID = $var; } public function getPeerURLs(): RepeatedField { return $this->peerURLs; } }
```

File: `src/Protobuf/Etcdserverpb/MemberUpdateResponse.php`
```php
<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;
use Google\Protobuf\Internal\Message;
use Google\Protobuf\Internal\RepeatedField;
class MemberUpdateResponse extends Message { private $header; private $members; public function __construct() { $this->members = new RepeatedField(GPBType::MESSAGE, Member::class); parent::__construct(); } public function getHeader(): ?ResponseHeader { return $this->header; } public function setHeader(ResponseHeader $var): void { $this->header = $var; } public function getMembers(): RepeatedField { return $this->members; } }
```

File: `src/Protobuf/Etcdserverpb/MemberListRequest.php`
```php
<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;
use Google\Protobuf\Internal\Message;
class MemberListRequest extends Message {}
```

File: `src/Protobuf/Etcdserverpb/MemberListResponse.php`
```php
<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;
use Google\Protobuf\Internal\Message;
use Google\Protobuf\Internal\RepeatedField;
class MemberListResponse extends Message { private $header; private $members; public function __construct() { $this->members = new RepeatedField(GPBType::MESSAGE, Member::class); parent::__construct(); } public function getHeader(): ?ResponseHeader { return $this->header; } public function setHeader(ResponseHeader $var): void { $this->header = $var; } public function getMembers(): RepeatedField { return $this->members; } }
```

File: `src/Protobuf/Etcdserverpb/MemberPromoteRequest.php`
```php
<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;
use Google\Protobuf\Internal\Message;
class MemberPromoteRequest extends Message { private $ID = 0; public function getID(): int { return $this->ID; } public function setID(int $var): void { $this->ID = $var; } }
```

File: `src/Protobuf/Etcdserverpb/MemberPromoteResponse.php`
```php
<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;
use Google\Protobuf\Internal\Message;
use Google\Protobuf\Internal\RepeatedField;
class MemberPromoteResponse extends Message { private $header; private $members; public function __construct() { $this->members = new RepeatedField(GPBType::MESSAGE, Member::class); parent::__construct(); } public function getHeader(): ?ResponseHeader { return $this->header; } public function setHeader(ResponseHeader $var): void { $this->header = $var; } public function getMembers(): RepeatedField { return $this->members; } }
```

- [ ] **Step 6: Write Maintenance stubs**

File: `src/Protobuf/Etcdserverpb/StatusRequest.php`
```php
<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;
use Google\Protobuf\Internal\Message;
class StatusRequest extends Message {}
```

File: `src/Protobuf/Etcdserverpb/StatusResponse.php`
```php
<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;
use Google\Protobuf\Internal\Message;
class StatusResponse extends Message { private $header; private $version = ''; private $dbSize = 0; private $leader = 0; private $raftIndex = 0; private $raftTerm = 0; private $raftAppliedIndex = 0; private $errors; public function __construct() { $this->errors = new RepeatedField(9, ''); parent::__construct(); } public function getHeader(): ?ResponseHeader { return $this->header; } public function setHeader(ResponseHeader $var): void { $this->header = $var; } public function getVersion(): string { return $this->version; } public function setVersion(string $var): void { $this->version = $var; } public function getDbSize(): int { return $this->dbSize; } public function setDbSize(int $var): void { $this->dbSize = $var; } public function getLeader(): int { return $this->leader; } public function setLeader(int $var): void { $this->leader = $var; } public function getRaftIndex(): int { return $this->raftIndex; } public function setRaftIndex(int $var): void { $this->raftIndex = $var; } public function getRaftTerm(): int { return $this->raftTerm; } public function setRaftTerm(int $var): void { $this->raftTerm = $var; } public function getRaftAppliedIndex(): int { return $this->raftAppliedIndex; } public function setRaftAppliedIndex(int $var): void { $this->raftAppliedIndex = $var; } public function getErrors(): RepeatedField { return $this->errors; } }
```

File: `src/Protobuf/Etcdserverpb/AlarmRequest.php`
```php
<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;
use Google\Protobuf\Internal\Message;
class AlarmRequest extends Message { const NONE = 0; const NOSPACE = 1; const CORRUPT = 2; private $action = 0; private $memberID = 0; private $alarm = 0; public function getAction(): int { return $this->action; } public function setAction(int $var): void { $this->action = $var; } public function getMemberID(): int { return $this->memberID; } public function setMemberID(int $var): void { $this->memberID = $var; } public function getAlarm(): int { return $this->alarm; } public function setAlarm(int $var): void { $this->alarm = $var; } }
```

File: `src/Protobuf/Etcdserverpb/AlarmResponse.php`
```php
<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;
use Google\Protobuf\Internal\Message;
use Google\Protobuf\Internal\RepeatedField;
class AlarmMember extends Message { private $memberID = 0; private $alarm = 0; public function getMemberID(): int { return $this->memberID; } public function setMemberID(int $var): void { $this->memberID = $var; } public function getAlarm(): int { return $this->alarm; } public function setAlarm(int $var): void { $this->alarm = $var; } }
class AlarmResponse extends Message { private $header; private $alarms; public function __construct() { $this->alarms = new RepeatedField(GPBType::MESSAGE, AlarmMember::class); parent::__construct(); } public function getHeader(): ?ResponseHeader { return $this->header; } public function setHeader(ResponseHeader $var): void { $this->header = $var; } public function getAlarms(): RepeatedField { return $this->alarms; } }
```

File: `src/Protobuf/Etcdserverpb/DefragmentRequest.php`
```php
<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;
use Google\Protobuf\Internal\Message;
class DefragmentRequest extends Message {}
```

File: `src/Protobuf/Etcdserverpb/DefragmentResponse.php`
```php
<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;
use Google\Protobuf\Internal\Message;
class DefragmentResponse extends Message { private $header; public function getHeader(): ?ResponseHeader { return $this->header; } public function setHeader(ResponseHeader $var): void { $this->header = $var; } }
```

File: `src/Protobuf/Etcdserverpb/HashRequest.php`
```php
<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;
use Google\Protobuf\Internal\Message;
class HashRequest extends Message {}
```

File: `src/Protobuf/Etcdserverpb/HashResponse.php`
```php
<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;
use Google\Protobuf\Internal\Message;
class HashResponse extends Message { private $header; private $hash = 0; public function getHeader(): ?ResponseHeader { return $this->header; } public function setHeader(ResponseHeader $var): void { $this->header = $var; } public function getHash(): int { return $this->hash; } public function setHash(int $var): void { $this->hash = $var; } }
```

- [ ] **Step 7: Commit**

```bash
git add src/Protobuf/
git commit -m "feat: add protobuf stubs for Auth, Cluster, and Maintenance subsystems"
```

---

### Task 7: KvClient

**Files:**
- Create: `src/Kv/KvClient.php`

- [ ] **Step 1: Write KvClient**

```php
<?php

declare(strict_types=1);

namespace Erikwang2013\Etcd\Kv;

use Erikwang2013\Etcd\Transport\TransportInterface;

class KvClient
{
    private TransportInterface $transport;

    public function __construct(TransportInterface $transport)
    {
        $this->transport = $transport;
    }

    /**
     * Put a key-value pair.
     *
     * @param string $key   Key (raw bytes or string)
     * @param string $value Value (raw bytes or string)
     * @param array  $options  Optional: 'lease' => int, 'prevKv' => bool, 'ignoreValue' => bool, 'ignoreLease' => bool
     * @return array  Response: ['header' => [...], 'prev_kv' => [...]|null]
     */
    public function put(string $key, string $value, array $options = []): array
    {
        $body = [
            'key'   => \base64_encode($key),
            'value' => \base64_encode($value),
        ];
        if (isset($options['lease'])) {
            $body['lease'] = $options['lease'];
        }
        if (!empty($options['prevKv'])) {
            $body['prev_kv'] = true;
        }
        if (!empty($options['ignoreValue'])) {
            $body['ignore_value'] = true;
        }
        if (!empty($options['ignoreLease'])) {
            $body['ignore_lease'] = true;
        }
        $response = $this->transport->send('/v3/kv/put', $body);
        return $this->decodePutResponse($response);
    }

    /**
     * Get a key or range of keys.
     *
     * @param string $key       Key to get (use '' with rangeEnd for prefix scan)
     * @param array  $options   Optional:
     *   - 'rangeEnd' => string    Range end for prefix scan
     *   - 'limit'    => int       Max results
     *   - 'revision' => int       Snapshot revision
     *   - 'sortOrder' => 'ascend'|'descend'|'none'
     *   - 'sortTarget' => 'key'|'version'|'create'|'mod'|'value'
     *   - 'serializable' => bool  Serializable read (no consensus, faster)
     *   - 'keysOnly'   => bool    Only return keys (no values)
     *   - 'countOnly'  => bool    Only return count
     * @return array  ['header' => [...], 'kvs' => [...], 'count' => int, 'more' => bool]
     */
    public function get(string $key, array $options = []): array
    {
        $body = ['key' => \base64_encode($key)];

        if (!empty($options['rangeEnd'])) {
            $body['range_end'] = \base64_encode($options['rangeEnd']);
        }
        if (isset($options['limit'])) {
            $body['limit'] = (int) $options['limit'];
        }
        if (isset($options['revision'])) {
            $body['revision'] = (int) $options['revision'];
        }
        if (!empty($options['sortOrder'])) {
            $orderMap = ['none' => 0, 'ascend' => 1, 'descend' => 2];
            $body['sort_order'] = $orderMap[$options['sortOrder']] ?? 0;
        }
        if (!empty($options['sortTarget'])) {
            $targetMap = ['key' => 0, 'version' => 1, 'create' => 2, 'mod' => 3, 'value' => 4];
            $body['sort_target'] = $targetMap[$options['sortTarget']] ?? 0;
        }
        if (!empty($options['serializable'])) {
            $body['serializable'] = true;
        }
        if (!empty($options['keysOnly'])) {
            $body['keys_only'] = true;
        }
        if (!empty($options['countOnly'])) {
            $body['count_only'] = true;
        }

        $response = $this->transport->send('/v3/kv/range', $body);
        return $this->decodeRangeResponse($response);
    }

    /**
     * Get all keys with a given prefix.
     *
     * @return array ['header' => [...], 'kvs' => [...], 'count' => int, 'more' => bool]
     */
    public function getByPrefix(string $prefix, array $options = []): array
    {
        // range_end = prefix with last byte incremented
        $rangeEnd = $this->prefixToRangeEnd($prefix);
        $options['rangeEnd'] = $rangeEnd;
        return $this->get($prefix, $options);
    }

    /**
     * Delete a key or range of keys.
     *
     * @param string $key     Key to delete
     * @param array  $options Optional: 'rangeEnd' => string, 'prevKv' => bool
     * @return array  ['header' => [...], 'deleted' => int, 'prev_kvs' => [...]]
     */
    public function delete(string $key, array $options = []): array
    {
        $body = ['key' => \base64_encode($key)];

        if (!empty($options['rangeEnd'])) {
            $body['range_end'] = \base64_encode($options['rangeEnd']);
        }
        if (!empty($options['prevKv'])) {
            $body['prev_kv'] = true;
        }

        $response = $this->transport->send('/v3/kv/deleterange', $body);
        return $this->decodeDeleteResponse($response);
    }

    /**
     * Delete all keys with a given prefix.
     */
    public function deleteByPrefix(string $prefix, array $options = []): array
    {
        $options['rangeEnd'] = $this->prefixToRangeEnd($prefix);
        return $this->delete($prefix, $options);
    }

    /**
     * Execute a transaction.
     *
     * @param array $compare   List of Compare objects: ['result' => 0, 'target' => 0, 'key' => '', 'version' => 0, ...]
     * @param array $success   List of RequestOp: [['request_put' => ['key' => ..., 'value' => ...]], ...]
     * @param array $failure   List of RequestOp (fallback if compare fails)
     * @return array  ['header' => [...], 'succeeded' => bool, 'responses' => [...]]
     */
    public function txn(array $compare, array $success, array $failure = []): array
    {
        $body = [
            'compare' => $this->encodeComparisons($compare),
            'success' => $this->encodeRequestOps($success),
            'failure' => $this->encodeRequestOps($failure),
        ];

        $response = $this->transport->send('/v3/kv/txn', $body);
        return $this->decodeTxnResponse($response);
    }

    /**
     * Compact the event history up to the given revision.
     * All revisions <= $revision are discarded.
     */
    public function compact(int $revision, bool $physical = false): array
    {
        $response = $this->transport->send('/v3/kv/compaction', [
            'revision' => $revision,
            'physical' => $physical,
        ]);
        return ['header' => $response['header'] ?? []];
    }

    // --- Helpers ---

    private function prefixToRangeEnd(string $prefix): string
    {
        if ($prefix === '') {
            return "\x00";
        }
        $bytes = $prefix;
        $len = \strlen($bytes);
        // Find the last byte that is not 0xFF and increment it
        for ($i = $len - 1; $i >= 0; $i--) {
            $c = \ord($bytes[$i]);
            if ($c < 0xFF) {
                return \substr($bytes, 0, $i) . \chr($c + 1);
            }
        }
        // All bytes are 0xFF — no practical range_end, return empty
        return '';
    }

    private function decodeRangeResponse(array $r): array
    {
        $kvs = [];
        foreach ($r['kvs'] ?? [] as $kv) {
            $kvs[] = $this->decodeKv($kv);
        }
        return [
            'header' => $r['header'] ?? [],
            'kvs'    => $kvs,
            'count'  => (int) ($r['count'] ?? \count($kvs)),
            'more'   => !empty($r['more']),
        ];
    }

    private function decodePutResponse(array $r): array
    {
        $prevKv = null;
        if (isset($r['prev_kv'])) {
            $prevKv = $this->decodeKv($r['prev_kv']);
        }
        return [
            'header'  => $r['header'] ?? [],
            'prev_kv' => $prevKv,
        ];
    }

    private function decodeDeleteResponse(array $r): array
    {
        $prevKvs = [];
        foreach ($r['prev_kvs'] ?? [] as $kv) {
            $prevKvs[] = $this->decodeKv($kv);
        }
        return [
            'header'   => $r['header'] ?? [],
            'deleted'  => (int) ($r['deleted'] ?? 0),
            'prev_kvs' => $prevKvs,
        ];
    }

    private function decodeTxnResponse(array $r): array
    {
        $responses = [];
        foreach ($r['responses'] ?? [] as $op) {
            if (isset($op['response_put'])) {
                $responses[] = ['type' => 'put', 'response' => $this->decodePutResponse($op['response_put'])];
            } elseif (isset($op['response_range'])) {
                $responses[] = ['type' => 'range', 'response' => $this->decodeRangeResponse($op['response_range'])];
            } elseif (isset($op['response_delete_range'])) {
                $responses[] = ['type' => 'delete', 'response' => $this->decodeDeleteResponse($op['response_delete_range'])];
            } elseif (isset($op['response_txn'])) {
                $responses[] = ['type' => 'txn', 'response' => $this->decodeTxnResponse($op['response_txn'])];
            }
        }
        return [
            'header'    => $r['header'] ?? [],
            'succeeded' => !empty($r['succeeded']),
            'responses' => $responses,
        ];
    }

    private function decodeKv(array $kv): array
    {
        return [
            'key'              => \base64_decode($kv['key'] ?? '', true) ?: ($kv['key'] ?? ''),
            'value'            => \array_key_exists('value', $kv) ? (\base64_decode($kv['value'], true) ?: $kv['value']) : null,
            'create_revision'  => (int) ($kv['create_revision'] ?? 0),
            'mod_revision'     => (int) ($kv['mod_revision'] ?? 0),
            'version'          => (int) ($kv['version'] ?? 0),
            'lease'            => (int) ($kv['lease'] ?? 0),
        ];
    }

    private function encodeComparisons(array $compares): array
    {
        return \array_map(function ($c) {
            $encoded = [
                'result' => $c['result'] ?? 0,
                'target' => $c['target'] ?? 0,
                'key'    => \base64_encode($c['key'] ?? ''),
            ];
            if (isset($c['range_end'])) {
                $encoded['range_end'] = \base64_encode($c['range_end']);
            }
            // Target union field
            switch ($c['target'] ?? 0) {
                case 0: $encoded['version'] = $c['version'] ?? 0; break;          // VERSION
                case 1: $encoded['create_revision'] = $c['create_revision'] ?? 0; break; // CREATE
                case 2: $encoded['mod_revision'] = $c['mod_revision'] ?? 0; break;    // MOD
                case 3: $encoded['value'] = \base64_encode($c['value'] ?? ''); break;  // VALUE
                case 4: $encoded['lease'] = $c['lease'] ?? 0; break;             // LEASE
            }
            return $encoded;
        }, $compares);
    }

    private function encodeRequestOps(array $ops): array
    {
        return \array_map(function ($op) {
            if (isset($op['request_put'])) {
                return ['request_put' => [
                    'key'   => \base64_encode($op['request_put']['key'] ?? ''),
                    'value' => \base64_encode($op['request_put']['value'] ?? ''),
                ] + ($op['request_put']['lease'] ?? [])];
            }
            if (isset($op['request_range'])) {
                $r = ['key' => \base64_encode($op['request_range']['key'] ?? '')];
                if (isset($op['request_range']['range_end'])) {
                    $r['range_end'] = \base64_encode($op['request_range']['range_end']);
                }
                return ['request_range' => $r];
            }
            if (isset($op['request_delete_range'])) {
                $r = ['key' => \base64_encode($op['request_delete_range']['key'] ?? '')];
                if (isset($op['request_delete_range']['range_end'])) {
                    $r['range_end'] = \base64_encode($op['request_delete_range']['range_end']);
                }
                return ['request_delete_range' => $r];
            }
            return $op;
        }, $ops);
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add src/Kv/KvClient.php
git commit -m "feat: add KvClient with put, get, delete, txn, compact"
```

---

### Task 8: WatchClient

**Files:**
- Create: `src/Watch/WatchClient.php`

- [ ] **Step 1: Write WatchClient**

```php
<?php

declare(strict_types=1);

namespace Erikwang2013\Etcd\Watch;

use Erikwang2013\Etcd\Transport\TransportInterface;

class WatchClient
{
    private TransportInterface $transport;

    public function __construct(TransportInterface $transport)
    {
        $this->transport = $transport;
    }

    /**
     * Watch a key for changes. Blocks until the caller stops.
     *
     * @param string   $key           Key to watch (use '' for all keys with rangeEnd)
     * @param callable $onEvent       Called with each batch of events: function(array $events): void
     *                                Each event: ['type' => 'PUT'|'DELETE', 'kv' => [...], 'prev_kv' => [...]|null]
     * @param array    $options
     *   - 'rangeEnd'       => string   Range end for prefix watch
     *   - 'startRevision'  => int      Revision to start from
     *   - 'prevKv'         => bool     Return previous KV on DELETE
     *   - 'progressNotify' => bool     Periodic empty events
     *   - 'filters'        => array    ['noPut'|'noDelete']
     */
    public function watch(string $key, callable $onEvent, array $options = []): void
    {
        $rangeEnd = $options['rangeEnd'] ?? '';
        $startRevision = $options['startRevision'] ?? 0;
        $this->transport->watch($key, $rangeEnd, $startRevision, $onEvent);
    }

    /**
     * Watch a prefix for changes.
     */
    public function watchPrefix(string $prefix, callable $onEvent, array $options = []): void
    {
        // Calculate rangeEnd for prefix
        if ($prefix === '') {
            $options['rangeEnd'] = "\x00";
        } else {
            $options['rangeEnd'] = $this->prefixToRangeEnd($prefix);
        }
        $this->watch($prefix, $onEvent, $options);
    }

    private function prefixToRangeEnd(string $prefix): string
    {
        if ($prefix === '') {
            return "\x00";
        }
        $bytes = $prefix;
        $len = \strlen($bytes);
        for ($i = $len - 1; $i >= 0; $i--) {
            $c = \ord($bytes[$i]);
            if ($c < 0xFF) {
                return \substr($bytes, 0, $i) . \chr($c + 1);
            }
        }
        return '';
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add src/Watch/WatchClient.php
git commit -m "feat: add WatchClient with key and prefix watch"
```

---

### Task 9: LeaseClient

**Files:**
- Create: `src/Lease/LeaseClient.php`

- [ ] **Step 1: Write LeaseClient**

```php
<?php

declare(strict_types=1);

namespace Erikwang2013\Etcd\Lease;

use Erikwang2013\Etcd\Transport\TransportInterface;

class LeaseClient
{
    private TransportInterface $transport;

    public function __construct(TransportInterface $transport)
    {
        $this->transport = $transport;
    }

    /**
     * Grant a lease with a TTL in seconds.
     *
     * @return array ['header' => [...], 'ID' => int, 'TTL' => int]
     */
    public function grant(int $ttl, int $id = 0): array
    {
        $body = ['TTL' => $ttl];
        if ($id > 0) {
            $body['ID'] = $id;
        }
        $response = $this->transport->send('/v3/lease/grant', $body);
        return [
            'header' => $response['header'] ?? [],
            'ID'     => (int) ($response['ID'] ?? 0),
            'TTL'    => (int) ($response['TTL'] ?? $ttl),
        ];
    }

    /**
     * Revoke a lease. All keys attached to the lease will be deleted.
     *
     * @return array ['header' => [...]]
     */
    public function revoke(int $id): array
    {
        $response = $this->transport->send('/v3/lease/revoke', ['ID' => $id]);
        return ['header' => $response['header'] ?? []];
    }

    /**
     * Keep a lease alive with a single keep-alive request.
     * For continuous keep-alive, call this periodically.
     *
     * @return array ['header' => [...], 'ID' => int, 'TTL' => int]
     */
    public function keepAlive(int $id): array
    {
        $response = $this->transport->send('/v3/lease/keepalive', ['ID' => $id]);
        return [
            'header' => $response['header'] ?? [],
            'ID'     => (int) ($response['ID'] ?? 0),
            'TTL'    => (int) ($response['TTL'] ?? 0),
        ];
    }

    /**
     * Get TTL and attached keys for a lease.
     *
     * @return array ['header' => [...], 'ID' => int, 'TTL' => int, 'grantedTTL' => int, 'keys' => list<string>]
     */
    public function timeToLive(int $id, bool $keys = false): array
    {
        $response = $this->transport->send('/v3/lease/timetolive', [
            'ID'   => $id,
            'keys' => $keys,
        ]);
        $decodedKeys = [];
        foreach ($response['keys'] ?? [] as $k) {
            $decodedKeys[] = \base64_decode($k, true) ?: $k;
        }
        return [
            'header'     => $response['header'] ?? [],
            'ID'         => (int) ($response['ID'] ?? 0),
            'TTL'        => (int) ($response['TTL'] ?? 0),
            'grantedTTL' => (int) ($response['grantedTTL'] ?? 0),
            'keys'       => $decodedKeys,
        ];
    }

    /**
     * List all active leases.
     *
     * @return array ['header' => [...], 'leases' => [['ID' => int], ...]]
     */
    public function list(): array
    {
        $response = $this->transport->send('/v3/lease/leases', []);
        $leases = [];
        foreach ($response['leases'] ?? [] as $ls) {
            $leases[] = ['ID' => (int) ($ls['ID'] ?? 0)];
        }
        return [
            'header' => $response['header'] ?? [],
            'leases' => $leases,
        ];
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add src/Lease/LeaseClient.php
git commit -m "feat: add LeaseClient with grant, revoke, keepAlive, timeToLive, list"
```

---

### Task 10: AuthClient, UserClient, RoleClient

**Files:**
- Create: `src/Auth/AuthClient.php`

- [ ] **Step 1: Write AuthClient (Auth enable/disable/status)**

```php
<?php

declare(strict_types=1);

namespace Erikwang2013\Etcd\Auth;

use Erikwang2013\Etcd\Transport\TransportInterface;

class AuthClient
{
    private TransportInterface $transport;

    public function __construct(TransportInterface $transport)
    {
        $this->transport = $transport;
    }

    /** Get the UserClient instance. */
    public function user(): UserClient
    {
        return new UserClient($this->transport);
    }

    /** Get the RoleClient instance. */
    public function role(): RoleClient
    {
        return new RoleClient($this->transport);
    }

    /**
     * Enable authentication.
     *
     * @return array ['header' => [...]]
     */
    public function enable(): array
    {
        $response = $this->transport->send('/v3/auth/auth/enable', []);
        return ['header' => $response['header'] ?? []];
    }

    /**
     * Disable authentication.
     *
     * @return array ['header' => [...]]
     */
    public function disable(): array
    {
        $response = $this->transport->send('/v3/auth/auth/disable', []);
        return ['header' => $response['header'] ?? []];
    }

    /**
     * Get authentication status.
     *
     * @return array ['header' => [...], 'enabled' => bool, 'authRevision' => int]
     */
    public function status(): array
    {
        $response = $this->transport->send('/v3/auth/auth/status', []);
        return [
            'header'       => $response['header'] ?? [],
            'enabled'      => !empty($response['enabled']),
            'authRevision' => (int) ($response['authRevision'] ?? 0),
        ];
    }
}
```

- [ ] **Step 2: Write UserClient**

File: `src/Auth/UserClient.php`

```php
<?php

declare(strict_types=1);

namespace Erikwang2013\Etcd\Auth;

use Erikwang2013\Etcd\Transport\TransportInterface;

class UserClient
{
    private TransportInterface $transport;

    public function __construct(TransportInterface $transport)
    {
        $this->transport = $transport;
    }

    /**
     * Add a user.
     *
     * @return array ['header' => [...]]
     */
    public function add(string $name, string $password): array
    {
        $response = $this->transport->send('/v3/auth/user/add', [
            'name'     => $name,
            'password' => $password,
        ]);
        return ['header' => $response['header'] ?? []];
    }

    /**
     * Get user details (roles).
     *
     * @return array ['header' => [...], 'roles' => list<string>]
     */
    public function get(string $name): array
    {
        $response = $this->transport->send('/v3/auth/user/get', ['name' => $name]);
        return [
            'header' => $response['header'] ?? [],
            'roles'  => $response['roles'] ?? [],
        ];
    }

    /**
     * List all users.
     *
     * @return array ['header' => [...], 'users' => list<string>]
     */
    public function list(): array
    {
        $response = $this->transport->send('/v3/auth/user/list', []);
        return [
            'header' => $response['header'] ?? [],
            'users'  => $response['users'] ?? [],
        ];
    }

    /**
     * Delete a user.
     *
     * @return array ['header' => [...]]
     */
    public function delete(string $name): array
    {
        $response = $this->transport->send('/v3/auth/user/delete', ['name' => $name]);
        return ['header' => $response['header'] ?? []];
    }

    /**
     * Change a user's password.
     *
     * @return array ['header' => [...]]
     */
    public function changePassword(string $name, string $password): array
    {
        $response = $this->transport->send('/v3/auth/user/changepw', [
            'name'     => $name,
            'password' => $password,
        ]);
        return ['header' => $response['header'] ?? []];
    }

    /**
     * Grant a role to a user.
     *
     * @return array ['header' => [...]]
     */
    public function grantRole(string $user, string $role): array
    {
        $response = $this->transport->send('/v3/auth/user/grant', [
            'user' => $user,
            'role' => $role,
        ]);
        return ['header' => $response['header'] ?? []];
    }

    /**
     * Revoke a role from a user.
     *
     * @return array ['header' => [...]]
     */
    public function revokeRole(string $user, string $role): array
    {
        $response = $this->transport->send('/v3/auth/user/revoke', [
            'name' => $user,
            'role' => $role,
        ]);
        return ['header' => $response['header'] ?? []];
    }
}
```

- [ ] **Step 3: Write RoleClient**

File: `src/Auth/RoleClient.php`

```php
<?php

declare(strict_types=1);

namespace Erikwang2013\Etcd\Auth;

use Erikwang2013\Etcd\Transport\TransportInterface;

class RoleClient
{
    private TransportInterface $transport;

    public function __construct(TransportInterface $transport)
    {
        $this->transport = $transport;
    }

    /**
     * Add a role.
     *
     * @return array ['header' => [...]]
     */
    public function add(string $name): array
    {
        $response = $this->transport->send('/v3/auth/role/add', ['name' => $name]);
        return ['header' => $response['header'] ?? []];
    }

    /**
     * Get role details (permissions).
     *
     * @return array ['header' => [...], 'perm' => [['permType' => int, 'key' => string, 'range_end' => string], ...]]
     */
    public function get(string $role): array
    {
        $response = $this->transport->send('/v3/auth/role/get', ['role' => $role]);
        $perms = [];
        foreach ($response['perm'] ?? [] as $p) {
            $perms[] = [
                'permType'  => (int) ($p['permType'] ?? 0),
                'key'       => \base64_decode($p['key'] ?? '', true) ?: ($p['key'] ?? ''),
                'range_end' => \base64_decode($p['range_end'] ?? '', true) ?: ($p['range_end'] ?? ''),
            ];
        }
        return [
            'header' => $response['header'] ?? [],
            'perm'   => $perms,
        ];
    }

    /**
     * List all roles.
     *
     * @return array ['header' => [...], 'roles' => list<string>]
     */
    public function list(): array
    {
        $response = $this->transport->send('/v3/auth/role/list', []);
        return [
            'header' => $response['header'] ?? [],
            'roles'  => $response['roles'] ?? [],
        ];
    }

    /**
     * Delete a role.
     *
     * @return array ['header' => [...]]
     */
    public function delete(string $role): array
    {
        $response = $this->transport->send('/v3/auth/role/delete', ['role' => $role]);
        return ['header' => $response['header'] ?? []];
    }

    /**
     * Grant a permission to a role.
     *
     * @param string $role     Role name
     * @param int    $permType  0=READ, 1=WRITE, 2=READWRITE
     * @param string $key      Key pattern
     * @param string $rangeEnd Range end (empty for exact match)
     * @return array ['header' => [...]]
     */
    public function grantPermission(string $role, int $permType, string $key, string $rangeEnd = ''): array
    {
        $perm = [
            'permType' => $permType,
            'key'      => \base64_encode($key),
        ];
        if ($rangeEnd !== '') {
            $perm['range_end'] = \base64_encode($rangeEnd);
        }
        $response = $this->transport->send('/v3/auth/role/grant', [
            'name' => $role,
            'perm' => $perm,
        ]);
        return ['header' => $response['header'] ?? []];
    }

    /**
     * Revoke a permission from a role.
     *
     * @return array ['header' => [...]]
     */
    public function revokePermission(string $role, string $key, string $rangeEnd = ''): array
    {
        $body = [
            'role' => $role,
            'key'  => \base64_encode($key),
        ];
        if ($rangeEnd !== '') {
            $body['range_end'] = \base64_encode($rangeEnd);
        }
        $response = $this->transport->send('/v3/auth/role/revoke', $body);
        return ['header' => $response['header'] ?? []];
    }
}
```

- [ ] **Step 4: Commit**

```bash
git add src/Auth/
git commit -m "feat: add Auth, User, and Role clients with full RBAC support"
```

---

### Task 11: ClusterClient

**Files:**
- Create: `src/Cluster/ClusterClient.php`

- [ ] **Step 1: Write ClusterClient**

```php
<?php

declare(strict_types=1);

namespace Erikwang2013\Etcd\Cluster;

use Erikwang2013\Etcd\Transport\TransportInterface;

class ClusterClient
{
    private TransportInterface $transport;

    public function __construct(TransportInterface $transport)
    {
        $this->transport = $transport;
    }

    /**
     * Add a member to the cluster.
     *
     * @return array ['header' => [...], 'member' => [...], 'members' => [...]]
     */
    public function memberAdd(array $peerURLs, bool $isLearner = false): array
    {
        $body = ['peerURLs' => $peerURLs];
        if ($isLearner) {
            $body['isLearner'] = true;
        }
        $response = $this->transport->send('/v3/cluster/member/add', $body);
        return [
            'header'  => $response['header'] ?? [],
            'member'  => $response['member'] ?? [],
            'members' => $response['members'] ?? [],
        ];
    }

    /**
     * Remove a member from the cluster.
     *
     * @return array ['header' => [...], 'members' => [...]]
     */
    public function memberRemove(int $id): array
    {
        $response = $this->transport->send('/v3/cluster/member/remove', ['ID' => $id]);
        return [
            'header'  => $response['header'] ?? [],
            'members' => $response['members'] ?? [],
        ];
    }

    /**
     * Update peer URLs for a member.
     *
     * @return array ['header' => [...], 'members' => [...]]
     */
    public function memberUpdate(int $id, array $peerURLs): array
    {
        $response = $this->transport->send('/v3/cluster/member/update', [
            'ID'       => $id,
            'peerURLs' => $peerURLs,
        ]);
        return [
            'header'  => $response['header'] ?? [],
            'members' => $response['members'] ?? [],
        ];
    }

    /**
     * List all cluster members.
     *
     * @return array ['header' => [...], 'members' => [...]]
     */
    public function memberList(): array
    {
        $response = $this->transport->send('/v3/cluster/member/list', []);
        return [
            'header'  => $response['header'] ?? [],
            'members' => $response['members'] ?? [],
        ];
    }

    /**
     * Promote a learner member to voting member.
     *
     * @return array ['header' => [...], 'members' => [...]]
     */
    public function memberPromote(int $id): array
    {
        $response = $this->transport->send('/v3/cluster/member/promote', ['ID' => $id]);
        return [
            'header'  => $response['header'] ?? [],
            'members' => $response['members'] ?? [],
        ];
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add src/Cluster/ClusterClient.php
git commit -m "feat: add ClusterClient with member management"
```

---

### Task 12: MaintenanceClient

**Files:**
- Create: `src/Maintenance/MaintenanceClient.php`

- [ ] **Step 1: Write MaintenanceClient**

```php
<?php

declare(strict_types=1);

namespace Erikwang2013\Etcd\Maintenance;

use Erikwang2013\Etcd\Transport\TransportInterface;

class MaintenanceClient
{
    private TransportInterface $transport;

    public function __construct(TransportInterface $transport)
    {
        $this->transport = $transport;
    }

    /**
     * Get the status of the connected etcd member.
     *
     * @return array ['header' => [...], 'version' => string, 'dbSize' => int, 'leader' => int, 'raftIndex' => int, 'raftTerm' => int, 'raftAppliedIndex' => int, 'errors' => list<string>]
     */
    public function status(): array
    {
        $response = $this->transport->send('/v3/maintenance/status', []);
        return [
            'header'           => $response['header'] ?? [],
            'version'          => $response['version'] ?? '',
            'dbSize'           => (int) ($response['dbSize'] ?? 0),
            'leader'           => (int) ($response['leader'] ?? 0),
            'raftIndex'        => (int) ($response['raftIndex'] ?? 0),
            'raftTerm'         => (int) ($response['raftTerm'] ?? 0),
            'raftAppliedIndex' => (int) ($response['raftAppliedIndex'] ?? 0),
            'errors'           => $response['errors'] ?? [],
        ];
    }

    /**
     * Manage etcd alarms. Action: 0=GET, 1=ACTIVATE, 2=DEACTIVATE. Alarm: 0=NONE, 1=NOSPACE, 2=CORRUPT.
     *
     * @param int $action   0=get, 1=activate, 2=deactivate
     * @param int $alarm    0=none, 1=nospace, 2=corrupt
     * @param int $memberID Member ID (0 for all)
     * @return array ['header' => [...], 'alarms' => [['memberID' => int, 'alarm' => int], ...]]
     */
    public function alarm(int $action = 0, int $alarm = 0, int $memberID = 0): array
    {
        $body = ['action' => $action];
        if ($memberID > 0) {
            $body['memberID'] = $memberID;
        }
        if ($alarm > 0) {
            $body['alarm'] = $alarm;
        }
        $response = $this->transport->send('/v3/maintenance/alarm', $body);
        return [
            'header' => $response['header'] ?? [],
            'alarms' => $response['alarms'] ?? [],
        ];
    }

    /**
     * Defragment the etcd database to reclaim storage.
     *
     * @return array ['header' => [...]]
     */
    public function defragment(): array
    {
        $response = $this->transport->send('/v3/maintenance/defragment', []);
        return ['header' => $response['header'] ?? []];
    }

    /**
     * Get the hash of the KV store (for integrity checking).
     *
     * @return array ['header' => [...], 'hash' => int]
     */
    public function hash(int $revision = 0): array
    {
        $body = [];
        if ($revision > 0) {
            $body['revision'] = $revision;
        }
        $response = $this->transport->send('/v3/maintenance/hash', $body);
        return [
            'header' => $response['header'] ?? [],
            'hash'   => (int) ($response['hash'] ?? 0),
        ];
    }

    /**
     * Take a snapshot of the etcd database.
     * Returns raw binary data — caller should write to disk.
     *
     * @return string  Raw snapshot bytes
     */
    public function snapshot(): string
    {
        // Snapshot is a special case — it returns raw binary, not JSON
        // The HTTP gateway returns application/octet-stream
        $endpoint = $this->getEndpoint();
        $url = "http://{$endpoint}/v3/maintenance/snapshot";

        $ch = \curl_init($url);
        \curl_setopt($ch, CURLOPT_POST, true);
        \curl_setopt($ch, CURLOPT_POSTFIELDS, '{}');
        \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        \curl_setopt($ch, CURLOPT_TIMEOUT, 300); // snapshots can be large

        $data = \curl_exec($ch);
        $httpCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
        \curl_close($ch);

        if ($httpCode !== 200) {
            throw new \Erikwang2013\Etcd\Exception\EtcdException("Snapshot failed with HTTP {$httpCode}");
        }

        return $data;
    }

    private function getEndpoint(): string
    {
        // Access the transport's endpoint via reflection or stored config
        // For now, this is handled by HttpTransport exposing its endpoint
        return '127.0.0.1:2379';
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add src/Maintenance/MaintenanceClient.php
git commit -m "feat: add MaintenanceClient with status, alarm, defragment, hash, snapshot"
```

---

### Task 13: EtcdClient (top-level façade)

**Files:**
- Create: `src/EtcdClient.php`

- [ ] **Step 1: Write EtcdClient**

```php
<?php

declare(strict_types=1);

namespace Erikwang2013\Etcd;

use Erikwang2013\Etcd\Transport\TransportInterface;
use Erikwang2013\Etcd\Transport\TransportSelector;
use Erikwang2013\Etcd\Kv\KvClient;
use Erikwang2013\Etcd\Watch\WatchClient;
use Erikwang2013\Etcd\Lease\LeaseClient;
use Erikwang2013\Etcd\Auth\AuthClient;
use Erikwang2013\Etcd\Cluster\ClusterClient;
use Erikwang2013\Etcd\Maintenance\MaintenanceClient;

class EtcdClient
{
    private TransportInterface $transport;
    private ?KvClient $kvClient = null;
    private ?WatchClient $watchClient = null;
    private ?LeaseClient $leaseClient = null;
    private ?AuthClient $authClient = null;
    private ?ClusterClient $clusterClient = null;
    private ?MaintenanceClient $maintenanceClient = null;
    private array $config;

    private static ?self $instance = null;

    /**
     * @param array{endpoints: list<string>, transport?: string, timeout?: float, retry?: int, auth?: array{user: string, password: string}, options?: array} $config
     */
    public function __construct(array $config = [])
    {
        $this->config = \array_merge(
            ['endpoints' => ['127.0.0.1:2379'], 'transport' => 'auto', 'timeout' => 5.0, 'retry' => 2],
            $config
        );
        $this->transport = TransportSelector::select($this->config);
    }

    /**
     * Get or create the singleton instance (useful for Webman / non-DI frameworks).
     */
    public static function instance(array $config = []): self
    {
        if (self::$instance === null) {
            self::$instance = new self($config);
        }
        return self::$instance;
    }

    /**
     * Reset the singleton instance (useful for testing).
     */
    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    /** Get the KV client. */
    public function kv(): KvClient
    {
        return $this->kvClient ??= new KvClient($this->transport);
    }

    /** Get the Watch client. */
    public function watch(): WatchClient
    {
        return $this->watchClient ??= new WatchClient($this->transport);
    }

    /** Get the Lease client. */
    public function lease(): LeaseClient
    {
        return $this->leaseClient ??= new LeaseClient($this->transport);
    }

    /** Get the Auth client. */
    public function auth(): AuthClient
    {
        return $this->authClient ??= new AuthClient($this->transport);
    }

    /** Get the Cluster client. */
    public function cluster(): ClusterClient
    {
        return $this->clusterClient ??= new ClusterClient($this->transport);
    }

    /** Get the Maintenance client. */
    public function maintenance(): MaintenanceClient
    {
        return $this->maintenanceClient ??= new MaintenanceClient($this->transport);
    }

    /** Get the underlying transport (for custom/direct calls). */
    public function transport(): TransportInterface
    {
        return $this->transport;
    }

    /** Get the current config. */
    public function config(): array
    {
        return $this->config;
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add src/EtcdClient.php
git commit -m "feat: add EtcdClient top-level façade composing all subsystems"
```

---

### Task 14: Laravel adapter

**Files:**
- Create: `src/Adapter/Laravel/ServiceProvider.php`
- Create: `src/Adapter/Laravel/Facade.php`

- [ ] **Step 1: Write Laravel ServiceProvider**

```php
<?php

declare(strict_types=1);

namespace Erikwang2013\Etcd\Adapter\Laravel;

use Erikwang2013\Etcd\EtcdClient;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../../../../config/etcd.php', 'etcd'
        );

        $this->app->singleton(EtcdClient::class, function ($app) {
            return new EtcdClient($app['config']->get('etcd', []));
        });

        $this->app->alias(EtcdClient::class, 'etcd');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../../../../config/etcd.php' => config_path('etcd.php'),
        ], 'etcd-config');
    }
}
```

- [ ] **Step 2: Write Laravel Facade**

```php
<?php

declare(strict_types=1);

namespace Erikwang2013\Etcd\Adapter\Laravel;

use Illuminate\Support\Facades\Facade as BaseFacade;

/**
 * @method static \Erikwang2013\Etcd\Kv\KvClient kv()
 * @method static \Erikwang2013\Etcd\Watch\WatchClient watch()
 * @method static \Erikwang2013\Etcd\Lease\LeaseClient lease()
 * @method static \Erikwang2013\Etcd\Auth\AuthClient auth()
 * @method static \Erikwang2013\Etcd\Cluster\ClusterClient cluster()
 * @method static \Erikwang2013\Etcd\Maintenance\MaintenanceClient maintenance()
 */
class Facade extends BaseFacade
{
    protected static function getFacadeAccessor(): string
    {
        return 'etcd';
    }
}
```

- [ ] **Step 3: Write default config**

File: `config/etcd.php`

```php
<?php

return [
    'endpoints' => \explode(',', \env('ETCD_ENDPOINTS', '127.0.0.1:2379')),
    'transport' => \env('ETCD_TRANSPORT', 'auto'),
    'timeout'   => (float) \env('ETCD_TIMEOUT', 5.0),
    'retry'     => (int) \env('ETCD_RETRY', 2),
    'auth'      => [
        'user'     => \env('ETCD_USER', ''),
        'password' => \env('ETCD_PASSWORD', ''),
    ],
];
```

- [ ] **Step 4: Commit**

```bash
git add src/Adapter/Laravel/ config/
git commit -m "feat: add Laravel adapter with ServiceProvider and Facade"
```

---

### Task 15: Hyperf adapter

**Files:**
- Create: `src/Adapter/Hyperf/ConfigProvider.php`

- [ ] **Step 1: Write Hyperf ConfigProvider**

```php
<?php

declare(strict_types=1);

namespace Erikwang2013\Etcd\Adapter\Hyperf;

use Erikwang2013\Etcd\EtcdClient;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                EtcdClient::class => function () {
                    return new EtcdClient(\Hyperf\Support\env('etcd', []));
                },
            ],
            'publish' => [
                [
                    'id'          => 'config',
                    'description' => 'etcd client config',
                    'source'      => __DIR__ . '/../../../../config/etcd.php',
                    'destination' => BASE_PATH . '/config/autoload/etcd.php',
                ],
            ],
        ];
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add src/Adapter/Hyperf/
git commit -m "feat: add Hyperf adapter with ConfigProvider"
```

---

### Task 16: ThinkPHP adapter

**Files:**
- Create: `src/Adapter/ThinkPHP/Service.php`
- Create: `src/Adapter/ThinkPHP/Facade.php`

- [ ] **Step 1: Write ThinkPHP Service**

```php
<?php

declare(strict_types=1);

namespace Erikwang2013\Etcd\Adapter\ThinkPHP;

use Erikwang2013\Etcd\EtcdClient;
use think\Service as BaseService;

class Service extends BaseService
{
    public function register(): void
    {
        $this->app->bind('etcd', function () {
            $config = $this->app->config->get('etcd', []);
            return new EtcdClient($config);
        });

        $this->app->bind(EtcdClient::class, function () {
            return $this->app->get('etcd');
        });
    }
}
```

- [ ] **Step 2: Write ThinkPHP Facade**

```php
<?php

declare(strict_types=1);

namespace Erikwang2013\Etcd\Adapter\ThinkPHP;

use think\Facade as BaseFacade;

/**
 * @method static \Erikwang2013\Etcd\Kv\KvClient kv()
 * @method static \Erikwang2013\Etcd\Watch\WatchClient watch()
 * @method static \Erikwang2013\Etcd\Lease\LeaseClient lease()
 * @method static \Erikwang2013\Etcd\Auth\AuthClient auth()
 * @method static \Erikwang2013\Etcd\Cluster\ClusterClient cluster()
 * @method static \Erikwang2013\Etcd\Maintenance\MaintenanceClient maintenance()
 */
class Facade extends BaseFacade
{
    protected static function getFacadeClass(): string
    {
        return 'etcd';
    }
}
```

- [ ] **Step 3: Commit**

```bash
git add src/Adapter/ThinkPHP/
git commit -m "feat: add ThinkPHP adapter with Service and Facade"
```

---

### Task 17: Webman adapter

**Files:**
- Create: `src/Adapter/Webman/Plugin.php`

- [ ] **Step 1: Write Webman Plugin**

```php
<?php

declare(strict_types=1);

namespace Erikwang2013\Etcd\Adapter\Webman;

use Erikwang2013\Etcd\EtcdClient;

class Plugin
{
    /**
     * Install — called once when composer require is run.
     */
    public static function install(): void
    {
        $configDir = \config_path() . '/plugin/erikwang2013/etcd';
        if (!\is_dir($configDir)) {
            \mkdir($configDir, 0755, true);
        }
        $configFile = $configDir . '/etcd.php';
        if (!\file_exists($configFile)) {
            \copy(
                __DIR__ . '/../../../../config/etcd.php',
                $configFile
            );
        }
        echo "erikwang2013/etcd plugin installed. Config at plugin/erikwang2013/etcd/etcd.php\n";
    }

    /**
     * Uninstall.
     */
    public static function uninstall(): void
    {
        $configDir = \config_path() . '/plugin/erikwang2013/etcd';
        // Keep config on uninstall (webman convention)
        echo "erikwang2013/etcd plugin uninstalled.\n";
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add src/Adapter/Webman/
git commit -m "feat: add Webman adapter with Plugin install/uninstall"
```

---

### Task 18: Install dependencies & verify autoloading

- [ ] **Step 1: Run composer install**

```bash
cd /home/wwwroot/erikwang2013/etcd && composer install
```

Expected: Dependencies installed successfully (psr/http-client, psr/http-factory, google/protobuf)

- [ ] **Step 2: Verify autoloading**

```bash
cd /home/wwwroot/erikwang2013/etcd && php -r "require 'vendor/autoload.php'; \$c = new Erikwang2013\Etcd\EtcdClient(['endpoints' => ['127.0.0.1:2379']]); echo 'EtcdClient instantiated OK: ' . get_class(\$c->kv());"
```

Expected: "EtcdClient instantiated OK: Erikwang2013\Etcd\Kv\KvClient"

- [ ] **Step 3: Commit**

```bash
git add vendor/ composer.lock
git commit -m "chore: composer install with dependencies"
```
