# erikwang2013/etcd

PHP etcd v3 客户端 — gRPC + HTTP 双模传输，全功能 API，支持 **Laravel / Hyperf / ThinkPHP / Webman**。

## 要求

- PHP >= 8.1
- etcd v3.x 服务端
- PSR-18 + PSR-17 HTTP 客户端（HTTP 传输必需，各框架通常自带）

## 安装

```bash
composer require erikwang2013/etcd
```

## 快速开始

```php
use Erikwang2013\Etcd\EtcdClient;

$etcd = new EtcdClient(['endpoints' => ['127.0.0.1:2379']]);

// 写入
$etcd->kv()->put('/app/config', '{"debug":true}');

// 读取
$result = $etcd->kv()->get('/app/config');
print_r($result['kvs'][0]);  // ['key' => '/app/config', 'value' => '{"debug":true}', ...]

// 找不到时抛异常
$kv = $etcd->kv()->getOrFail('/app/config');

// 前缀扫描
$all = $etcd->kv()->getByPrefix('/app/');
echo "共 {$all['count']} 条\n";

// 删除
$etcd->kv()->delete('/app/config');
$etcd->kv()->deleteByPrefix('/cache/');

// 带租约写入（60 秒后自动删除）
$lease = $etcd->lease()->grant(60);
$etcd->kv()->put('/session/123', 'active', ['lease' => $lease['ID']]);

// 续约
$etcd->lease()->keepAlive($lease['ID']);
```

## 配置

```php
$etcd = new EtcdClient([
    'endpoints' => ['192.168.1.10:2379', '192.168.1.11:2379'],  // 多节点
    'transport' => 'auto',  // auto（默认）| http | grpc
    'scheme'    => 'http',  // http（默认）| https
    'timeout'   => 5.0,     // 秒（需在 PSR-18 客户端配置）
    'retry'     => 3,       // 连接失败重试次数
    'auth'      => [        // 可选，Basic Auth
        'user'     => 'root',
        'password' => 'secret',
    ],
]);
```

### 环境变量

不传配置时自动读取环境变量：

| 变量 | 默认值 | 说明 |
|------|--------|------|
| `ETCD_ENDPOINTS` | `127.0.0.1:2379` | 逗号分隔的多节点地址 |
| `ETCD_TRANSPORT` | `auto` | auto / http / grpc |
| `ETCD_TIMEOUT` | `5.0` | 请求超时（秒） |
| `ETCD_SCHEME` | `http` | http / https |
| `ETCD_RETRY` | `2` | 连接重试次数 |
| `ETCD_USER` | — | etcd 用户名 |
| `ETCD_PASSWORD` | — | etcd 密码 |

## API 参考

### KV — 键值操作

```php
// 写入
$etcd->kv()->put('key', 'value', [
    'lease'       => 12345,    // 绑定租约 ID
    'prevKv'      => true,     // 返回写入前的旧值
    'ignoreValue' => false,
    'ignoreLease' => false,
]);

// 读取单键
$etcd->kv()->get('/exact/key');

// 找不到即抛异常
$kv = $etcd->kv()->getOrFail('/exact/key');

// 前缀扫描
$etcd->kv()->getByPrefix('/prefix/');

// 范围查询（完整参数）
$etcd->kv()->get('/start', [
    'rangeEnd'    => '/startz',      // 范围结束 key
    'limit'       => 100,             // 最大返回条数
    'revision'    => 42,              // 快照版本号
    'sortOrder'   => 'ascend',        // none | ascend | descend
    'sortTarget'  => 'key',           // key | version | create | mod | value
    'serializable'=> true,            // 跳过 Raft 共识（更快，可能过期）
    'keysOnly'    => true,            // 只返回 key，不返回 value
    'countOnly'   => false,           // 只返回计数
]);

// 删除
$etcd->kv()->delete('/key');
$etcd->kv()->deleteByPrefix('/prefix/');
$etcd->kv()->delete('/key', ['prevKv' => true]);  // 同时返回被删的值

// 事务（原子 CAS）
$etcd->kv()->txn(
    compare: [
        ['result' => 0, 'target' => 3, 'key' => '/counter', 'value' => '100']
    ],
    success: [
        ['request_put' => ['key' => '/counter', 'value' => '101']]
    ],
    failure: [
        ['request_put' => ['key' => '/counter', 'value' => '1']]
    ]
);

// 压缩历史版本（释放存储空间）
$etcd->kv()->compact(1000);
```

**比较目标（target）常量：** `0`=VERSION, `1`=CREATE, `2`=MOD, `3`=VALUE, `4`=LEASE  
**比较结果（result）常量：** `0`=EQUAL, `1`=GREATER, `2`=LESS, `3`=NOT_EQUAL

### Watch — 变更监听

```php
// 监听单个 key（阻塞模式，建议在协程/独立进程中运行）
$etcd->watch()->watch('/config/key', function (array $events) {
    foreach ($events as $event) {
        // $event: ['type' => 'PUT'|'DELETE', 'kv' => [...], 'prev_kv' => [...]|null]
        echo "{$event['type']} {$event['kv']['key']} = {$event['kv']['value']}\n";
    }
});

// 监听前缀下所有 key 的变更
$etcd->watch()->watchPrefix('/config/', $callback, [
    'startRevision' => 100,       // 从指定版本开始
    'prevKv'        => true,      // DELETE 事件返回原值
    'progressNotify'=> true,      // 定期发送空事件（心跳）
]);
```

**断线重连：** Watch 连接断开时自动从上一次收到的 revision 续订，不会丢失事件。

### Lease — 租约

```php
// 创建租约
$lease = $etcd->lease()->grant(300);             // 300 秒 TTL
$lease = $etcd->lease()->grant(300, 99999);      // 指定租约 ID

// 续约（单次）
$result = $etcd->lease()->keepAlive($lease['ID']);
echo "TTL 剩余: {$result['TTL']} 秒";

// 查看租约状态
$info = $etcd->lease()->timeToLive($lease['ID']);
$info = $etcd->lease()->timeToLive($lease['ID'], true);  // 含绑定的 key 列表

// 列出所有活跃租约
$leases = $etcd->lease()->list();

// 撤销租约（绑定的所有 key 立即删除）
$etcd->lease()->revoke($lease['ID']);
```

**典型场景：** 服务注册时创建租约 + 写入 key，定时调用 `keepAlive()` 心跳续约；服务停止后租约到期自动清理。

### Auth — 认证与权限

```php
$auth = $etcd->auth();

// === 用户管理 ===
$auth->user()->add('alice', 'password123');          // 创建用户
$auth->user()->get('alice');                         // 查看用户及其角色
$auth->user()->list();                               // 列出所有用户
$auth->user()->changePassword('alice', 'newpass');    // 修改密码
$auth->user()->grantRole('alice', 'admin');          // 授权角色
$auth->user()->revokeRole('alice', 'admin');         // 撤销角色
$auth->user()->delete('alice');                      // 删除用户

// === 角色管理 ===
$auth->role()->add('reader');                        // 创建角色
$auth->role()->get('reader');                        // 查看角色权限
$auth->role()->list();                               // 列出所有角色

// 授予权限（permType: 0=READ, 1=WRITE, 2=READWRITE）
$auth->role()->grantPermission('reader', 0, '/data/', "\0");   // 对 /data/ 前缀的读权限
$auth->role()->grantPermission('writer', 2, '/data/', "\0");   // 读写权限
$auth->role()->revokePermission('reader', '/data/', "\0");     // 撤销权限
$auth->role()->delete('reader');

// === 认证开关 ===
$auth->enable();           // 开启认证
$auth->disable();          // 关闭认证
$status = $auth->status(); // ['enabled' => true, 'authRevision' => 5]
```

**注意：** 开启认证后，客户端必须配置 `auth.user` 和 `auth.password` 才能继续操作。

### Cluster — 集群管理

```php
// 查看集群成员
$members = $etcd->cluster()->memberList();

// 添加成员
$etcd->cluster()->memberAdd(['http://node3:2380']);        // 添加 Voting 成员
$etcd->cluster()->memberAdd(['http://node4:2380'], true);  // 添加 Learner 成员

// 修改成员 peer URL
$etcd->cluster()->memberUpdate(123456, ['http://newnode:2380']);

// Learner 提升为 Voter
$etcd->cluster()->memberPromote(789012);

// 移除成员
$etcd->cluster()->memberRemove(345678);
```

### Maintenance — 运维

```php
// 查看节点状态
$status = $etcd->maintenance()->status();
// ['version' => '3.5.0', 'dbSize' => 24576, 'leader' => 123, 'raftIndex' => 1000, ...]

// 告警管理
$alarms = $etcd->maintenance()->alarm();                    // 查看告警
$etcd->maintenance()->alarm(action: 2, alarm: 1);           // 清除 NOSPACE 告警

// 碎片整理（回收存储空间）
$etcd->maintenance()->defragment();

// KV 哈希校验
$hash = $etcd->maintenance()->hash();

// 获取快照（返回二进制数据，写入文件即可）
$snapshot = $etcd->maintenance()->snapshot();
file_put_contents('/backup/etcd-snapshot.db', $snapshot);
```

## 传输模式

| 模式 | 状态 | 依赖 | 适用场景 |
|------|------|------|---------|
| **HTTP** | 可用 | PSR-18 + PSR-17 | 零扩展依赖，即刻可用 |
| **gRPC** | 骨架 | ext-grpc + grpc/grpc + google/protobuf | 高性能、原生流式 |
| **auto** | 默认 | 自动检测 | 有 gRPC 则用 gRPC，否则 HTTP |

`auto` 模式检测逻辑：
1. `extension_loaded('grpc')` — C 扩展已加载？
2. `class_exists('Grpc\BaseStub')` — `grpc/grpc` composer 包已安装？

两者都满足才走 gRPC，否则回退 HTTP。

### 手动配置 PSR-18 HTTP 客户端

```php
use Erikwang2013\Etcd\Transport\HttpTransport;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;

$transport = new HttpTransport(['127.0.0.1:2379'], ['timeout' => 3.0]);
$transport->setHttpClient(
    new Client(['timeout' => 3]),
    new HttpFactory(),
    new HttpFactory()
);
```

## 框架集成

### Laravel

安装即用。composer.json 的 `extra.laravel` 自动发现 ServiceProvider 和 Facade。

```php
// Facade 方式
use Etcd;
Etcd::kv()->put('/foo', 'bar');
$val = Etcd::kv()->get('/foo');

// 依赖注入方式
use Erikwang2013\Etcd\EtcdClient;

class MyService
{
    public function __construct(private EtcdClient $etcd) {}

    public function work(): void
    {
        $this->etcd->kv()->put('/key', 'value');
    }
}
```

发布配置文件：

```bash
php artisan vendor:publish --tag=etcd-config
# → config/etcd.php
```

`.env` 配置：

```env
ETCD_ENDPOINTS=10.0.0.1:2379,10.0.0.2:2379
ETCD_USER=root
ETCD_PASSWORD=secret
```

### Hyperf

安装即用。Hyperf 自动发现 `ConfigProvider`。

```php
use Erikwang2013\Etcd\EtcdClient;
use Hyperf\Di\Annotation\Inject;

class MyService
{
    #[Inject]
    private EtcdClient $etcd;

    public function work(): void
    {
        $this->etcd->kv()->put('/key', 'value');
    }
}

// 或者直接 make
$etcd = make(EtcdClient::class);
```

发布配置：

```bash
php bin/hyperf.php vendor:publish erikwang2013/etcd
# → config/autoload/etcd.php
```

### ThinkPHP

1. 安装后，在 `app/service.php` 中注册：

```php
return [
    Erikwang2013\Etcd\Adapter\ThinkPHP\Service::class,
];
```

2. 创建 `config/etcd.php` 配置文件。

使用：

```php
// Facade 方式
use think\facade\Etcd;
Etcd::kv()->put('/key', 'value');

// 容器方式
app('etcd')->kv()->get('/key');
```

### Webman

安装即用，无需额外配置。

```php
use Erikwang2013\Etcd\EtcdClient;

$etcd = EtcdClient::instance();
$etcd->kv()->put('/key', 'value');
```

如需自定义配置，编辑 `plugin/erikwang2013/etcd/config/etcd.php`。

## 异常处理

```php
use Erikwang2013\Etcd\Exception\{
    EtcdException,
    ConnectionException,
    AuthException,
    KeyNotFoundException,
};

try {
    $etcd->kv()->put('/key', 'value');
} catch (ConnectionException $e) {
    // etcd 节点无法连接（网络故障、宕机）
} catch (AuthException $e) {
    // 认证失败（用户名密码错误）
} catch (KeyNotFoundException $e) {
    // getOrFail() 时 key 不存在
} catch (EtcdException $e) {
    // 其他 etcd 服务端错误
}
```

## 目录结构

```
src/
├── EtcdClient.php              # 顶层门面
├── Transport/                  # 传输层
│   ├── TransportInterface.php  #   传输抽象接口
│   ├── TransportSelector.php   #   自动选择逻辑
│   ├── HttpTransport.php       #   HTTP JSON 传输（完整可用）
│   └── GrpcTransport.php       #   gRPC 传输（骨架）
├── Kv/KvClient.php             # KV 键值操作
├── Watch/WatchClient.php       # Watch 变更监听
├── Lease/LeaseClient.php       # Lease 租约管理
├── Auth/                       # Auth 认证授权
│   ├── AuthClient.php          #   开关/状态
│   ├── UserClient.php          #   用户 CRUD
│   └── RoleClient.php          #   角色 CRUD + 权限
├── Cluster/ClusterClient.php   # Cluster 集群管理
├── Maintenance/                # Maintenance 运维操作
├── Exception/                  # 异常层次
├── Protobuf/                   # 消息桩（60+ 类）
└── Adapter/                    # 框架适配器
    ├── Laravel/                #   ServiceProvider + Facade
    ├── Hyperf/                 #   ConfigProvider
    ├── ThinkPHP/               #   Service + Facade
    └── Webman/                 #   Plugin
```

## 开源不易，欢迎支持 / Support This Project

<p align="center">
  <table>
    <tr>
      <td align="center"><b>微信 / WeChat</b></td>
      <td align="center"><b>支付宝 / Alipay</b></td>
    </tr>
    <tr>
      <td align="center"><img src="./docs/weixinpay.png" alt="微信支付" width="130" height="130" /></td>
      <td align="center"><img src="./docs/alipay.png" alt="支付宝" width="130" height="130" /></td>
    </tr>
  </table>
</p>

---

## License

MIT — Copyright (c) 2026 [erik](https://erik.xyz) <erik@erik.xyz>
