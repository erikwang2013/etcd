# erikwang2013/etcd 设计文档

## 概述

`erikwang2013/etcd` 是一个 PHP 8.1+ 的 etcd v3 客户端包，支持 gRPC + HTTP 双模传输，覆盖 etcd v3 全部 API，并提供 Laravel / Hyperf / ThinkPHP / Webman 四大框架的一等适配。

## 设计目标

- **双模传输**：gRPC 原生协议（高性能、完整流式支持）与 HTTP JSON 网关（零扩展依赖、开箱即用）自动切换
- **全功能覆盖**：KV 存储、Watch 监听、Lease 租约、Auth 认证授权、Cluster 集群管理、Maintenance 运维操作
- **框架无关核心**：核心客户端仅依赖 PSR-18 + PSR-17 接口，不绑定任何框架
- **框架适配层**：为每个目标框架提供符合其插件规范的适配器（ServiceProvider / Facade / ConfigProvider 等）
- **PHP 8.1+**：利用严格类型、枚举、纤程等现代 PHP 特性

## 架构总览

```
┌──────────────────────────────────────────────────────┐
│                    EtcdClient                         │
│  .kv → KvClient      .watch → WatchClient            │
│  .lease → LeaseClient  .auth → AuthClient            │
│  .cluster → ClusterClient  .maintenance → MaintClient │
└──────────────────────┬───────────────────────────────┘
                       │
              ┌────────┴────────┐
              │ TransportSelector│  ← auto / grpc / http
              └────────┬────────┘
                       │
           ┌───────────┼───────────┐
           ▼           ▼           ▼
     GrpcTransport  HttpTransport (扩展中...)
```

### 核心分层

| 层 | 职责 | 关键类 |
|---|------|-------|
| **门面层** | 统一入口，组合 6 个子系统 | `EtcdClient` |
| **子系统层** | 各 API 领域的业务逻辑、编解码 | `KvClient`, `WatchClient`, `LeaseClient` 等 |
| **传输层** | 网络通信，gRPC/HTTP 抽象 | `TransportInterface`, `HttpTransport`, `GrpcTransport` |
| **消息层** | protobuf 消息桩（纯 PHP 数据类） | `src/Protobuf/` 下 60+ 类 |
| **异常层** | 类型化错误处理 | `EtcdException` → `ConnectionException`, `AuthException`, `KeyNotFoundException` |

### 设计决策

**为什么 protobuf 桩是纯 PHP 类？**  
当前环境的 protobuf C 扩展（v4.31.1）与 `google/protobuf` composer 包的运行时版本存在不兼容（实例化 Message 子类时 SIGSEGV）。为了让 HTTP 传输立刻可用，桩代码被设计为不继承 `Google\Protobuf\Internal\Message` 的纯 PHP 数据类。将来实现 gRPC 传输时，会提供独立编译的正式 protobuf 桩。

**为什么默认传输是 `auto` 而非 `http`？**  
设计目标是与 gRPC 扩展共存的自动检测。`auto` 模式检查两个条件：`extension_loaded('grpc')` AND `class_exists('Grpc\BaseStub')`。后者确保 `grpc/grpc` composer 包已安装（不仅是 C 扩展），避免选中骨架实现。

## 六大于系统

### 1. KV（键值存储）

etcd 的核心操作，支持精确读写、前缀扫描、事务和压缩。

```
put(key, value, [lease, prevKv, ignoreValue, ignoreLease])
get(key, [rangeEnd, limit, revision, sortOrder, sortTarget, serializable, keysOnly, countOnly])
getByPrefix(prefix, [...])
getOrFail(key)  → 找不到时抛出 KeyNotFoundException
delete(key, [rangeEnd, prevKv])
deleteByPrefix(prefix)
txn(compare[], success[], failure[])
compact(revision)
```

**编码约定：**  
etcd 的 gRPC-gateway 要求 key 和 value 使用 base64 编码的 JSON 字符串。发送前 base64_encode，接收后 base64_decode。`getOrFail()` 直接返回解码后的 KV 数组。

**事务（Txn）结构：**
```php
// 原子 CAS：如果 /counter 值为 100，则更新为 101
$etcd->kv()->txn(
    compare: [['result' => 0, 'target' => 3, 'key' => '/counter', 'value' => '100']],
    success: [['request_put' => ['key' => '/counter', 'value' => '101']]],
    failure: [['request_put' => ['key' => '/counter', 'value' => '1']]]
);
```

### 2. Watch（键变更监听）

基于 HTTP 分块传输（chunked transfer encoding）的流式监听。

```
watch(key, callback, [rangeEnd, startRevision, prevKv, progressNotify])
watchPrefix(prefix, callback, [...])
```

**断线重连机制：**  
`HttpTransport::watch()` 在检测到 EOF 时自动从 `lastRevision` 重建连接，确保不丢事件。非阻塞 I/O（`stream_set_blocking(false)`）避免阻塞 PHP 进程。

**回调事件格式：**
```php
['type' => 'PUT'|'DELETE', 'kv' => [...], 'prev_kv' => [...]|null]
```

### 3. Lease（租约）

绑定到 key 的 TTL 定时器，到期自动删除。

```
grant(ttl, [id])     → {ID, TTL}
revoke(id)
keepAlive(id)        → {ID, TTL}
timeToLive(id, [keys])
list()               → [{ID}, ...]
```

**典型模式：** 服务注册（grant + put with lease）+ 心跳续约（keepAlive 定时调用）。

### 4. Auth（认证与 RBAC）

完整的用户/角色/权限管理，支持 etcd 的认证开关。

```
auth().enable() / disable() / status()

// 用户管理
auth().user().add(name, password)
auth().user().get(name)           → {roles: [...]}
auth().user().list()              → {users: [...]}
auth().user().delete(name)
auth().user().changePassword(name, newpass)
auth().user().grantRole(user, role)
auth().user().revokeRole(user, role)

// 角色管理
auth().role().add(name)
auth().role().get(name)           → {perm: [...]}
auth().role().list()
auth().role().delete(name)
auth().role().grantPermission(role, permType, key, rangeEnd)
auth().role().revokePermission(role, key, rangeEnd)
```

**权限类型：** `0` = READ，`1` = WRITE，`2` = READWRITE

### 5. Cluster（集群管理）

```
cluster().memberList()
cluster().memberAdd(peerURLs, [isLearner])
cluster().memberUpdate(id, peerURLs)
cluster().memberRemove(id)
cluster().memberPromote(id)
```

### 6. Maintenance（运维操作）

```
maintenance().status()       → {version, dbSize, leader, raftIndex, raftTerm, ...}
maintenance().alarm([action, alarm, memberID])
maintenance().defragment()
maintenance().hash([revision])
maintenance().snapshot()     → 返回原始二进制数据
```

## 传输层设计

### TransportInterface

```php
interface TransportInterface {
    public function send(string $path, array $body): array;
    public function watch(string $key, string $rangeEnd, int $startRevision, callable $onEvent, array $options = []): void;
}
```

### HttpTransport（默认可用）

- **通信协议：** 通过 etcd 内置的 gRPC-gateway 发送 JSON HTTP 请求
- **端点路径：** `/v3/kv/put`, `/v3/kv/range`, `/v3/watch` 等
- **依赖：** PSR-18 `ClientInterface` + PSR-17 `RequestFactoryInterface` + `StreamFactoryInterface`
- **认证：** HTTP Basic Auth 头（`Authorization: Basic <base64>`）
- **重试：** 连接级失败自动重试（默认 2 次，间隔 100ms），认证和服务器错误不重试
- **Watch：** `fopen()` + `stream_context_create` 分块读取，非阻塞 I/O，断线自动重连

### GrpcTransport（骨架）

- **通道管理：** `Grpc\Channel` 实例复用（静态缓存）
- **当前状态：** `send()` 和 `watch()` 抛出 `ConnectionException`（待实现）
- **启用条件：** `extension_loaded('grpc')` AND `class_exists('Grpc\BaseStub')`

### TransportSelector（自动选择）

```
config['transport'] === 'grpc'  → GrpcTransport
config['transport'] === 'http'  → HttpTransport
config['transport'] === 'auto'  → 检测 gRPC → 可用则 GrpcTransport，否则 HttpTransport
```

## 异常体系

```
RuntimeException
 └── EtcdException
      ├── ConnectionException    // 连接超时、DNS 失败、连接拒绝
      ├── AuthException          // 401 认证失败
      └── KeyNotFoundException   // getOrFail() 找不到 key
```

- `ConnectionException`：仅在连接层失败时抛出，会触发重试
- `AuthException`：HTTP 401 响应，不重试
- `EtcdException`：其他 etcd 服务端错误（4xx/5xx + error message）
- `KeyNotFoundException`：仅在显式调用 `getOrFail()` 时抛出

## 框架适配器

### Laravel

- **入口：** `ServiceProvider` + `Facade`
- **发现：** composer.json `extra.laravel.providers` 自动注册
- **配置：** `config/etcd.php`，`php artisan vendor:publish --tag=etcd-config`
- **环境变量：** `ETCD_ENDPOINTS`, `ETCD_USER`, `ETCD_PASSWORD` 等

### Hyperf

- **入口：** `ConfigProvider`（Hyperf 自动发现）
- **依赖注入：** `#[Inject] private EtcdClient $etcd` 或 `make(EtcdClient::class)`
- **配置：** `config/autoload/etcd.php`

### ThinkPHP

- **入口：** `think\Service` + `think\Facade`
- **注册：** 在 `app/service.php` 中手动注册
- **使用：** `app('etcd')` 或 `Etcd::kv()->put(...)`

### Webman

- **入口：** `Plugin::install()` 自动复制配置
- **使用：** `EtcdClient::instance()` 单例模式
- **配置：** `plugin/erikwang2013/etcd/config/etcd.php`

## 配置参考

```php
[
    'endpoints' => ['127.0.0.1:2379'],  // 支持多节点
    'transport' => 'auto',               // auto | http | grpc
    'timeout'   => 5.0,                  // 秒
    'retry'     => 2,                    // 连接失败重试次数
    'auth'      => [
        'user'     => '',                // etcd 用户名
        'password' => '',                // etcd 密码
    ],
    'options' => [
        'grpc' => ['grpc_target_persist' => true],
        'http' => ['verify_peer' => true],
    ],
]
```

环境变量：`ETCD_ENDPOINTS` | `ETCD_TRANSPORT` | `ETCD_TIMEOUT` | `ETCD_RETRY` | `ETCD_USER` | `ETCD_PASSWORD`

## 依赖关系

| 包 | 类型 | 用途 |
|----|------|------|
| `psr/http-client` | required | HttpTransport 的 HTTP 客户端接口 |
| `psr/http-factory` | required | HttpTransport 的 Request/Stream 工厂接口 |
| `grpc/grpc` | suggested | gRPC 原生传输（PHP composer 包） |
| `google/protobuf` | suggested | protobuf 运行时（gRPC 传输需要） |
| `ext-grpc` | suggested | gRPC C 扩展（更高性能） |

## 目录结构

```
erikwang2013/etcd/
├── composer.json
├── config/etcd.php                    # 默认配置
├── README.md
├── docs/
│   └── design-cn.md                   # 本设计文档
├── src/
│   ├── EtcdClient.php                 # 顶层门面
│   ├── Transport/
│   │   ├── TransportInterface.php     # 传输抽象
│   │   ├── TransportSelector.php      # 自动选择逻辑
│   │   ├── HttpTransport.php          # HTTP JSON 传输
│   │   └── GrpcTransport.php          # gRPC 传输骨架
│   ├── Kv/KvClient.php                # 键值操作
│   ├── Watch/WatchClient.php          # 变更监听
│   ├── Lease/LeaseClient.php          # 租约管理
│   ├── Auth/                          # 认证授权
│   │   ├── AuthClient.php             #   开关/状态
│   │   ├── UserClient.php             #   用户 CRUD
│   │   └── RoleClient.php             #   角色 CRUD + 权限
│   ├── Cluster/ClusterClient.php      # 集群成员管理
│   ├── Maintenance/MaintenanceClient.php  # 运维操作
│   ├── Protobuf/                      # 消息桩（纯 PHP 类）
│   │   ├── Mvccpb/                    #   KeyValue, Event
│   │   ├── Etcdserverpb/              #   60+ 请求/响应消息
│   │   └── Authpb/                    #   Permission, User, Role
│   ├── Exception/                     # 异常层次
│   └── Adapter/                       # 框架适配器
│       ├── Laravel/
│       ├── Hyperf/
│       ├── ThinkPHP/
│       └── Webman/
```

## 待办路线图

- [ ] gRPC 传输完整实现（需编译 protobuf 服务桩）
- [ ] TLS/SSL 双向认证支持
- [ ] 选举（Election）和并发控制 API
- [ ] 单元测试 + 集成测试（Docker etcd 容器）
- [ ] Watch 多 key 并行监听
- [ ] 连接池 / 长连接复用
