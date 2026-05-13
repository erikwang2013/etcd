# erikwang2013/etcd

PHP etcd v3 客户端 — gRPC + HTTP 双模传输，全功能 API，支持 Laravel / Hyperf / ThinkPHP / Webman。

## 要求

- PHP >= 8.1
- etcd v3.x 服务端
- `psr/http-client` + `psr/http-factory`（HTTP 传输必需）

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

// 前缀扫描
$all = $etcd->kv()->getByPrefix('/app/');
echo "共 {$all['count']} 条\n";

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
    'transport' => 'http',   // http | grpc | auto（默认 http）
    'timeout'   => 5.0,      // 请求超时（秒）
    'retry'     => 3,        // 重试次数
    'auth'      => [         // 可选，Basic Auth
        'user'     => 'root',
        'password' => 'secret',
    ],
]);
```

### 环境变量

未显式传参时读取环境变量：

| 变量 | 默认值 |
|------|--------|
| `ETCD_ENDPOINTS` | `127.0.0.1:2379` |
| `ETCD_TRANSPORT` | `http` |
| `ETCD_TIMEOUT` | `5.0` |
| `ETCD_RETRY` | `2` |
| `ETCD_USER` | — |
| `ETCD_PASSWORD` | — |

## API 参考

### KV — 键值操作

```php
// 写入
$etcd->kv()->put('key', 'value', [
    'lease'       => 12345,   // 绑定租约 ID
    'prevKv'      => true,    // 返回旧值
    'ignoreValue' => false,
    'ignoreLease' => false,
]);

// 读取单键
$etcd->kv()->get('/exact/key');

// 前缀扫描
$etcd->kv()->getByPrefix('/prefix/');

// 高级范围查询
$etcd->kv()->get('/start', [
    'rangeEnd'    => '/startz',    // 范围结束
    'limit'       => 100,          // 最大条数
    'revision'    => 42,           // 快照版本
    'sortOrder'   => 'ascend',     // none | ascend | descend
    'sortTarget'  => 'key',        // key | version | create | mod | value
    'serializable'=> true,         // 跳过共识（更快，可能过期）
    'keysOnly'    => true,         // 只返回 key
    'countOnly'   => false,        // 只返回计数
]);

// 删除
$etcd->kv()->delete('/key');
$etcd->kv()->deleteByPrefix('/prefix/');
$etcd->kv()->delete('/key', ['prevKv' => true]);  // 返回被删的值

// 事务
$etcd->kv()->txn(
    compare: [['result' => 0, 'target' => 3, 'key' => '/counter', 'value' => '100']],
    success: [['request_put' => ['key' => '/counter', 'value' => '101']]],
    failure: [['request_put' => ['key' => '/counter', 'value' => '1']]]
);

// 压缩历史版本
$etcd->kv()->compact(1000);
```

### Watch — 监听

```php
// 监听单个 key（阻塞，在协程/独立进程中运行）
$etcd->watch()->watch('/config/key', function (array $events) {
    foreach ($events as $event) {
        // $event: ['type' => 'PUT'|'DELETE', 'kv' => [...], 'prev_kv' => [...]]
        echo "{$event['type']} {$event['kv']['key']} = {$event['kv']['value']}\n";
    }
});

// 监听前缀
$etcd->watch()->watchPrefix('/config/', $callback, [
    'startRevision' => 100,    // 从指定版本开始
    'prevKv'        => true,   // DELETE 事件返回原值
]);
```

### Lease — 租约

```php
$lease = $etcd->lease()->grant(300);          // 300 秒
$lease = $etcd->lease()->grant(300, 99999);   // 指定 ID

$etcd->lease()->keepAlive($lease['ID']);       // 单次续约
$etcd->lease()->timeToLive($lease['ID']);      // 查 TTL
$etcd->lease()->timeToLive($lease['ID'], true); // 含绑定的 key 列表
$etcd->lease()->list();                        // 所有活跃租约
$etcd->lease()->revoke($lease['ID']);           // 撤销（绑定的 key 被删除）
```

### Auth — 认证授权

```php
$auth = $etcd->auth();

// 用户管理
$auth->user()->add('alice', 'password123');
$auth->user()->get('alice');           // 查看用户角色
$auth->user()->list();                 // 所有用户
$auth->user()->changePassword('alice', 'newpass');
$auth->user()->grantRole('alice', 'admin');
$auth->user()->revokeRole('alice', 'admin');
$auth->user()->delete('alice');

// 角色管理
$auth->role()->add('reader');
$auth->role()->get('reader');          // 查看角色权限
$auth->role()->list();                 // 所有角色
$auth->role()->grantPermission('reader', 0, '/data/', "\0");  // READ 前缀权限
$auth->role()->grantPermission('writer', 1, '/data/', "\0");  // WRITE
$auth->role()->revokePermission('reader', '/data/', "\0");
$auth->role()->delete('reader');

// 开关认证
$auth->enable();
$auth->disable();
$auth->status();  // ['enabled' => true, 'authRevision' => 5]
```

### Cluster — 集群管理

```php
$etcd->cluster()->memberList();                          // 列出所有节点
$etcd->cluster()->memberAdd(['http://node3:2380']);      // 添加节点
$etcd->cluster()->memberAdd(['http://node4:2380'], true); // 添加 Learner
$etcd->cluster()->memberUpdate(123, ['http://node:2380']);// 更新 peer URL
$etcd->cluster()->memberPromote(456);                     // Learner → Voter
$etcd->cluster()->memberRemove(789);                      // 移除节点
```

### Maintenance — 运维

```php
$etcd->maintenance()->status();       // 版本、DB 大小、Raft 信息
$etcd->maintenance()->alarm();        // 查看告警
$etcd->maintenance()->alarm(action: 2, alarm: 1);  // 清除 NOSPACE 告警
$etcd->maintenance()->defragment();   // 碎片整理
$etcd->maintenance()->hash();         // KV 哈希校验
$etcd->maintenance()->snapshot();     // 获取快照二进制数据
```

## 传输模式

| 模式 | 依赖 | 适用场景 |
|------|------|---------|
| **HTTP**（默认） | PSR-18 + PSR-17 | 无需扩展，即刻可用 |
| **gRPC** | `ext-grpc` + `grpc/grpc` + `google/protobuf` | 高性能、原生流式 |

```php
// 显式指定传输
new EtcdClient(['transport' => 'http']);  // HTTP 传输（默认）
new EtcdClient(['transport' => 'grpc']);  // gRPC 传输
new EtcdClient(['transport' => 'auto']);  // 自动检测 gRPC 扩展
```

### 配置 PSR-18 HTTP 客户端

```php
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;

$transport = new HttpTransport(['127.0.0.1:2379']);
$transport->setHttpClient(
    new Client(['timeout' => 5]),
    new HttpFactory(),
    new HttpFactory()
);
```

## 框架集成

### Laravel

无需额外配置。composer install 后自动发现 ServiceProvider。

```php
// Facade 方式
use Etcd;

Etcd::kv()->put('/foo', 'bar');
$val = Etcd::kv()->get('/foo');

// 依赖注入方式
use Erikwang2013\Etcd\EtcdClient;

class MyService {
    public function __construct(EtcdClient $etcd) {
        $this->etcd = $etcd;
    }
}
```

发布配置文件：

```bash
php artisan vendor:publish --tag=etcd-config
# → config/etcd.php
```

`.env` 配置：

```
ETCD_ENDPOINTS=10.0.0.1:2379,10.0.0.2:2379
ETCD_USER=root
ETCD_PASSWORD=secret
```

### Hyperf

composer install 后自动发现 ConfigProvider。

```php
use Erikwang2013\Etcd\EtcdClient;
use Hyperf\Di\Annotation\Inject;

class MyService {
    #[Inject]
    private EtcdClient $etcd;

    public function doWork() {
        $this->etcd->kv()->put('/key', 'value');
    }
}
```

发布配置：

```bash
php bin/hyperf.php vendor:publish erikwang2013/etcd
# → config/autoload/etcd.php
```

### ThinkPHP

在 `config/etcd.php` 添加配置后，注册 Service。在应用的 `service.php` 中：

```php
return [
    Erikwang2013\Etcd\Adapter\ThinkPHP\Service::class,
];
```

使用：

```php
use think\facade\Etcd;

Etcd::kv()->put('/key', 'value');
// 或者
app('etcd')->kv()->get('/key');
```

### Webman

composer install 后即可用。如需自定义配置，编辑 `plugin/erikwang2013/etcd/config/etcd.php`。

```php
use Erikwang2013\Etcd\EtcdClient;

$etcd = EtcdClient::instance();
$etcd->kv()->put('/key', 'value');
```

## 错误处理

```php
use Erikwang2013\Etcd\Exception\{
    EtcdException,
    ConnectionException,
    AuthException,
    KeyNotFoundException
};

try {
    $etcd->kv()->put('/key', 'value');
} catch (ConnectionException $e) {
    // etcd 无法连接
} catch (AuthException $e) {
    // 认证失败
} catch (EtcdException $e) {
    // 其他 etcd 错误
}
```

## License

MIT
