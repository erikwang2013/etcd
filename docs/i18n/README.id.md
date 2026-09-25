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
  <img src="../pet.svg" alt="Maskot proyek Etchy" width="200" />
</p>

<p align="center">
  <b>Etchy</b> · gajah kecil bertopi klaster Raft tiga node dan membawa <code>k/v</code> serta <code>rev</code> di ujung belalainya ——<br/>
  penjaga konfigurasi dan lease Anda: putus, ia menyambung sendiri; kedaluwarsa, ia membersihkan sendiri.
</p>

Klien etcd v3 untuk PHP —— transport dua mode (HTTP fitur penuh / gRPC RPC unary), mencakup seluruh API etcd v3 (KV / Watch / Lease / Auth / Cluster / Maintenance / Election / Lock), siap pakai dengan **Laravel / Hyperf / ThinkPHP / Webman**.

## Persyaratan

- PHP >= 8.1
- Server etcd v3.x
- Cukup satu jalur HTTP: ext-curl, stream wrapper PHP (allow_url_fopen), atau klien PSR-18 Anda sendiri — dipilih otomatis, dengan pesan jelas bila tidak ada

## Instalasi

```bash
composer require erikwang2013/etcd
```

### PHP murni (tanpa framework)

Tidak perlu framework, implementasi PSR-18, maupun ext-curl — bila ext-curl dimuat maka dipakai, jika tidak klien otomatis memakai stream wrapper bawaan PHP.

```php
<?php
require __DIR__ . '/vendor/autoload.php';   // autoload Anda

use Erikwang2013\Etcd\EtcdClient;

$etcd = new EtcdClient(['endpoints' => ['127.0.0.1:2379']]);

$etcd->kv()->put('/app/config', '{"debug":true}');
echo $etcd->kv()->getOrFail('/app/config')['value'], "\n";

// jalur yang sedang dipakai: curl / stream / none
var_dump(Erikwang2013\Etcd\Transport\HttpTransport::detectDriver());
```

Paksa satu jalur dengan `'driver' => 'stream'` (default `auto`). Bila ketiganya tidak tersedia, permintaan melempar `ConnectionException` yang bisa ditangkap dan menjelaskan apa yang perlu diaktifkan.

## Mulai Cepat

```php
use Erikwang2013\Etcd\EtcdClient;

$etcd = new EtcdClient(['endpoints' => ['127.0.0.1:2379']]);

// tulis
$etcd->kv()->put('/app/config', '{"debug":true}');

// baca
$result = $etcd->kv()->get('/app/config');
print_r($result['kvs'][0]);  // ['key' => '/app/config', 'value' => '{"debug":true}', ...]

// melempar exception bila tidak ditemukan
$kv = $etcd->kv()->getOrFail('/app/config');

// pemindaian prefix
$all = $etcd->kv()->getByPrefix('/app/');
echo "total {$all['count']} key\n";

// hapus
$etcd->kv()->delete('/app/config');
$etcd->kv()->deleteByPrefix('/cache/');

// tulis dengan lease (terhapus otomatis setelah 60 detik)
$lease = $etcd->lease()->grant(60);
$etcd->kv()->put('/session/123', 'active', ['lease' => $lease['ID']]);

// perpanjang lease
$etcd->lease()->keepAlive($lease['ID']);
```

## Konfigurasi

```php
$etcd = new EtcdClient([
    'endpoints' => ['192.168.1.10:2379', '192.168.1.11:2379'],  // beberapa node
    'transport' => 'auto',  // auto (default) | http | grpc
    'driver'    => 'auto',  // auto (default) | curl | stream
    'scheme'    => 'http',  // http (default) | https
    'timeout'   => 5.0,     // detik
    'retry'     => 3,       // jumlah percobaan ulang saat koneksi gagal
    'auth'      => [        // opsional; menukar kredensial dengan token butuh https
        'user'     => 'root',
        'password' => 'secret',
    ],
]);
```

### Variabel Environment

Tanpa konfigurasi eksplisit, variabel berikut dibaca otomatis:

| Variabel | Default | Keterangan |
|------|--------|------|
| `ETCD_ENDPOINTS` | `127.0.0.1:2379` | alamat node, dipisah koma |
| `ETCD_TRANSPORT` | `auto` | auto / http / grpc |
| `ETCD_TIMEOUT` | `5.0` | timeout request (detik) |
| `ETCD_SCHEME` | `http` | http / https |
| `ETCD_RETRY` | `2` | jumlah percobaan ulang koneksi |
| `ETCD_DRIVER` | `auto` | jalur HTTP: auto / curl / stream |
| `ETCD_USER` | — | nama pengguna etcd |
| `ETCD_PASSWORD` | — | kata sandi etcd |

## Referensi API

### KV — Operasi Key/Value

```php
// tulis
$etcd->kv()->put('key', 'value', [
    'lease'       => 12345,    // ID lease yang diikat
    'prevKv'      => true,     // kembalikan value lama sebelum ditulis
    'ignoreValue' => false,
    'ignoreLease' => false,
]);

// baca satu key
$etcd->kv()->get('/exact/key');

// melempar exception bila tidak ditemukan
$kv = $etcd->kv()->getOrFail('/exact/key');

// pemindaian prefix
$etcd->kv()->getByPrefix('/prefix/');

// kueri rentang (parameter lengkap)
$etcd->kv()->get('/start', [
    'rangeEnd'    => '/startz',      // key akhir rentang
    'limit'       => 100,             // jumlah maksimum yang dikembalikan
    'revision'    => 42,              // nomor revisi snapshot
    'sortOrder'   => 'ascend',        // none | ascend | descend
    'sortTarget'  => 'key',           // key | version | create | mod | value
    'serializable'=> true,            // lewati konsensus Raft (lebih cepat, bisa kedaluwarsa)
    'keysOnly'    => true,            // hanya kembalikan key, bukan value
    'countOnly'   => false,           // hanya kembalikan jumlah
    'minModRevision'    => 100,       // hanya key dengan revisi perubahan >= 100
    'maxModRevision'    => 200,
    'minCreateRevision' => 100,       // filter berdasarkan revisi pembuatan
    'maxCreateRevision' => 200,
]);

// hapus
$etcd->kv()->delete('/key');
$etcd->kv()->deleteByPrefix('/prefix/');
$etcd->kv()->delete('/key', ['prevKv' => true]);  // sekaligus kembalikan value yang dihapus

// transaksi (CAS atomik)
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

// transaksi bersarang: cabang bisa memuat transaksi lagi
$etcd->kv()->txn(
    compare: [['result' => 0, 'target' => 3, 'key' => '/lock', 'value' => 'free']],
    success: [[
        'request_put' => ['key' => '/lock', 'value' => 'mine'],
        'request_txn' => [                       // transaksi dalam
            'compare' => [['result' => 0, 'target' => 1, 'key' => '/lock', 'create_revision' => 0]],
            'success' => [['request_put' => ['key' => '/log', 'value' => 'acquired']]],
            'failure' => [],
        ],
    ]],
    failure: []
);

// padatkan versi historis (membebaskan ruang penyimpanan)
$etcd->kv()->compact(1000);
```

**Konstanta target perbandingan:** `0`=VERSION, `1`=CREATE, `2`=MOD, `3`=VALUE, `4`=LEASE  
**Konstanta hasil perbandingan:** `0`=EQUAL, `1`=GREATER, `2`=LESS, `3`=NOT_EQUAL

### Watch — Pemantauan Perubahan

```php
// pantau satu key (mode blocking, sebaiknya dijalankan di coroutine/proses terpisah)
$etcd->watch()->watch('/config/key', function (array $events) {
    foreach ($events as $event) {
        // $event: ['type' => 'PUT'|'DELETE', 'kv' => [...], 'prev_kv' => [...]|null]
        echo "{$event['type']} {$event['kv']['key']} = {$event['kv']['value']}\n";
    }
});

// pantau semua perubahan key di bawah prefix
$etcd->watch()->watchPrefix('/config/', $callback, [
    'startRevision' => 100,       // mulai dari revisi tertentu
    'prevKv'        => true,      // event DELETE mengembalikan value asli
    'progressNotify'=> true,      // kirim event kosong berkala (heartbeat)
]);
```

**Menghentikan watch:** `watch()` memblokir terus-menerus; berikan sebuah `WatchHandle` dan ia bisa dihentikan dari luar (kebutuhan lazim proses berjalan lama yang berhenti karena SIGTERM):

```php
use Erikwang2013\Etcd\Support\WatchHandle;

$handle = new WatchHandle();
pcntl_async_signals(true);
pcntl_signal(SIGTERM, fn() => $handle->cancel());

$etcd->watch()->watchPrefix('/config/', $onEvent, ['handle' => $handle]);
```

Setelah `cancel()`, `watch()` **kembali normal** (tidak melempar exception, tidak ada yang perlu di-catch). Bahkan pada key yang menganggur ia keluar dalam sekitar satu detik — driver curl mengetahuinya lewat callback berkala cURL, driver stream lewat siklus menganggur dengan read timeout 200ms.

**Reconnect:** saat koneksi Watch terputus, klien berlangganan ulang dari `lastRevision + 1` (`start_revision` bersifat **inklusif**, melanjutkan dengan nilai lama akan memutar ulang event terakhir). Failover tidak kehilangan event dan tidak mengirimnya dua kali.

**Kebijakan retry:** hanya kegagalan yang membuktikan koneksi tidak pernah terbentuk (koneksi ditolak / DNS gagal) yang dicoba ulang, dan RPC baca-saja (range, status, memberlist, dll.) juga mentoleransi 5xx dan timeout. Operasi tulis yang terkena 5xx atau timeout baca **tidak** dicoba ulang — permintaan itu bisa jadi sudah berlaku, dan mengulangnya menerapkan CAS dua kali, atau bahkan memberi "jawaban yang salah dengan yakin" (retry melihat tulisannya sendiri yang pertama lalu melaporkan CAS gagal padahal sebenarnya menang).

### Lease — Sewa

```php
// buat lease
$lease = $etcd->lease()->grant(300);             // TTL 300 detik
$lease = $etcd->lease()->grant(300, 99999);      // tentukan ID lease

// perpanjang lease (sekali)
$result = $etcd->lease()->keepAlive($lease['ID']);
echo "TTL tersisa: {$result['TTL']} detik";

// lihat status lease
$info = $etcd->lease()->timeToLive($lease['ID']);
$info = $etcd->lease()->timeToLive($lease['ID'], true);  // termasuk daftar key yang terikat

// daftar semua lease aktif
$leases = $etcd->lease()->list();

// cabut lease (semua key yang terikat langsung dihapus)
$etcd->lease()->revoke($lease['ID']);
```

**Skenario umum:** saat registrasi layanan, buat lease lalu tulis key; panggil `keepAlive()` berkala sebagai heartbeat. Setelah layanan berhenti, lease kedaluwarsa dan semuanya dibersihkan otomatis.

### Auth — Autentikasi dan Izin

```php
$auth = $etcd->auth();

// === manajemen pengguna ===
$auth->user()->add('alice', 'password123');          // buat pengguna
$auth->user()->get('alice');                         // lihat pengguna beserta perannya
$auth->user()->list();                               // daftar semua pengguna
$auth->user()->changePassword('alice', 'newpass');    // ubah kata sandi
$auth->user()->grantRole('alice', 'admin');          // berikan peran
$auth->user()->revokeRole('alice', 'admin');         // cabut peran
$auth->user()->delete('alice');                      // hapus pengguna

// === manajemen peran ===
$auth->role()->add('reader');                        // buat peran
$auth->role()->get('reader');                        // lihat izin peran
$auth->role()->list();                               // daftar semua peran

// berikan izin (permType: 0=READ, 1=WRITE, 2=READWRITE)
$auth->role()->grantPermission('reader', 0, '/data/', "\0");   // izin baca untuk prefix /data/
$auth->role()->grantPermission('writer', 2, '/data/', "\0");   // izin baca-tulis
$auth->role()->revokePermission('reader', '/data/', "\0");     // cabut izin
$auth->role()->delete('reader');

// === saklar autentikasi ===
$auth->enable();           // aktifkan autentikasi
$auth->disable();          // matikan autentikasi
$status = $auth->status(); // ['enabled' => true, 'authRevision' => 5]
```

**Bagaimana autentikasi terjadi:** etcd v3 tidak menerima HTTP Basic — ia menuntut kredensial ditukar dulu dengan token (`POST /v3/auth/authenticate`), lalu token dikirim apa adanya sebagai `Authorization: <token>` (awalan `Bearer` juga ditolak). Bila `auth.user` / `auth.password` diisi, klien melakukannya **secara otomatis** dan menyimpan token di cache, serta mengautentikasi ulang sekali saat menerima 401 — tanpa pemanggilan manual. Anda juga bisa menukarnya sendiri:

```php
$token = $etcd->auth()->authenticate('root', 'secret');  // token yang didapat akan dipakai ulang oleh permintaan berikutnya
```

Mengirim kredensial butuh `scheme => 'https'`: pada http polos konstruktor langsung menolak (agar kata sandi tidak pernah terkirim terbuka).

### Cluster — Manajemen Klaster

```php
// lihat anggota klaster
$members = $etcd->cluster()->memberList();

// tambah anggota
$etcd->cluster()->memberAdd(['http://node3:2380']);        // tambah anggota Voting
$etcd->cluster()->memberAdd(['http://node4:2380'], true);  // tambah anggota Learner

// ubah peer URL anggota
$etcd->cluster()->memberUpdate(123456, ['http://newnode:2380']);

// promosikan Learner menjadi Voter
$etcd->cluster()->memberPromote(789012);

// hapus anggota
$etcd->cluster()->memberRemove(345678);
```

### Election — pemilihan leader

```php
// pencalonan: ambil lease dulu lalu ikut serta; hanya kembali saat menang, yang kalah menunggu (dibatasi $timeout)
$lease  = $etcd->lease()->grant(30);
$leader = $etcd->election()->campaign('/my-election', 'node-a', $lease['ID'], 5.0);
// → ['name' => ..., 'key' => ..., 'rev' => ..., 'lease' => ...]

// leader saat ini (null bila belum ada yang menang)
$current = $etcd->election()->leader('/my-election');

// mundur
$etcd->election()->resign($leader);
```

Di gateway HTTP, `campaign()` adalah **respons tertahan** — tidak kembali sebelum Anda menang, jadi batasi penantian dengan `$timeout`. Untuk memantau pergantian leader dalam jangka panjang, gunakan `observe()`.

**Perhatian (jebakan senyap yang terukur):** `proclaim()` / `resign()` memerlukan **array deskriptor leader yang lengkap** (name, key, dan rev ketiganya ada). Kurang satu saja, etcd mengembalikan **HTTP 200 tetapi tidak melakukan apa pun** — tampak seperti berhasil dilepas, padahal leader masih ada. Karena itu kedua metode memvalidasi deskriptor sebelum mengirim dan melempar exception bila tidak lengkap; selalu pakai nilai balikan `campaign()` / `leader()`, jangan menyusun sendiri.

**Perilaku terukur lainnya:** pencalonan yang gagal di tengah permintaan **ditarik kembali** oleh server (jadi timeout `acquire()` bisa melaporkan kegagalan dengan aman, tidak menjadi 'pemegang tersembunyi'); bila tidak ada yang terpilih, `leader()` mengembalikan `null` (server menjawab 500 `election: no leader`, itu keadaan normal bukan error).

### Lock — lock terdistribusi

```php
$lock = $etcd->lock()->acquire('/my-lock', ttl: 30, timeout: 5.0);
// ... bagian kritis ...
$etcd->lock()->release($lock);
```

**Ini implementasi di sisi klien di atas Election, bukan lock di sisi server.** Gateway HTTP etcd 3.5 **tidak mengekspos** `/v3/lock/*` (terukur: 404), sehingga saling-eksklusi disediakan oleh «pencalonan Election + lease» — pendekatan yang sama dengan paket Go `concurrency` milik etcd sendiri. Bila pemegangnya di-`SIGKILL`, lock otomatis dilepas setelah lease kedaluwarsa, tanpa pembersihan manual.

### Maintenance — Operasional

```php
// lihat status node
$status = $etcd->maintenance()->status();
// ['version' => '3.5.0', 'dbSize' => 24576, 'leader' => 123, 'raftIndex' => 1000, ...]

// manajemen alarm
$alarms = $etcd->maintenance()->alarm();                    // lihat alarm
$etcd->maintenance()->alarm(action: 2, alarm: 1);           // bersihkan alarm NOSPACE

// defragmentasi (ambil kembali ruang penyimpanan)
$etcd->maintenance()->defragment();

// verifikasi hash KV
$hash = $etcd->maintenance()->hash();

// ambil snapshot (mengembalikan data biner, tinggal tulis ke file)
$snapshot = $etcd->maintenance()->snapshot();
file_put_contents('/backup/etcd-snapshot.db', $snapshot);

// untuk database besar, tulis streaming ke disk: jangan baca seluruh DB ke memori
$bytes = $etcd->maintenance()->snapshotTo('/backup/etcd-snapshot.db');
// menulis file sementara dulu dan baru mengganti nama setelah digest sha256
// di 32 byte terakhir lolos, jadi penulisan yang terputus tidak meninggalkan file yang terlihat seperti backup; mengembalikan jumlah byte yang ditulis.
```

## Mode Transport

| Mode | Status | Dependensi | Cocok untuk |
|------|------|------|---------|
| **HTTP** | Tersedia | ext-curl / stream / PSR-18 (salah satu) | tanpa dependensi ekstensi, langsung jalan |
| **gRPC** | RPC unary | ext-grpc + grpc/grpc + pesan protobuf hasil generate | throughput tinggi; streaming dan Election tetap perlu HTTP |
| **auto** | Default | — | saat ini sama dengan `http` (lihat di bawah) |

`auto` setara dengan `http`: gRPC saat ini hanya mencakup RPC unary — panggilan streaming seperti watch / snapshot, serta Election, tetap harus lewat HTTP, jadi beralih otomatis hanya akan diam-diam menghilangkan separuh fitur pengguna yang memasang ekstensinya. Hanya `'transport' => 'grpc'` yang diminta secara eksplisit yang memilihnya.

**Status transport gRPC saat ini (perhatikan):**
- **Sudah ada**: RPC unary (`send()`) — kelas pesan hasil generate `protoc` dari `rpc.proto` etcd v3.5; body request disusun sebagai pesan bertipe, dan nama serta tipe field dijamin oleh proto.
- **Belum ada**: panggilan streaming seperti watch dan snapshot, serta `/v3/election/*` (itu proto lain, `v3electionpb`) — jalur-jalur ini **ditolak berdasarkan nama** beserta alasannya, tidak gagal diam-diam.
- **Belum diverifikasi end-to-end**: lingkungan pengembangan proyek ini tidak punya `ext-grpc`, jadi pembukaan channel, metadata kredensial, `_simpleRequest`, timeout, dan pemetaan kode status semuanya **ditulis tangan mengikuti pola keluaran grpc_php_plugin dan belum pernah dijalankan lewat panggilan sungguhan**. Pembuatan pesan dan penyusunan request punya tes; perjalanan bolak-balik di jaringan tidak. Kalau mau memakai gRPC, verifikasi sendiri dulu sebelum ke produksi.

### Konfigurasi Manual Klien HTTP PSR-18

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

## Integrasi Framework

### Laravel

Langsung pakai setelah instalasi. `extra.laravel` pada composer.json menemukan ServiceProvider dan Facade secara otomatis.

```php
// cara Facade
use Etcd;
Etcd::kv()->put('/foo', 'bar');
$val = Etcd::kv()->get('/foo');

// cara dependency injection
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

Terbitkan berkas konfigurasi:

```bash
php artisan vendor:publish --tag=etcd-config
# → config/etcd.php
```

Konfigurasi `.env`:

```env
ETCD_ENDPOINTS=10.0.0.1:2379,10.0.0.2:2379
ETCD_USER=root
ETCD_PASSWORD=secret
```

### Hyperf

Langsung pakai setelah instalasi. Hyperf menemukan `ConfigProvider` secara otomatis.

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

// atau langsung make
$etcd = make(EtcdClient::class);
```

Terbitkan konfigurasi:

```bash
php bin/hyperf.php vendor:publish erikwang2013/etcd
# → config/autoload/etcd.php
```

### ThinkPHP

1. Setelah instalasi, daftarkan service di `app/service.php`:

```php
return [
    Erikwang2013\Etcd\Adapter\ThinkPHP\Service::class,
];
```

2. Buat berkas konfigurasi `config/etcd.php`.

Pemakaian:

```php
// cara Facade
use think\facade\Etcd;
Etcd::kv()->put('/key', 'value');

// cara container
app('etcd')->kv()->get('/key');
```

### Webman

Langsung pakai setelah instalasi, tanpa konfigurasi tambahan.

```php
use Erikwang2013\Etcd\EtcdClient;

$etcd = EtcdClient::instance();
$etcd->kv()->put('/key', 'value');
```

Untuk konfigurasi khusus, sunting `plugin/erikwang2013/etcd/config/etcd.php`.

## Penanganan Exception

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
    // node etcd tidak bisa dihubungi (gangguan jaringan, mati)
} catch (AuthException $e) {
    // autentikasi gagal (nama pengguna atau kata sandi salah)
} catch (KeyNotFoundException $e) {
    // key tidak ada saat getOrFail()
} catch (EtcdException $e) {
    // error lain dari server etcd
}
```

## Struktur Proyek

```
erikwang2013/etcd/
├── composer.json                    # definisi paket: autoload PSR-4 + auto-discovery Laravel / Hyperf
├── phpunit.xml.dist                 # konfigurasi PHPUnit (dua suite: unit / integration)
├── protos/                          # proto upstream etcd v3.5 + skrip generate + keluaran hasil generate (untuk gRPC)
├── .github/workflows/ci.yml         # gate sebelum merge: matriks unit test / batas sintaks / integrasi / dokumen i18n
├── config/etcd.php                  # konfigurasi default untuk dipublikasikan tiap framework (membaca variabel environment ETCD_*)
├── .github/workflows/release.yml    # rilis otomatis saat tag dibuat
├── scripts/i18n/                    #   perkakas doks: katalog, pembuat diagram, pemeriksaan terjemahan
├── docs/                            # dokumentasi dan diagram desain
│   ├── design-cn.md                 #   dokumen desain
│   ├── i18n/                        #   README dan diagram terlokalisasi dalam 13 bahasa
│   ├── pet.svg                      #   maskot proyek Etchy
│   ├── architecture.svg             #   diagram arsitektur
│   ├── features.svg                 #   diagram fitur
│   └── lifecycle.svg                #   diagram siklus hidup
├── src/
│   ├── EtcdClient.php               # facade teratas + singleton: aksesor delapan subsistem
│   ├── Mascot.php                   # pintu masuk maskot proyek Etchy (svg / dataUri / path)
│   ├── Install.php                  # hook plugin Webman (WEBMAN_PLUGIN)
│   ├── Transport/                   # lapisan transport
│   │   ├── TransportInterface.php   #   abstraksi transport: send / sendRaw / watch
│   │   ├── TransportSelector.php    #   pemilihan otomatis auto / http / grpc
│   │   ├── HttpTransport.php        #   transport HTTP JSON (sudah lengkap)
│   │   ├── GrpcTransport.php        #   transport gRPC (RPC unary; streaming ditolak dengan alasan)
│   │   └── GrpcStub.php             #   subclass Grpc\BaseStub (file terpisah, tidak dimuat bila ekstensinya tidak ada)
│   ├── Kv/KvClient.php              # baca-tulis KV / pemindaian prefix / transaksi / kompaksi
│   ├── Watch/WatchClient.php        # pemantauan perubahan Watch + langganan ulang saat terputus
│   ├── Lease/LeaseClient.php        # sewa Lease grant / keepAlive / revoke
│   ├── Auth/                        # Auth autentikasi dan otorisasi
│   │   ├── AuthClient.php           #   saklar dan status autentikasi
│   │   ├── UserClient.php           #   CRUD pengguna + pengikatan peran
│   │   └── RoleClient.php           #   CRUD peran + izin
│   ├── Cluster/ClusterClient.php    # Cluster manajemen anggota klaster
│   ├── Election/                    # Election — pemilihan (campaign / leader / observe / resign)
│   ├── Lock/LockClient.php          # Lock — lock terdistribusi (di atas Election; gateway tidak punya /v3/lock/*)
│   ├── Maintenance/                 # Maintenance operasional: status / alarm / defrag / snapshot
│   ├── Exception/                   # hierarki exception
│   ├── Support/                     # dekode bersama: KeyValue / Int64, handle pembatalan WatchHandle
│   └── Adapter/                     # adapter framework
│       ├── Laravel/                 #   ServiceProvider + Facade
│       ├── Hyperf/                  #   ConfigProvider
│       ├── ThinkPHP/                #   Service + Facade
│       └── Webman/                  #   Plugin
└── tests/
    ├── Unit/                        # unit test (per klien / transport / adapter / kelas pesan)
    ├── Integration/                 # integration test (terhadap etcd sungguhan)
    └── Support/                     # FakeTransport, stub PSR HTTP
```

## Pengujian

```bash
composer install
vendor/bin/phpunit --no-coverage tests/Unit     # uji unit (stub di memori)

# integrasi: suite membawa gateway palsu sendiri yang meniru yang asli (framing chunked + amplop {"result":…} + int64 sebagai string)
php -d zend.assertions=1 -d assert.exception=1 tests/transport_test.php
```

**Verifikasi terhadap klaster nyata (disarankan):** beri suite yang sama alamat etcd nyata — ia menjalankan kasus yang sama dan memastikan gateway palsu dan asli sepakat (framing, amplop, penyandian int64):

```bash
docker run -d --name etcd -p 2379:2379 quay.io/coreos/etcd:v3.5.17 \
  /usr/local/bin/etcd --name n1 --data-dir /d \
  --listen-client-urls http://0.0.0.0:2379 --advertise-client-urls http://127.0.0.1:2379 \
  --listen-peer-urls http://0.0.0.0:2380 --initial-advertise-peer-urls http://127.0.0.1:2380 \
  --initial-cluster n1=http://127.0.0.1:2380

ETCD_REAL=127.0.0.1:2379 php -d zend.assertions=1 -d assert.exception=1 tests/transport_test.php
```

> Kenapa layak: proyek ini pernah membawa sekumpulan cacat (watch mati total, sembilan metode tanpa argumen
> mengembalikan 400, keepAlive selalu melempar) sementara 40+ tes hijau — fixture dan gateway asli berbeda
> **pada framing maupun amplop**. Semuanya sudah diperbaiki, dan mode diferensial inilah yang mencegah kelas bug yang sama lolos lagi.

Dokumentasi dan diagram juga dijaga konsisten oleh skrip:

```bash
python3 scripts/i18n/check_translations.py      # 13 terjemahan: pengalih bahasa, tautan, katalog, struktur blok
python3 scripts/i18n/build_diagrams.py --check --all   # diagram dalam 13 bahasa: setiap string diukur
python3 scripts/i18n/sync_zh_readme.py --check  # salinan zh sinkron dengan README akar
```

CI (`.github/workflows/ci.yml`) menjalankan semuanya: uji unit di PHP 8.2/8.3, batas sintaks di PHP 8.0/8.1, suite integrasi (termasuk lintasan diferensial terhadap etcd nyata di kontainer layanan), dan tiga pemeriksaan dokumentasi itu.

## Arsitektur dan Diagram Desain

Tiga diagram disusun sebagai struktur → kemampuan → urutan waktu; klik untuk membuka masing-masing:

| Diagram | Pertanyaan yang dijawab | Berkas |
|----|-----------|------|
| Arsitektur | terbagi jadi lapisan apa saja, ke arah mana dependensi mengalir, bagaimana error bercabang | [`diagrams/id/architecture.svg`](diagrams/id/architecture.svg) |
| Desain fitur | metode apa saja yang disediakan tiap subsistem dan kontrak perilakunya | [`diagrams/id/features.svg`](diagrams/id/features.svg) |
| Siklus hidup | bagaimana satu request / satu watch / satu lease berjalan sampai tuntas | [`diagrams/id/lifecycle.svg`](diagrams/id/lifecycle.svg) |

### Arsitektur

![Diagram arsitektur](diagrams/id/architecture.svg)

### Desain Fitur

![Diagram desain fitur](diagrams/id/features.svg)

### Siklus Hidup

![Diagram siklus hidup](diagrams/id/lifecycle.svg)

### Memakai Etchy di Kode

Grafik ikut dipublikasikan bersama paket dan `Mascot` adalah satu-satunya pintu akses, jadi panel admin atau halaman status tidak perlu menyalinnya lagi:

```php
use Erikwang2013\Etcd\Mascot;

echo Mascot::svg();                                          // kode sumber SVG, langsung di-inline
echo '<img src="' . Mascot::dataUri() . '" alt="Etchy">';    // data URI, tidak bergantung pada direktori web
copy(Mascot::path(), __DIR__ . '/public/etcd.svg');          // atau salin sendiri ke direktori statis
```

Di Laravel, aset bisa langsung diterbitkan ke `public/`:

```bash
php artisan vendor:publish --tag=etcd-assets
# → public/vendor/etcd/pet.svg
```

## Dukung Proyek Ini / Support This Project

<p align="center">
  <table>
    <tr>
      <td align="center"><b>WeChat</b></td>
      <td align="center"><b>Alipay</b></td>
    </tr>
    <tr>
      <td align="center"><img src="../weixinpay.png" alt="WeChat Pay" width="130" height="130" /></td>
      <td align="center"><img src="../alipay.png" alt="Alipay" width="130" height="130" /></td>
    </tr>
  </table>
</p>

---

## License

MIT — Copyright (c) 2026 [erik](https://erik.xyz) <erik@erik.xyz>
