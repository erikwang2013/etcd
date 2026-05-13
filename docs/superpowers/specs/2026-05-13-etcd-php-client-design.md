# etcd PHP Client — Design Spec

## Overview

A PHP 8.1+ etcd v3 client package (`erikwang2013/etcd`) with dual transport (gRPC + HTTP fallback), full API coverage, and first-class adapters for Laravel, Hyperf, ThinkPHP, and Webman.

## Requirements

### Functional

- **KV operations:** Put, Get, Delete, Range (prefix scan), Txn (transactions)
- **Watch:** streaming key change events over both gRPC and HTTP transports
- **Lease:** Grant, Revoke, KeepAlive, TimeToLive, List
- **Auth:** User CRUD, Role CRUD, user/role grant/revoke, auth enable/disable
- **Cluster:** Member Add/Remove/Update/List/Promote
- **Maintenance:** Status, Alarm, Defragment, Hash, Snapshot, MoveLeader
- **Error handling:** typed exceptions, gRPC status-code mapping
- **Transport selection:** auto-detect gRPC extension → gRPC; else HTTP gateway

### Framework integrations

| Framework | Entry point | Config location | Client retrieval |
|-----------|-------------|-----------------|------------------|
| Laravel | `ServiceProvider` + `Facade` | `config/etcd.php` (publishable) | `Etcd::kv()->put(...)` or `app('etcd')` |
| Hyperf | `ConfigProvider` (auto-discovered) | `config/autoload/etcd.php` | `#[Inject]` or `make(EtcdClient::class)` |
| ThinkPHP | `think\Service` | `config/etcd.php` | `app('etcd')` or `think\facade\Etcd::...` |
| Webman | Autoload + singleton | `plugin/erikwang2013/etcd/config/etcd.php` | `EtcdClient::instance()` |

### Non-functional

- PHP 8.1+ (minimum)
- PSR-18 HTTP Client + PSR-17 HTTP Factory for HttpTransport (framework-agnostic)
- No hard dependency on any framework; gRPC extension is optional
- Protobuf stubs pre-compiled and bundled (no user-side `protoc` runs)

## Architecture

### Top-level structure

```
erikwang2013/etcd/
├── src/
│   ├── EtcdClient.php              # Unified façade composing 6 subsystems
│   ├── Transport/
│   │   ├── TransportInterface.php   # send(string $method, array $body): array
│   │   ├── TransportSelector.php    # pick Grpc or Http based on env + config
│   │   ├── GrpcTransport.php        # via grpc extension + compiled stubs
│   │   └── HttpTransport.php        # via PSR-18 + etcd gRPC-gateway JSON
│   ├── Kv/
│   │   └── KvClient.php             // Put, Range, DeleteRange, Txn, Compact
│   ├── Watch/
│   │   └── WatchClient.php          // Watch (streaming), WatchOnce
│   ├── Lease/
│   │   └── LeaseClient.php          // Grant, Revoke, KeepAlive, TimeToLive, Leases
│   ├── Auth/
│   │   ├── AuthClient.php           // AuthEnable, AuthDisable, AuthStatus
│   │   ├── UserClient.php           // UserAdd/Get/List/Delete/ChangePassword/Grant/Revoke
│   │   └── RoleClient.php           // RoleAdd/Get/List/Delete/Grant/Revoke
│   ├── Cluster/
│   │   └── ClusterClient.php        // MemberAdd/Remove/Update/List/Promote
│   ├── Maintenance/
│   │   └── MaintenanceClient.php    // Status, Alarm, Defragment, Hash, Snapshot
│   ├── Exception/
│   │   ├── EtcdException.php        // base
│   │   ├── ConnectionException.php
│   │   ├── AuthException.php
│   │   └── KeyNotFoundException.php
│   ├── Protobuf/                    // Pre-compiled grpc + protobuf stubs
│   │   ├── Etcdserverpb/
│   │   ├── Mvccpb/
│   │   └── Authpb/
│   └── Adapter/
│       ├── Laravel/
│       │   ├── ServiceProvider.php
│       │   └── Facade.php
│       ├── Hyperf/
│       │   └── ConfigProvider.php
│       ├── ThinkPHP/
│       │   ├── Service.php
│       │   └── Facade.php
│       └── Webman/
│           └── Plugin.php
├── proto/                           // source .proto files (reference, not used at runtime)
├── composer.json
├── LICENSE
└── README.md
```

### Transport layer

```
TransportSelector::select($config): TransportInterface
    ├── config['transport'] === 'grpc'  → GrpcTransport
    ├── config['transport'] === 'http'  → HttpTransport
    └── config['transport'] === 'auto'  → extension_loaded('grpc') ? GrpcTransport : HttpTransport
```

**GrpcTransport** injects auth token into gRPC metadata. Uses pre-compiled stub classes under `Protobuf/`.

**HttpTransport** sends POST to `http://{endpoint}{path}` with JSON body, base64-encodes keys/values per gRPC-gateway convention. Adds Authorization header when auth is configured.

### Watch implementation

- **gRPC:** `Grpc\BidiStreamingCall` to `etcdserverpb.Watch::Watch()`. Read events from stream in a loop, callback on each WatchResponse.
- **HTTP:** POST to `/v3/watch` with `stream_context_create`, read chunked response line-by-line with `fgets`. Each line is a JSON WatchResponse. Reconnect on EOF using last `watch_id` + `revision`.

Both transport watch methods accept a `callable $onEvent` and return void (run indefinitely; caller wraps in a coroutine/process if needed).

### Error handling

```
EtcdException (extends RuntimeException)
├── ConnectionException           // timeout, DNS, refused
├── AuthException                 // 401 / gRPC Unauthenticated
└── KeyNotFoundException          // only thrown on explicit getOrFail()
```

gRPC status codes → exceptions:
- `Unauthenticated` → AuthException
- `Unavailable` / `DeadlineExceeded` → ConnectionException
- Others → EtcdException with status details

### Configuration schema

```php
[
    'endpoints' => ['127.0.0.1:2379'],
    'transport' => 'auto',        // auto | grpc | http
    'timeout'   => 5.0,
    'retry'     => 2,
    'auth'      => [
        'user'     => '',
        'password' => '',
    ],
    'options' => [
        'grpc' => ['grpc_target_persist' => true],
        'http' => ['verify_peer' => true],
    ],
]
```

### Framework adapter design

Each adapter:
1. Reads `endpoints` + `auth` from the framework's config system
2. Instantiates `EtcdClient` with that config
3. Registers it as a singleton in the framework's service container
4. Provides a Facade (where the framework uses them) for static access

**Laravel `ServiceProvider`:**
- `register()`: binds `EtcdClient` as singleton, reads `config('etcd')`
- `boot()`: publishes `config/etcd.php` via `vendor:publish`

**Hyperf `ConfigProvider`:**
- Returns `dependencies` map binding `EtcdClientInterface` → factory closure
- Hyperf auto-discovers `ConfigProvider` from composer.json `extra.hyperf.config`

**ThinkPHP `Service`:**
- `register()`: binds EtcdClient into `think\Container`
- `Service::boot()` called by ThinkPHP's service manager on app init

**Webman `Plugin`:**
- Autoload plugin class on composer autoload
- `install()`: copies default config into plugin directory
- Client retrievable via static `EtcdClient::instance()` singleton

### composer.json structure

```json
{
    "name": "erikwang2013/etcd",
    "type": "library",
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
        "psr-4": {"Erikwang2013\\Etcd\\": "src/"}
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

### Dependencies

| Dependency | Required | Purpose |
|-----------|----------|---------|
| `psr/http-client` | yes | HttpTransport |
| `psr/http-factory` | yes | HttpTransport (request factory) |
| `google/protobuf` | yes | gRPC stub runtime (used even by HttpTransport for message types) |
| `grpc/grpc` | suggested | Native gRPC PHP extension polyfill |
| `ext-grpc` | suggested | Better: native C extension |

## Testing strategy

- Unit tests for each subsystem client using a mocked `TransportInterface`
- Integration tests against a real etcd container (via Docker)
- HTTP transport tests against etcd's gRPC-gateway
- Framework adapter tests verify proper service registration

## Scope & exclusions

- **In scope:** KV, Watch, Lease, Auth, Cluster, Maintenance APIs for etcd v3
- **Out of scope:** etcd v2 API, election/concurrency primitives (future), TLS mutual auth (future)
- **Deferred:** SSL/TLS certificate support (can be added to options without breaking changes)
