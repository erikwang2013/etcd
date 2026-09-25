# erikwang2013/etcd 设计文档

## 概述

`erikwang2013/etcd` 是一个 PHP 8.1+ 的 etcd v3 客户端包，支持 gRPC + HTTP 双模传输，覆盖 etcd v3 全部 API，并提供 Laravel / Hyperf / ThinkPHP / Webman 四大框架的一等适配。

## 设计目标

- **双模传输**：HTTP JSON 网关（全功能、零扩展依赖）与 gRPC 原生协议（一元 RPC 已实现，流式待补；需 ext-grpc）
- **全功能覆盖**：KV 存储、Watch 监听、Lease 租约、Auth 认证授权、Cluster 集群管理、Maintenance 运维操作
- **框架无关核心**：不绑定任何框架；HTTP 通道按 ext-curl → PHP 流封装 → PSR-18 自动降级，PSR 接口本身是可选的
- **框架适配层**：为每个目标框架提供符合其插件规范的适配器（ServiceProvider / Facade / ConfigProvider 等）
- **PHP 8.1+**：利用严格类型、枚举、纤程等现代 PHP 特性

## 架构总览

> 完整图示见 [`architecture.svg`](./architecture.svg)、[`features.svg`](./features.svg)、[`lifecycle.svg`](./lifecycle.svg)。

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
| **支撑层** | 线格式解码（base64 / int64 字符串 / 零值省略） | `Support/KeyValue.php` |
| **异常层** | 类型化错误处理 | `EtcdException` → `ConnectionException`, `AuthException`, `KeyNotFoundException` |

### 设计决策

**手写桩删掉之后怎么走？**  
gRPC 的消息层改为**从上游 proto 生成**：`protos/` 放 etcd v3.5 的 `rpc.proto`、`kv.proto`、`auth.proto` 与生成脚本，产物提交在 `protos/generated/`（`composer.json` 里映射为 `Erikwang2013\Etcd\Proto\`）。这与手写桩的关键差别是：序列化能力与字段类型由 protoc 保证，而不是靠人工维护。附带确认了一件事——早先"实例化 protobuf Message 子类会 SIGSEGV"的环境问题**已不复现**（实测 `serializeToString`/`mergeFromString` 可正常往返大整数），那正是当初手写桩存在的唯一理由。

**（历史）为什么删掉了手写的 protobuf 桩？**  
原先 `src/Protobuf/` 有 85 个手写消息类（约 1555 行）。它们**没有任何引用**、**不含任何序列化实现**，而且类型是错的：64 位字段用 `int`（网关发的是 JSON 字符串，uint64 还放不进 PHP 的 int）、枚举用 `int`（网关发的是名字如 `"DELETE"`/`"NOSPACE"`）、多个 repeated 字段连 setter 都没有。留着比删掉更危险——实现 gRPC 时会被当成地基。实现 gRPC 应从 etcd 的 `rpc.proto` 生成正式桩。

**`auto` 为什么现在等同于 `http`？**  
`GrpcTransport` 三个方法都还抛异常，所以自动探测 `ext-grpc` 只会让"装了扩展"的用户直接不可用。当前实现下 `auto` 与 `http` 完全等价，只有显式 `transport = grpc` 才会选中 gRPC。等 gRPC 真正实现后再恢复探测语义。

## 八大系统

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
`HttpTransport::watch()` 在检测到 EOF 时自动从 `lastRevision` 重建连接，并随机选择新端点，确保不丢事件且支持故障转移。非阻塞 I/O（`stream_set_blocking(false)`）避免阻塞 PHP 进程。

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
maintenance().snapshot()     → 通过 sendRaw() 走 PSR-18 返回原始二进制数据
```

### 7. Election（选举）

```
election().campaign(name, value, lease, timeout)  → leader 描述符（当选前阻塞，用 timeout 兜底）
election().proclaim(value, leader)                → 需完整描述符，否则服务端 200 但无效果
election().leader(name)                           → 描述符或 null（无人当选时服务端回 500，属正常）
election().observe(name, onLeader[, options])     → 前缀 watch + 重读 leader
election().resign(leader)                         → 让位
```

**实测要点：** 网关把 `campaign` 当**缓冲响应**返回（当选才返回，不是流）；`observe` 则是永不关闭的 `{"result":…}` 流，网关不暴露，故用前缀 watch + 重读实现。

### 8. Lock（分布式锁）

```
lock().acquire(name, ttl = 30, timeout = null)  → 锁描述符
lock().release(lock)
lock().leader(name)                             → 当前持有者
```

**这是客户端实现，不是服务端锁**：etcd 3.5 的 HTTP 网关不暴露 `/v3/lock/*`（实测 404），互斥由「Election 竞选 + 租约」提供——与 etcd 自家 Go 的 `concurrency` 包同一做法。持有者被 `SIGKILL` 后，租约到期自动释放（实测 TTL 5 秒时接管耗时 2.95 秒）。

## 传输层设计

### TransportInterface

```php
interface TransportInterface {
    public function send(string $path, array $body): array;
    public function sendRaw(string $path): string;
    public function watch(string $key, string $rangeEnd, int $startRevision, callable $onEvent, array $options = []): void;
}
```

### HttpTransport（默认可用）

- **通信协议：** 通过 etcd 内置的 gRPC-gateway 发送 JSON HTTP 请求
- **端点路径：** `/v3/kv/put`, `/v3/kv/range`, `/v3/watch` 等
- **HTTP 通道（自动降级）：** ext-curl → PHP 流封装（`file_get_contents` + `stream_context`）→ PSR-18 `ClientInterface` + PSR-17 工厂。
  三者都不可用时抛 `ConnectionException` 并说明启用方式；`config['driver']` 可强制指定 `curl` / `stream`（`options.ssl` 对 curl 与流封装都生效，PSR-18 路径的 TLS 由调用方的客户端负责）。
- **Watch：** cURL 写回调（默认驱动）或阻塞读 + 短超时（流驱动）。**不能用 `stream_select`**：真实 etcd 的 `/v3/watch` 是 chunked 响应，PHP 的 http 封装会挂 dechunk 过滤器，带过滤器的流无法转成可 select 的 fd，PHP 8 直接抛 `ValueError`。
- **认证：** etcd v3 只认 `/v3/auth/authenticate` 换来的 token（裸 `Authorization: <token>`，不接受 Basic，也不接受 `Bearer` 前缀）。配置 `auth` 后自动换取并缓存，401 时重认证一次。
- **重试：** 只在"请求根本没到达服务端"（拒绝连接 / DNS 失败）时重试；只读接口额外容忍 5xx 与超时。写操作遇 5xx/超时不重试——重放会让 CAS 被应用两次并返回错误的分支结果。
- **认证：** HTTP Basic Auth 头（`Authorization: Basic <base64>`）
- **重试：** 连接级失败自动重试（默认 2 次，间隔 100ms），认证和服务器错误不重试
- **Watch：** `fopen()` + `stream_context_create` 分块读取，非阻塞 I/O，断线自动重连

### GrpcTransport（一元 RPC）

- **消息层：** 从 etcd v3.5 上游 `rpc.proto` / `kv.proto` / `auth.proto` 用 `protoc --php_out` 生成（`protos/` 内存放上游 proto 与生成脚本，产物在 `protos/generated/`），请求体按类型化消息组装，字段名与类型由 proto 保证。
- **通道管理：** `GrpcStub`（`Grpc\BaseStub` 子类）单独成文件——父类在文件加载时解析，缺 ext-grpc 的环境下不能连累 `GrpcTransport` 本身的加载。
- **已实现：** 一元 `send()` / `sendRaw()`。
- **未实现：** watch、snapshot 等流式调用，以及 `/v3/election/*`（属另一个 proto `v3electionpb`）。这些路径**按名字拒绝**并说明原因，不静默失败。
- **验证边界（重要）：** 开发环境没有 `ext-grpc`，也没装 `grpc_php_plugin`，所以信道打开、凭据 metadata、`_simpleRequest`、超时与状态码映射都是照插件的输出模式手写、**未经真实往返验证**。消息生成、字段校验与请求组装有测试覆盖。

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
    'driver'    => 'auto',               // auto | curl | stream（HTTP 通道）
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

环境变量：`ETCD_ENDPOINTS` | `ETCD_TRANSPORT` | `ETCD_DRIVER` | `ETCD_TIMEOUT` | `ETCD_RETRY` | `ETCD_USER` | `ETCD_PASSWORD`

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
├── protos/                            # 上游 proto + 生成脚本 + 生成产物（Proto 命名空间）
├── config/etcd.php                    # 默认配置
├── README.md
├── docs/
│   ├── design-cn.md                   # 本设计文档
│   ├── architecture.svg               # 架构设计图
│   ├── features.svg                   # 功能设计图
│   └── lifecycle.svg                  # 生命周期图
├── src/
│   ├── EtcdClient.php                 # 顶层门面
│   ├── Transport/
│   │   ├── TransportInterface.php     # 传输抽象
│   │   ├── TransportSelector.php      # 自动选择逻辑
│   │   ├── HttpTransport.php          # HTTP JSON 传输
│   │   ├── GrpcTransport.php          # gRPC 传输（一元 RPC）
│   │   └── GrpcStub.php               # Grpc\BaseStub 子类（独立文件）
│   ├── Kv/KvClient.php                # 键值操作
│   ├── Watch/WatchClient.php          # 变更监听
│   ├── Lease/LeaseClient.php          # 租约管理
│   ├── Auth/                          # 认证授权
│   │   ├── AuthClient.php             #   开关/状态
│   │   ├── UserClient.php             #   用户 CRUD
│   │   └── RoleClient.php             #   角色 CRUD + 权限
│   ├── Cluster/ClusterClient.php      # 集群成员管理
│   ├── Election/ElectionClient.php    # 选举：campaign / proclaim / leader / observe / resign
│   ├── Lock/LockClient.php            # 分布式锁（建在 Election 之上，网关无 /v3/lock/*）
│   ├── Maintenance/MaintenanceClient.php  # 运维操作
│   ├── Support/                       # KeyValue / Int64 共用解码、WatchHandle
│   ├── Exception/                     # 异常层次
│   └── Adapter/                       # 框架适配器
│       ├── Laravel/
│       ├── Hyperf/
│       ├── ThinkPHP/
│       └── Webman/
```

## 待办路线图

- [x] 消息层从上游 proto 生成（`protos/`，protoc 产物可复现）
- [x] 选举（Election）与分布式锁（Lock，建在 Election 之上）
- [x] 单元测试 + 集成测试（忠实网关桩 + `ETCD_REAL` 真集群差分，已接入 CI）
- [x] TLS 配置（`options.ssl` 对 curl 与流封装生效，含客户端证书 `local_cert`/`local_pk`）
- [ ] gRPC 流式调用（watch / snapshot；一元 RPC 已实现）
- [ ] gRPC 网络往返验证（需 ext-grpc 环境，代码已就位）
- [ ] Watch 多 key 并行监听
- [ ] 连接池 / 长连接复用

## 开源不易，欢迎支持 / Support This Project

<p align="center">
  <table>
    <tr>
      <td align="center"><b>微信 / WeChat</b></td>
      <td align="center"><b>支付宝 / Alipay</b></td>
    </tr>
    <tr>
      <td align="center"><img src="./weixinpay.png" alt="微信支付" width="130" height="130" /></td>
      <td align="center"><img src="./alipay.png" alt="支付宝" width="130" height="130" /></td>
    </tr>
  </table>
</p>
