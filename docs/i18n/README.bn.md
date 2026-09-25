# erikwang2013/etcd

<p align="center">
  <a href="../../README.md">简体中文</a> ·
  <a href="README.en.md">English</a> ·
  <a href="README.ko.md">한국어</a> ·
  <a href="README.ru.md">Русский</a> ·
  <a href="README.de.md">Deutsch</a> ·
  <a href="README.fr.md">Français</a> ·
  <a href="README.es.md">Español</a> ·
  <a href="README.pt.md">Português</a> ·
  <a href="README.hi.md">हिन्दी</a> ·
  <a href="README.ar.md">العربية</a> ·
  <a href="README.bn.md">বাংলা</a> ·
  <a href="README.id.md">Bahasa Indonesia</a> ·
  <a href="README.ja.md">日本語</a>
</p>

<p align="center">
  <img src="../pet.svg" alt="প্রজেক্ট পেট Etchy" width="200" />
</p>

<p align="center">
  <b>Etchy</b> · মাথায় তিন-নোডের Raft ক্লাস্টার, শুঁড়ে <code>k/v</code> আর <code>rev</code> ঝুলিয়ে রাখা ছোট্ট হাতি ——<br/>
  তোমার কনফিগ আর লিজ পাহারা দেয়: কানেকশন গেলে নিজেই রিকানেক্ট, মেয়াদ শেষে নিজেই ক্লিনআপ।
</p>

PHP etcd v3 ক্লায়েন্ট —— gRPC + HTTP দ্বৈত ট্রান্সপোর্ট, etcd v3-এর সম্পূর্ণ API (KV / Watch / Lease / Auth / Cluster / Maintenance) কভার করে, আর **Laravel / Hyperf / ThinkPHP / Webman**-এ আউট-অব-দ্য-বক্স চলে।

## প্রয়োজনীয়তা

- PHP >= 8.1
- etcd v3.x সার্ভার
- PSR-18 + PSR-17 HTTP ক্লায়েন্ট (HTTP ট্রান্সপোর্টের জন্য আবশ্যক, বেশিরভাগ ফ্রেমওয়ার্কে সাধারণত আগে থেকেই থাকে)

## ইনস্টলেশন

```bash
composer require erikwang2013/etcd
```

## দ্রুত শুরু

```php
use Erikwang2013\Etcd\EtcdClient;

$etcd = new EtcdClient(['endpoints' => ['127.0.0.1:2379']]);

// লেখা
$etcd->kv()->put('/app/config', '{"debug":true}');

// পড়া
$result = $etcd->kv()->get('/app/config');
print_r($result['kvs'][0]);  // ['key' => '/app/config', 'value' => '{"debug":true}', ...]

// না মিললে এক্সেপশন তোলে
$kv = $etcd->kv()->getOrFail('/app/config');

// prefix স্ক্যান
$all = $etcd->kv()->getByPrefix('/app/');
echo "মোট {$all['count']}টি কী\n";

// মুছে ফেলা
$etcd->kv()->delete('/app/config');
$etcd->kv()->deleteByPrefix('/cache/');

// lease সহ লেখা (60 সেকেন্ড পরে স্বয়ংক্রিয়ভাবে মুছে যায়)
$lease = $etcd->lease()->grant(60);
$etcd->kv()->put('/session/123', 'active', ['lease' => $lease['ID']]);

// রিনিউ
$etcd->lease()->keepAlive($lease['ID']);
```

## কনফিগারেশন

```php
$etcd = new EtcdClient([
    'endpoints' => ['192.168.1.10:2379', '192.168.1.11:2379'],  // একাধিক নোড
    'transport' => 'auto',  // auto (ডিফল্ট) | http | grpc
    'scheme'    => 'http',  // http (ডিফল্ট) | https
    'timeout'   => 5.0,     // সেকেন্ড (PSR-18 ক্লায়েন্টে কনফিগ করতে হবে)
    'retry'     => 3,       // কানেকশন ব্যর্থ হলে রিট্রাই সংখ্যা
    'auth'      => [        // ঐচ্ছিক, Basic Auth
        'user'     => 'root',
        'password' => 'secret',
    ],
]);
```

### এনভায়রনমেন্ট ভেরিয়েবল

কনফিগ না দিলে স্বয়ংক্রিয়ভাবে এনভায়রনমেন্ট ভেরিয়েবল পড়া হয়:

| ভেরিয়েবল | ডিফল্ট | বর্ণনা |
|------|--------|------|
| `ETCD_ENDPOINTS` | `127.0.0.1:2379` | কমা দিয়ে আলাদা করা একাধিক নোডের ঠিকানা |
| `ETCD_TRANSPORT` | `auto` | auto / http / grpc |
| `ETCD_TIMEOUT` | `5.0` | রিকোয়েস্ট টাইমআউট (সেকেন্ড) |
| `ETCD_SCHEME` | `http` | http / https |
| `ETCD_RETRY` | `2` | কানেকশন রিট্রাই সংখ্যা |
| `ETCD_USER` | — | etcd ইউজারনেম |
| `ETCD_PASSWORD` | — | etcd পাসওয়ার্ড |

## API রেফারেন্স

### KV — কি-ভ্যালু অপারেশন

```php
// লেখা
$etcd->kv()->put('key', 'value', [
    'lease'       => 12345,    // লিজ ID বাইন্ড
    'prevKv'      => true,     // লেখার আগের পুরনো value ফেরায়
    'ignoreValue' => false,
    'ignoreLease' => false,
]);

// একক key পড়া
$etcd->kv()->get('/exact/key');

// না মিললেই এক্সেপশন তোলে
$kv = $etcd->kv()->getOrFail('/exact/key');

// prefix স্ক্যান
$etcd->kv()->getByPrefix('/prefix/');

// range কোয়েরি (সম্পূর্ণ প্যারামিটার)
$etcd->kv()->get('/start', [
    'rangeEnd'    => '/startz',      // range-এর শেষ key
    'limit'       => 100,             // সর্বোচ্চ কতটি ফেরাবে
    'revision'    => 42,              // স্ন্যাপশট revision
    'sortOrder'   => 'ascend',        // none | ascend | descend
    'sortTarget'  => 'key',           // key | version | create | mod | value
    'serializable'=> true,            // Raft কনসেন্সাস বাদ (দ্রুত, তবে পুরনো হতে পারে)
    'keysOnly'    => true,            // শুধু key ফেরায়, value নয়
    'countOnly'   => false,           // শুধু সংখ্যা ফেরায়
]);

// মুছে ফেলা
$etcd->kv()->delete('/key');
$etcd->kv()->deleteByPrefix('/prefix/');
$etcd->kv()->delete('/key', ['prevKv' => true]);  // মুছে ফেলা value-ও ফেরায়

// ট্রানজেকশন (অ্যাটমিক CAS)
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

// পুরনো revision কম্প্যাক্ট (স্টোরেজ খালি করে)
$etcd->kv()->compact(1000);
```

**তুলনা টার্গেট (target) কনস্ট্যান্ট:** `0`=VERSION, `1`=CREATE, `2`=MOD, `3`=VALUE, `4`=LEASE  
**তুলনা ফলাফল (result) কনস্ট্যান্ট:** `0`=EQUAL, `1`=GREATER, `2`=LESS, `3`=NOT_EQUAL

### Watch — পরিবর্তন মনিটরিং

```php
// একক key ওয়াচ (ব্লকিং মোড, করুটিন/আলাদা প্রসেসে চালানো ভালো)
$etcd->watch()->watch('/config/key', function (array $events) {
    foreach ($events as $event) {
        // $event: ['type' => 'PUT'|'DELETE', 'kv' => [...], 'prev_kv' => [...]|null]
        echo "{$event['type']} {$event['kv']['key']} = {$event['kv']['value']}\n";
    }
});

// prefix-এর সব key-র পরিবর্তন ওয়াচ
$etcd->watch()->watchPrefix('/config/', $callback, [
    'startRevision' => 100,       // নির্দিষ্ট revision থেকে শুরু
    'prevKv'        => true,      // DELETE ইভেন্টে আগের value ফেরায়
    'progressNotify'=> true,      // নিয়মিত খালি ইভেন্ট (হার্টবিট) পাঠায়
]);
```

**ডিসকানেক্টে রিকানেক্ট:** Watch কানেকশন কেটে গেলে শেষবার পাওয়া revision থেকেই স্বয়ংক্রিয়ভাবে সাবস্ক্রিপশন চালু হয়, কোনো ইভেন্ট হারায় না।

### Lease — লিজ

```php
// লিজ তৈরি
$lease = $etcd->lease()->grant(300);             // 300 সেকেন্ড TTL
$lease = $etcd->lease()->grant(300, 99999);      // নির্দিষ্ট lease ID

// রিনিউ (একবার)
$result = $etcd->lease()->keepAlive($lease['ID']);
echo "TTL বাকি: {$result['TTL']} সেকেন্ড";

// লিজের স্ট্যাটাস দেখা
$info = $etcd->lease()->timeToLive($lease['ID']);
$info = $etcd->lease()->timeToLive($lease['ID'], true);  // বাউন্ড key-র তালিকাসহ

// সক্রিয় সব লিজের তালিকা
$leases = $etcd->lease()->list();

// লিজ রিভোক (বাউন্ড সব key সাথে সাথে মুছে যায়)
$etcd->lease()->revoke($lease['ID']);
```

**সাধারণ ব্যবহার:** সার্ভিস রেজিস্ট্রেশনের সময় লিজ তৈরি করে key লেখা হয়, নির্দিষ্ট বিরতিতে `keepAlive()` হার্টবিট দেওয়া হয়; সার্ভিস বন্ধ হলে TTL শেষে লিজ নিজেই পরিষ্কার হয়ে যায়।

### Auth — অথ ও পারমিশন

```php
$auth = $etcd->auth();

// === ইউজার ম্যানেজমেন্ট ===
$auth->user()->add('alice', 'password123');          // ইউজার তৈরি
$auth->user()->get('alice');                         // ইউজার ও তার রোল দেখা
$auth->user()->list();                               // সব ইউজারের তালিকা
$auth->user()->changePassword('alice', 'newpass');    // পাসওয়ার্ড বদল
$auth->user()->grantRole('alice', 'admin');          // রোল দেওয়া
$auth->user()->revokeRole('alice', 'admin');         // রোল প্রত্যাহার
$auth->user()->delete('alice');                      // ইউজার মুছে ফেলা

// === রোল ম্যানেজমেন্ট ===
$auth->role()->add('reader');                        // রোল তৈরি
$auth->role()->get('reader');                        // রোলের পারমিশন দেখা
$auth->role()->list();                               // সব রোলের তালিকা

// পারমিশন দেওয়া (permType: 0=READ, 1=WRITE, 2=READWRITE)
$auth->role()->grantPermission('reader', 0, '/data/', "\0");   // /data/ prefix-এ রিড পারমিশন
$auth->role()->grantPermission('writer', 2, '/data/', "\0");   // রিড-রাইট পারমিশন
$auth->role()->revokePermission('reader', '/data/', "\0");     // পারমিশন প্রত্যাহার
$auth->role()->delete('reader');

// === অথ চালু/বন্ধ ===
$auth->enable();           // অথ চালু
$auth->disable();          // অথ বন্ধ
$status = $auth->status(); // ['enabled' => true, 'authRevision' => 5]
```

**লক্ষ্য করুন:** অথ চালু করার পর ক্লায়েন্টে অবশ্যই `auth.user` ও `auth.password` কনফিগ করা থাকতে হবে, নইলে কাজ চালানো যাবে না।

### Cluster — ক্লাস্টার ম্যানেজমেন্ট

```php
// ক্লাস্টার সদস্য দেখা
$members = $etcd->cluster()->memberList();

// সদস্য যোগ
$etcd->cluster()->memberAdd(['http://node3:2380']);        // Voting সদস্য যোগ
$etcd->cluster()->memberAdd(['http://node4:2380'], true);  // Learner সদস্য যোগ

// সদস্যের peer URL বদল
$etcd->cluster()->memberUpdate(123456, ['http://newnode:2380']);

// Learner-কে Voter-এ প্রমোট
$etcd->cluster()->memberPromote(789012);

// সদস্য সরানো
$etcd->cluster()->memberRemove(345678);
```

### Maintenance — অপারেশনস

```php
// নোড স্ট্যাটাস দেখা
$status = $etcd->maintenance()->status();
// ['version' => '3.5.0', 'dbSize' => 24576, 'leader' => 123, 'raftIndex' => 1000, ...]

// অ্যালার্ম ম্যানেজমেন্ট
$alarms = $etcd->maintenance()->alarm();                    // অ্যালার্ম দেখা
$etcd->maintenance()->alarm(action: 2, alarm: 1);           // NOSPACE অ্যালার্ম পরিষ্কার

// ডিফ্র্যাগমেন্ট (স্টোরেজ খালি করে)
$etcd->maintenance()->defragment();

// KV হ্যাশ চেক
$hash = $etcd->maintenance()->hash();

// স্ন্যাপশট নেওয়া (বাইনারি ডেটা ফেরায়, ফাইলে লিখলেই হয়)
$snapshot = $etcd->maintenance()->snapshot();
file_put_contents('/backup/etcd-snapshot.db', $snapshot);
```

## ট্রান্সপোর্ট মোড

| মোড | অবস্থা | ডিপেন্ডেন্সি | উপযোগী ক্ষেত্র |
|------|------|------|---------|
| **HTTP** | প্রস্তুত | PSR-18 + PSR-17 | এক্সটেনশন ডিপেন্ডেন্সি ছাড়াই সাথে সাথে চলে |
| **gRPC** | স্কেলিটন | ext-grpc + grpc/grpc + google/protobuf | উচ্চ পারফরম্যান্স, নেটিভ স্ট্রিমিং |
| **auto** | ডিফল্ট | স্বয়ংক্রিয় সনাক্তকরণ | gRPC থাকলে gRPC, নইলে HTTP |

`auto` মোডের সনাক্তকরণ লজিক:
1. `extension_loaded('grpc')` — C এক্সটেনশন লোড করা আছে?
2. `class_exists('Grpc\BaseStub')` — `grpc/grpc` composer প্যাকেজ ইনস্টল করা আছে?

দুটোই সত্যি হলে তবেই gRPC, নইলে HTTP-তে ফলব্যাক।

### ম্যানুয়ালি PSR-18 HTTP ক্লায়েন্ট কনফিগার

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

## ফ্রেমওয়ার্ক ইন্টিগ্রেশন

### Laravel

ইনস্টল করলেই চলবে। composer.json-এর `extra.laravel` ServiceProvider ও Facade স্বয়ংক্রিয়ভাবে খুঁজে নেয়।

```php
// Facade পদ্ধতি
use Etcd;
Etcd::kv()->put('/foo', 'bar');
$val = Etcd::kv()->get('/foo');

// ডিপেন্ডেন্সি ইনজেকশন পদ্ধতি
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

কনফিগ ফাইল পাবলিশ করুন:

```bash
php artisan vendor:publish --tag=etcd-config
# → config/etcd.php
```

`.env` কনফিগ:

```env
ETCD_ENDPOINTS=10.0.0.1:2379,10.0.0.2:2379
ETCD_USER=root
ETCD_PASSWORD=secret
```

### Hyperf

ইনস্টল করলেই চলবে। Hyperf নিজেই `ConfigProvider` খুঁজে নেয়।

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

// অথবা সরাসরি make
$etcd = make(EtcdClient::class);
```

কনফিগ পাবলিশ:

```bash
php bin/hyperf.php vendor:publish erikwang2013/etcd
# → config/autoload/etcd.php
```

### ThinkPHP

1. ইনস্টল করার পর `app/service.php`-তে রেজিস্টার করুন:

```php
return [
    Erikwang2013\Etcd\Adapter\ThinkPHP\Service::class,
];
```

2. `config/etcd.php` কনফিগ ফাইল তৈরি করুন।

ব্যবহার:

```php
// Facade পদ্ধতি
use think\facade\Etcd;
Etcd::kv()->put('/key', 'value');

// কন্টেইনার পদ্ধতি
app('etcd')->kv()->get('/key');
```

### Webman

ইনস্টল করলেই চলবে, বাড়তি কোনো কনফিগ লাগে না।

```php
use Erikwang2013\Etcd\EtcdClient;

$etcd = EtcdClient::instance();
$etcd->kv()->put('/key', 'value');
```

নিজস্ব কনফিগ দরকার হলে `plugin/erikwang2013/etcd/config/etcd.php` সম্পাদনা করুন।

## এক্সেপশন হ্যান্ডলিং

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
    // etcd নোডে কানেক্ট করা যায় না (নেটওয়ার্ক সমস্যা, ডাউন)
} catch (AuthException $e) {
    // অথ ব্যর্থ (ইউজারনেম/পাসওয়ার্ড ভুল)
} catch (KeyNotFoundException $e) {
    // getOrFail()-এ key নেই
} catch (EtcdException $e) {
    // অন্যান্য etcd সার্ভার ত্রুটি
}
```

## প্রজেক্ট স্ট্রাকচার

```
erikwang2013/etcd/
├── composer.json                    # প্যাকেজ ডেফিনিশন: PSR-4 অটোলোড + Laravel / Hyperf অটো-ডিসকভারি
├── phpunit.xml                      # PHPUnit কনফিগ (unit / integration দুটি স্যুট)
├── config/etcd.php                  # ডিফল্ট কনফিগ, ফ্রেমওয়ার্কে পাবলিশের জন্য (ETCD_* এনভায়রনমেন্ট ভেরিয়েবল পড়ে)
├── .github/workflows/release.yml    # tag দিলে স্বয়ংক্রিয় পাবলিশ
├── scripts/i18n/                    #   ডক্স টুলিং: ক্যাটালগ, ডায়াগ্রাম বিল্ডার, অনুবাদ যাচাই
├── docs/                            # ডকুমেন্টেশন ও ডিজাইন ডায়াগ্রাম
│   ├── design-cn.md                 #   ডিজাইন ডকুমেন্ট
│   ├── i18n/                        #   13টি ভাষায় README ও স্থানীয়কৃত ডায়াগ্রাম
│   ├── pet.svg                      #   প্রজেক্ট পেট Etchy
│   ├── architecture.svg             #   আর্কিটেকচার ডায়াগ্রাম
│   ├── features.svg                 #   ফিচার ডায়াগ্রাম
│   └── lifecycle.svg                #   লাইফসাইকল ডায়াগ্রাম
├── src/
│   ├── EtcdClient.php               # টপ-লেভেল ফ্যাসাড + সিঙ্গলটন: kv / watch / lease / auth / cluster / maintenance
│   ├── Mascot.php                   # প্রজেক্ট পেট Etchy পাওয়ার এন্ট্রি (svg / dataUri / path)
│   ├── Install.php                  # Webman প্লাগইন হুক (WEBMAN_PLUGIN)
│   ├── Transport/                   # ট্রান্সপোর্ট লেয়ার
│   │   ├── TransportInterface.php   #   ট্রান্সপোর্ট অ্যাবস্ট্রাকশন: send / sendRaw / watch
│   │   ├── TransportSelector.php    #   auto / http / grpc অটো সিলেকশন
│   │   ├── HttpTransport.php        #   HTTP JSON ট্রান্সপোর্ট (সম্পূর্ণ প্রস্তুত)
│   │   └── GrpcTransport.php        #   gRPC ট্রান্সপোর্ট (স্কেলিটন)
│   ├── Kv/KvClient.php              # KV রিড-রাইট / prefix স্ক্যান / ট্রানজেকশন / কম্প্যাক্ট
│   ├── Watch/WatchClient.php        # Watch পরিবর্তন মনিটরিং + ডিসকানেক্টে রিজিউম
│   ├── Lease/LeaseClient.php        # Lease লিজ grant / keepAlive / revoke
│   ├── Auth/                        # Auth অথ ও পারমিশন
│   │   ├── AuthClient.php           #   অথ সুইচ ও স্ট্যাটাস
│   │   ├── UserClient.php           #   ইউজার CRUD + রোল বাইন্ডিং
│   │   └── RoleClient.php           #   রোল CRUD + পারমিশন
│   ├── Cluster/ClusterClient.php    # Cluster ক্লাস্টার সদস্য ম্যানেজমেন্ট
│   ├── Maintenance/                 # Maintenance অপারেশনস: status / alarm / defrag / snapshot
│   ├── Exception/                   # এক্সেপশন হায়ারার্কি
│   ├── Protobuf/                    # মেসেজ স্টাব (সাধারণ PHP ডেটা ক্লাস, Message এক্সটেন্ড করে না)
│   │   ├── Mvccpb/                  #   KeyValue, Event
│   │   ├── Etcdserverpb/            #   60+ রিকোয়েস্ট / রেসপন্স মেসেজ
│   │   └── Authpb/                  #   User, Role, Permission
│   └── Adapter/                     # ফ্রেমওয়ার্ক অ্যাডাপ্টার
│       ├── Laravel/                 #   ServiceProvider + Facade
│       ├── Hyperf/                  #   ConfigProvider
│       ├── ThinkPHP/                #   Service + Facade
│       └── Webman/                  #   Plugin
└── tests/
    ├── Unit/                        # ইউনিট টেস্ট (প্রতিটি ক্লায়েন্ট / ট্রান্সপোর্ট / অ্যাডাপ্টার / মেসেজ ক্লাস)
    ├── Integration/                 # ইন্টিগ্রেশন টেস্ট (আসল etcd-র সাথে)
    └── Support/                     # FakeTransport, PSR HTTP স্টাব
```

## আর্কিটেকচার ও ডিজাইন ডায়াগ্রাম

তিনটি ডায়াগ্রাম "স্ট্রাকচার → সক্ষমতা → টাইমলাইন" ক্রমে সাজানো, আলাদা করেও দেখা যায়:

| ডায়াগ্রাম | যে প্রশ্নের উত্তর দেয় | ফাইল |
|----|-----------|------|
| আর্কিটেকচার ডিজাইন | কয়টি লেয়ার, নির্ভরতা কোন দিকে যায়, ত্রুটি কীভাবে ভাগ হয় | [`docs/architecture.svg`](diagrams/bn/architecture.svg) |
| ফিচার ডিজাইন | প্রতিটি সাবসিস্টেম কোন কোন মেথড দেয়, কী কী আচরণগত চুক্তি আছে | [`docs/features.svg`](diagrams/bn/features.svg) |
| লাইফসাইকল | একটি রিকোয়েস্ট / একটি watch / একটি lease কীভাবে শেষ পর্যন্ত চলে | [`docs/lifecycle.svg`](diagrams/bn/lifecycle.svg) |

### আর্কিটেকচার ডিজাইন

![আর্কিটেকচার ডিজাইন ডায়াগ্রাম](diagrams/bn/architecture.svg)

### ফিচার ডিজাইন

![ফিচার ডিজাইন ডায়াগ্রাম](diagrams/bn/features.svg)

### লাইফসাইকল

![লাইফসাইকল ডায়াগ্রাম](diagrams/bn/lifecycle.svg)

### কোডে Etchy ব্যবহার

ডায়াগ্রামগুলো প্যাকেজের সাথেই আসে, `Mascot` একমাত্র এন্ট্রি পয়েন্ট —— অ্যাডমিন প্যানেল বা স্ট্যাটাস পেজ বানাতে আলাদা কপি রাখতে হয় না:

```php
use Erikwang2013\Etcd\Mascot;

echo Mascot::svg();                                          // SVG সোর্স, সরাসরি ইনলাইন
echo '<img src="' . Mascot::dataUri() . '" alt="Etchy">';    // data URI, web ডিরেক্টরির উপর নির্ভর করে না
copy(Mascot::path(), __DIR__ . '/public/etcd.svg');          // অথবা নিজে স্ট্যাটিক ডিরেক্টরিতে রাখুন
```

Laravel-এ সরাসরি `public/`-এ পাবলিশ করা যায়:

```bash
php artisan vendor:publish --tag=etcd-assets
# → public/vendor/etcd/pet.svg
```

## ওপেন সোর্স সহজ নয়, সমর্থন জানান / Support This Project

<p align="center">
  <table>
    <tr>
      <td align="center"><b>微信 / WeChat</b></td>
      <td align="center"><b>支付宝 / Alipay</b></td>
    </tr>
    <tr>
      <td align="center"><img src="../weixinpay.png" alt="微信支付" width="130" height="130" /></td>
      <td align="center"><img src="../alipay.png" alt="支付宝" width="130" height="130" /></td>
    </tr>
  </table>
</p>

---

## License

MIT — Copyright (c) 2026 [erik](https://erik.xyz) <erik@erik.xyz>
