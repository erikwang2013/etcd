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
  <img src="../pet.svg" alt="プロジェクトのマスコット Etchy" width="200" />
</p>

<p align="center">
  <b>Etchy</b> · 頭に 3 ノードの Raft クラスタ、鼻先に <code>k/v</code> と <code>rev</code> をぶら下げた子象 ——<br/>
  あなたの設定とリースを見守ります：切断すれば自分で再接続し、期限が切れれば自分で掃除します。
</p>

PHP etcd v3 クライアント —— デュアルモード転送（HTTP は全機能 / gRPC は単項 RPC）で、etcd v3 の全 API（KV / Watch / Lease / Auth / Cluster / Maintenance / Election / Lock）をカバー。**Laravel / Hyperf / ThinkPHP / Webman** にすぐ対応します。

## 要件

- PHP >= 8.1
- etcd v3.x サーバ
- HTTP 経路はいずれか 1 つで十分です: ext-curl、PHP のストリームラッパー（allow_url_fopen）、自前の PSR-18 クライアント — 自動選択され、どれも使えない場合は明確なエラーを返します

## インストール

```bash
composer require erikwang2013/etcd
```

### 素の PHP（フレームワークなし）

フレームワークも PSR-18 実装も ext-curl も不要です。ext-curl が読み込まれていればそれを使い、なければ PHP 標準のストリームラッパーに自動で切り替わります。

```php
<?php
require __DIR__ . '/vendor/autoload.php';   // あなたの autoload

use Erikwang2013\Etcd\EtcdClient;

$etcd = new EtcdClient(['endpoints' => ['127.0.0.1:2379']]);

$etcd->kv()->put('/app/config', '{"debug":true}');
echo $etcd->kv()->getOrFail('/app/config')['value'], "\n";

// 実際に使われている経路: curl / stream / none
var_dump(Erikwang2013\Etcd\Transport\HttpTransport::detectDriver());
```

経路を固定するには `'driver' => 'stream'`（既定は `auto`）。3 つとも使えない場合、リクエストは捕捉可能な `ConnectionException` を投げ、何を有効にすべきかを示します。

## クイックスタート

```php
use Erikwang2013\Etcd\EtcdClient;

$etcd = new EtcdClient(['endpoints' => ['127.0.0.1:2379']]);

// 書き込み
$etcd->kv()->put('/app/config', '{"debug":true}');

// 読み取り
$result = $etcd->kv()->get('/app/config');
print_r($result['kvs'][0]);  // ['key' => '/app/config', 'value' => '{"debug":true}', ...]

// 見つからない場合は例外を送出
$kv = $etcd->kv()->getOrFail('/app/config');

// プレフィックススキャン
$all = $etcd->kv()->getByPrefix('/app/');
echo "合計 {$all['count']} 件\n";

// 削除
$etcd->kv()->delete('/app/config');
$etcd->kv()->deleteByPrefix('/cache/');

// リース付きで書き込み（60 秒後に自動削除）
$lease = $etcd->lease()->grant(60);
$etcd->kv()->put('/session/123', 'active', ['lease' => $lease['ID']]);

// 更新
$etcd->lease()->keepAlive($lease['ID']);
```

## 設定

```php
$etcd = new EtcdClient([
    'endpoints' => ['192.168.1.10:2379', '192.168.1.11:2379'],  // 複数ノード
    'transport' => 'auto',  // auto（既定）| http | grpc
    'driver'    => 'auto',  // auto（既定）| curl | stream
    'scheme'    => 'http',  // http（既定）| https
    'timeout'   => 5.0,     // 秒
    'retry'     => 3,       // 接続失敗時のリトライ回数
    'auth'      => [        // 任意；資格情報を token に交換するには https が必要
        'user'     => 'root',
        'password' => 'secret',
    ],
]);
```

### 環境変数

設定を渡さない場合は環境変数を自動的に読み取ります：

| 変数 | 既定値 | 説明 |
|------|--------|------|
| `ETCD_ENDPOINTS` | `127.0.0.1:2379` | カンマ区切りのマルチノードアドレス |
| `ETCD_TRANSPORT` | `auto` | auto / http / grpc |
| `ETCD_TIMEOUT` | `5.0` | リクエストタイムアウト（秒） |
| `ETCD_SCHEME` | `http` | http / https |
| `ETCD_RETRY` | `2` | 接続リトライ回数 |
| `ETCD_DRIVER` | `auto` | HTTP 経路: auto / curl / stream |
| `ETCD_USER` | — | etcd ユーザ名 |
| `ETCD_PASSWORD` | — | etcd パスワード |

## API リファレンス

### KV — キー値操作

```php
// 書き込み
$etcd->kv()->put('key', 'value', [
    'lease'       => 12345,    // リース ID をバインド
    'prevKv'      => true,     // 書き込み前の旧値を返す
    'ignoreValue' => false,
    'ignoreLease' => false,
]);

// 単一 key の読み取り
$etcd->kv()->get('/exact/key');

// 見つからなければ例外を送出
$kv = $etcd->kv()->getOrFail('/exact/key');

// プレフィックススキャン
$etcd->kv()->getByPrefix('/prefix/');

// 範囲クエリ（全パラメータ）
$etcd->kv()->get('/start', [
    'rangeEnd'    => '/startz',      // 範囲終了 key
    'limit'       => 100,             // 最大取得件数
    'revision'    => 42,              // スナップショットのリビジョン
    'sortOrder'   => 'ascend',        // none | ascend | descend
    'sortTarget'  => 'key',           // key | version | create | mod | value
    'serializable'=> true,            // Raft 合意をスキップ（高速、古い可能性あり）
    'keysOnly'    => true,            // key のみ返し value は返さない
    'countOnly'   => false,           // 件数のみ返す
    'minModRevision'    => 100,       // このリビジョン以降に変更された key のみ
    'maxModRevision'    => 200,
    'minCreateRevision' => 100,       // 作成リビジョンで絞り込む
    'maxCreateRevision' => 200,
]);

// 削除
$etcd->kv()->delete('/key');
$etcd->kv()->deleteByPrefix('/prefix/');
$etcd->kv()->delete('/key', ['prevKv' => true]);  // 削除された値も返す

// トランザクション（アトミック CAS）
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

// ネストしたトランザクション：ブランチ内にさらにトランザクションを置ける
$etcd->kv()->txn(
    compare: [['result' => 0, 'target' => 3, 'key' => '/lock', 'value' => 'free']],
    success: [[
        'request_put' => ['key' => '/lock', 'value' => 'mine'],
        'request_txn' => [                       // 内側のトランザクション
            'compare' => [['result' => 0, 'target' => 1, 'key' => '/lock', 'create_revision' => 0]],
            'success' => [['request_put' => ['key' => '/log', 'value' => 'acquired']]],
            'failure' => [],
        ],
    ]],
    failure: []
);

// 履歴バージョンを圧縮（領域を解放）
$etcd->kv()->compact(1000);
```

**比較ターゲット（target）定数：** `0`=VERSION, `1`=CREATE, `2`=MOD, `3`=VALUE, `4`=LEASE  
**比較結果（result）定数：** `0`=EQUAL, `1`=GREATER, `2`=LESS, `3`=NOT_EQUAL

### Watch — 変更監視

```php
// 単一 key を監視（ブロッキングモード。コルーチン/別プロセスでの実行を推奨）
$etcd->watch()->watch('/config/key', function (array $events) {
    foreach ($events as $event) {
        // $event: ['type' => 'PUT'|'DELETE', 'kv' => [...], 'prev_kv' => [...]|null]
        echo "{$event['type']} {$event['kv']['key']} = {$event['kv']['value']}\n";
    }
});

// プレフィックス配下の全 key の変更を監視
$etcd->watch()->watchPrefix('/config/', $callback, [
    'startRevision' => 100,       // 指定リビジョンから開始
    'prevKv'        => true,      // DELETE イベントで元の値を返す
    'progressNotify'=> true,      // 定期的に空イベントを送信（ハートビート）
]);
```

**watch の停止：** `watch()` はブロックし続けますが、`WatchHandle` を渡せば外から止められます（常駐プロセスが SIGTERM で終了するときの定番の要件です）：

```php
use Erikwang2013\Etcd\Support\WatchHandle;

$handle = new WatchHandle();
pcntl_async_signals(true);
pcntl_signal(SIGTERM, fn() => $handle->cancel());

$etcd->watch()->watchPrefix('/config/', $onEvent, ['handle' => $handle]);
```

`cancel()` の後、`watch()` は**正常に戻ります**（例外は投げず、catch も不要です）。アイドル状態の key でも 1 秒ほどで抜けます——curl ドライバは cURL の周期コールバックで、stream ドライバは 200ms の読み取りタイムアウトのアイドル周期で検知します。

**切断時の再接続：** Watch 接続が切れると `lastRevision + 1` から再購読します（`start_revision` は**閉区間**のため、同じ値で再開すると最後のイベントが再生されます）。フェイルオーバーでイベントを失わず、二重に届くこともありません。

**リトライ方針：** リトライするのは、要求がサーバーに届いていないことが確実な失敗（接続拒否 / DNS 失敗）だけです。読み取り専用の RPC（range、status、memberlist など）はさらに 5xx とタイムアウトも許容します。書き込みは 5xx や読み取りタイムアウトでは**リトライしません** — すでに反映済みかもしれず、再生すると CAS のような要求が二重に適用され、「自信満々の誤答」（リトライが自分の最初の書き込みを見て、実際には勝っているのに CAS 失敗と報告する）すら返り得ます。

### Lease — リース

```php
// リースを作成
$lease = $etcd->lease()->grant(300);             // 300 秒 TTL
$lease = $etcd->lease()->grant(300, 99999);      // リース ID を指定

// 更新（1 回）
$result = $etcd->lease()->keepAlive($lease['ID']);
echo "TTL 残り: {$result['TTL']} 秒";

// リースの状態を確認
$info = $etcd->lease()->timeToLive($lease['ID']);
$info = $etcd->lease()->timeToLive($lease['ID'], true);  // バインドされた key 一覧を含む

// 有効なリースを一覧
$leases = $etcd->lease()->list();

// リースを失効（バインドされた全 key を即削除）
$etcd->lease()->revoke($lease['ID']);
```

**典型的な用途：** サービス登録時にリースを作成して key を書き込み、定期的に `keepAlive()` でハートビート更新。サービス停止後はリースの期限切れで自動的にクリーンアップされます。

### Auth — 認証と権限

```php
$auth = $etcd->auth();

// === ユーザ管理 ===
$auth->user()->add('alice', 'password123');          // ユーザを作成
$auth->user()->get('alice');                         // ユーザとロールを確認
$auth->user()->list();                               // 全ユーザを一覧
$auth->user()->changePassword('alice', 'newpass');    // パスワードを変更
$auth->user()->grantRole('alice', 'admin');          // ロールを付与
$auth->user()->revokeRole('alice', 'admin');         // ロールを剥奪
$auth->user()->delete('alice');                      // ユーザを削除

// === ロール管理 ===
$auth->role()->add('reader');                        // ロールを作成
$auth->role()->get('reader');                        // ロールの権限を確認
$auth->role()->list();                               // 全ロールを一覧

// 権限を付与（permType: 0=READ, 1=WRITE, 2=READWRITE）
$auth->role()->grantPermission('reader', 0, '/data/', "\0");   // /data/ プレフィックスの読み取り権限
$auth->role()->grantPermission('writer', 2, '/data/', "\0");   // 読み書き権限
$auth->role()->revokePermission('reader', '/data/', "\0");     // 権限を剥奪
$auth->role()->delete('reader');

// === 認証の有効/無効 ===
$auth->enable();           // 認証を有効化
$auth->disable();          // 認証を無効化
$status = $auth->status(); // ['enabled' => true, 'authRevision' => 5]
```

**認証はどう行われるか：** etcd v3 は HTTP Basic を受け付けません — まず資格情報を token に交換する必要があり（`POST /v3/auth/authenticate`）、そのうえで token をそのまま `Authorization: <token>` として送ります（`Bearer` を付けても拒否されます）。`auth.user` / `auth.password` を設定すると、クライアントが**自動的に**これを行って token をキャッシュし、401 のときは一度だけ再認証します。手動で呼ぶ必要はありません。自分で交換することもできます：

```php
$token = $etcd->auth()->authenticate('root', 'secret');  // 取得した token は以降のリクエストで再利用される
```

資格情報を送るには `scheme => 'https'` が必要です。平文 http ではコンストラクタがその場で拒否します（パスワードが平文で流れないように）。

### Cluster — クラスタ管理

```php
// クラスタメンバを確認
$members = $etcd->cluster()->memberList();

// メンバを追加
$etcd->cluster()->memberAdd(['http://node3:2380']);        // Voting メンバを追加
$etcd->cluster()->memberAdd(['http://node4:2380'], true);  // Learner メンバを追加

// メンバの peer URL を変更
$etcd->cluster()->memberUpdate(123456, ['http://newnode:2380']);

// Learner を Voter へ昇格
$etcd->cluster()->memberPromote(789012);

// メンバを削除
$etcd->cluster()->memberRemove(345678);
```

### Election — leader 選出

```php
// 立候補：先に lease を取得してから参加。当選するまで戻らず、敗者は待ちます（$timeout で上限）
$lease  = $etcd->lease()->grant(30);
$leader = $etcd->election()->campaign('/my-election', 'node-a', $lease['ID'], 5.0);
// → ['name' => ..., 'key' => ..., 'rev' => ..., 'lease' => ...]

// 現在の leader（誰も当選していなければ null）
$current = $etcd->election()->leader('/my-election');

// 譲る
$etcd->election()->resign($leader);
```

HTTP ゲートウェイでは `campaign()` は**バッファ応答**です——当選するまで戻らないため、待ち時間は `$timeout` で区切ります。leader の変化を長期的に追うには `observe()` を使ってください。

**注意（実測された静かな罠）：** `proclaim()` / `resign()` には**完全な leader 記述配列**（name、key、rev がそろっていること）が必要です。どれか欠けると etcd は **HTTP 200 を返しながら何も行いません**——解放に成功したように見えて、leader はそのままです。そのため両メソッドは送信前に記述子を検証し、不完全なら例外を投げます。必ず `campaign()` / `leader()` の戻り値を使い、自分で組み立てないでください。

**その他の実測挙動：** 途中で失敗した立候補はサーバー側で**取り消されます**（したがって `acquire()` のタイムアウトは安全に失敗を報告でき、「隠れた保持者」にはなりません）。誰も当選していないとき `leader()` は `null` を返します（サーバーは 500 `election: no leader` を返しますが、これはエラーではなく正常な状態です）。

### Lock — 分散ロック

```php
$lock = $etcd->lock()->acquire('/my-lock', ttl: 30, timeout: 5.0);
// ... クリティカルセクション ...
$etcd->lock()->release($lock);
```

**これは Election の上に載せたクライアント側の実装で、サーバー側のロックではありません。** etcd 3.5 の HTTP ゲートウェイは `/v3/lock/*` を**公開していません**（実測 404）。そのため相互排他は「Election の立候補 + リース」で提供します——etcd 自身の Go 版 `concurrency` パッケージと同じやり方です。保持者が `SIGKILL` された場合も、リースの期限切れでロックは自動的に解放され、手作業の後始末は要りません。

### Maintenance — 運用

```php
// ノード状態を確認
$status = $etcd->maintenance()->status();
// ['version' => '3.5.0', 'dbSize' => 24576, 'leader' => 123, 'raftIndex' => 1000, ...]

// アラーム管理
$alarms = $etcd->maintenance()->alarm();                    // アラームを確認
$etcd->maintenance()->alarm(action: 2, alarm: 1);           // NOSPACE アラームを解除

// デフラグ（領域を回収）
$etcd->maintenance()->defragment();

// KV ハッシュ検証
$hash = $etcd->maintenance()->hash();

// スナップショットを取得（バイナリを返すのでファイルに書き込むだけ）
$snapshot = $etcd->maintenance()->snapshot();
file_put_contents('/backup/etcd-snapshot.db', $snapshot);

// 大きな DB はストリーミングでディスクへ：データベース全体をメモリに載せない
$bytes = $etcd->maintenance()->snapshotTo('/backup/etcd-snapshot.db');
// まず一時ファイルに書き、末尾 32 バイトの sha256 ダイジェストが通ってから改名します。
// そのため中断しても「バックアップに見える」中途半端なファイルは残りません。書き込んだバイト数を返します。
```

## トランスポートモード

| モード | 状態 | 依存 | 用途 |
|------|------|------|---------|
| **HTTP** | 利用可 | ext-curl / stream / PSR-18 のいずれか | 拡張機能への依存ゼロ、すぐ使える |
| **gRPC** | 単項 RPC | ext-grpc + grpc/grpc + 生成した protobuf メッセージ | 高スループット；ストリーミングと Election は HTTP 経由 |
| **auto** | 既定 | — | 現在は `http` と同じ（下記参照） |

`auto` は `http` と等価です。gRPC が今カバーするのは単項 RPC だけで、watch / snapshot のようなストリーミング呼び出しや Election は依然として HTTP を通す必要があるため、自動で切り替えると拡張を入れている利用者の機能が黙って半分になってしまいます。明示的に `'transport' => 'grpc'` を渡したときだけ選ばれます。

**gRPC 転送の現状（ご注意）：**
- **実装済み**：単項 RPC（`send()`）——etcd v3.5 の `rpc.proto` から `protoc` で生成したメッセージクラスを使い、リクエスト本文は型付きメッセージとして組み立てます。フィールド名と型は proto が保証します。
- **未実装**：watch、snapshot などのストリーミング呼び出し、および `/v3/election/*`（こちらは別の proto、`v3electionpb`）——これらのパスは**名前で拒否**し理由を返します。黙って失敗することはありません。
- **エンドツーエンド未検証**：このプロジェクトの開発環境には `ext-grpc` がないため、チャネル開設、資格情報 metadata、`_simpleRequest`、タイムアウト、ステータスコードのマッピングはいずれも **grpc_php_plugin の出力パターンに倣って手書きしたもので、実際の呼び出しでは一度も動かしていません**。メッセージ生成とリクエスト組み立てにはテストがありますが、ネットワーク往復にはありません。gRPC を使う場合は本番前にご自身で検証してください。

### PSR-18 HTTP クライアントを手動設定

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

## フレームワーク統合

### Laravel

インストールするだけで使えます。composer.json の `extra.laravel` が ServiceProvider と Facade を自動検出します。

```php
// Facade 経由
use Etcd;
Etcd::kv()->put('/foo', 'bar');
$val = Etcd::kv()->get('/foo');

// 依存注入経由
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

設定ファイルを publish する：

```bash
php artisan vendor:publish --tag=etcd-config
# → config/etcd.php
```

`.env` の設定：

```env
ETCD_ENDPOINTS=10.0.0.1:2379,10.0.0.2:2379
ETCD_USER=root
ETCD_PASSWORD=secret
```

### Hyperf

インストールするだけで使えます。Hyperf が `ConfigProvider` を自動検出します。

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

// あるいは直接 make
$etcd = make(EtcdClient::class);
```

設定を publish する：

```bash
php bin/hyperf.php vendor:publish erikwang2013/etcd
# → config/autoload/etcd.php
```

### ThinkPHP

1. インストール後、`app/service.php` に登録します：

```php
return [
    Erikwang2013\Etcd\Adapter\ThinkPHP\Service::class,
];
```

2. `config/etcd.php` 設定ファイルを作成します。

使い方：

```php
// Facade 経由
use think\facade\Etcd;
Etcd::kv()->put('/key', 'value');

// コンテナ経由
app('etcd')->kv()->get('/key');
```

### Webman

インストールするだけで使え、追加設定は不要です。

```php
use Erikwang2013\Etcd\EtcdClient;

$etcd = EtcdClient::instance();
$etcd->kv()->put('/key', 'value');
```

設定をカスタマイズする場合は `plugin/erikwang2013/etcd/config/etcd.php` を編集します。

## 例外処理

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
    // etcd ノードに接続できない（ネットワーク障害、ダウン）
} catch (AuthException $e) {
    // 認証失敗（ユーザ名・パスワード誤り）
} catch (KeyNotFoundException $e) {
    // getOrFail() で key が存在しない
} catch (EtcdException $e) {
    // その他の etcd サーバエラー
}
```

## プロジェクト構成

```
erikwang2013/etcd/
├── composer.json                    # パッケージ定義：PSR-4 自動読込 + Laravel / Hyperf 自動検出
├── phpunit.xml.dist                 # PHPUnit 設定（unit / integration の 2 スイート）
├── protos/                          # etcd v3.5 上流 proto + 生成スクリプト + 生成物（gRPC 用）
├── .github/workflows/ci.yml         # マージ前ゲート：ユニット行列 / 構文ベースライン / 統合 / i18n ドキュメント
├── config/etcd.php                  # 既定設定。各フレームワークへ publish（ETCD_* 環境変数を読む）
├── .github/workflows/release.yml    # タグ付けで自動リリース
├── scripts/i18n/                    #   ドキュメント用ツール: カタログ、図の生成、翻訳チェック
├── docs/                            # ドキュメントと設計図
│   ├── design-cn.md                 #   設計ドキュメント
│   ├── i18n/                        #   13 言語の README とローカライズ済み図
│   ├── pet.svg                      #   マスコット Etchy
│   ├── architecture.svg             #   アーキテクチャ設計図
│   ├── features.svg                 #   機能設計図
│   └── lifecycle.svg                #   ライフサイクル図
├── src/
│   ├── EtcdClient.php               # 最上位ファサード + シングルトン：8 つのサブシステムへのアクセサ
│   ├── Mascot.php                   # マスコット Etchy の取得入口（svg / dataUri / path）
│   ├── Install.php                  # Webman プラグインフック（WEBMAN_PLUGIN）
│   ├── Transport/                   # トランスポート層
│   │   ├── TransportInterface.php   #   転送の抽象：send / sendRaw / watch
│   │   ├── TransportSelector.php    #   auto / http / grpc を自動選択
│   │   ├── HttpTransport.php        #   HTTP JSON 転送（利用可）
│   │   ├── GrpcTransport.php        #   gRPC 転送（単項 RPC；ストリーミングは理由付きで拒否）
│   │   └── GrpcStub.php             #   Grpc\BaseStub のサブクラス（別ファイル、拡張がないときは読み込まない）
│   ├── Kv/KvClient.php              # KV 読み書き / プレフィックス走査 / トランザクション / 圧縮
│   ├── Watch/WatchClient.php        # Watch 変更監視 + 切断時の再開
│   ├── Lease/LeaseClient.php        # Lease リース grant / keepAlive / revoke
│   ├── Auth/                        # Auth 認証・認可
│   │   ├── AuthClient.php           #   認証の有効/無効と状態
│   │   ├── UserClient.php           #   ユーザ CRUD + ロール紐付け
│   │   └── RoleClient.php           #   ロール CRUD + 権限
│   ├── Cluster/ClusterClient.php    # Cluster クラスタメンバ管理
│   ├── Election/                    # Election 選出（campaign / leader / observe / resign）
│   ├── Lock/LockClient.php          # Lock 分散ロック（Election の上に構築、ゲートウェイに /v3/lock/* なし）
│   ├── Maintenance/                 # Maintenance 運用：status / alarm / defrag / snapshot
│   ├── Exception/                   # 例外階層
│   ├── Support/                     # KeyValue / Int64 の共通デコード、WatchHandle 取消ハンドル
│   └── Adapter/                     # フレームワークアダプタ
│       ├── Laravel/                 #   ServiceProvider + Facade
│       ├── Hyperf/                  #   ConfigProvider
│       ├── ThinkPHP/                #   Service + Facade
│       └── Webman/                  #   Plugin
└── tests/
    ├── Unit/                        # 単体テスト（クライアント / 転送 / アダプタ / メッセージクラス別）
    ├── Integration/                 # 結合テスト（実 etcd に接続）
    └── Support/                     # FakeTransport、PSR HTTP スタブ
```

## テスト

```bash
composer install
vendor/bin/phpunit --no-coverage tests/Unit     # 単体テスト（インメモリのスタブ）

# 統合：スイートは実物を模した偽ゲートウェイを同梱しています（chunked フレーミング + {"result":…} エンベロープ + int64 は文字列）
php -d zend.assertions=1 -d assert.exception=1 tests/transport_test.php
```

**実クラスタでの検証（推奨）：** 同じスイートに実 etcd のアドレスを渡すと、同じケースを実行し、偽ゲートウェイと実物が一致するか（フレーミング、エンベロープ、int64 エンコード）を検証します：

```bash
docker run -d --name etcd -p 2379:2379 quay.io/coreos/etcd:v3.5.17 \
  /usr/local/bin/etcd --name n1 --data-dir /d \
  --listen-client-urls http://0.0.0.0:2379 --advertise-client-urls http://127.0.0.1:2379 \
  --listen-peer-urls http://0.0.0.0:2380 --initial-advertise-peer-urls http://127.0.0.1:2380 \
  --initial-cluster n1=http://127.0.0.1:2380

ETCD_REAL=127.0.0.1:2379 php -d zend.assertions=1 -d assert.exception=1 tests/transport_test.php
```

> なぜやる価値があるか：このプロジェクトはかつて 40 以上のテストが緑のまま、一群の不具合（watch が完全に動かない、
> 引数なしの 9 メソッドが 400、keepAlive が必ず例外）を抱えていました。原因はスタブと実ゲートウェイが
> **フレーミングとエンベロープの両方で**違っていたことです。それらは修正済みで、この差分モードが再流入を防ぎます。

ドキュメントと図の整合もスクリプトが守ります：

```bash
python3 scripts/i18n/check_translations.py      # 13 言語の翻訳：切り替え、リンク、カタログ、フェンス構造
python3 scripts/i18n/build_diagrams.py --check --all   # 13 言語の図：すべての文字列を計測
python3 scripts/i18n/sync_zh_readme.py --check  # zh コピーはルート README と同期
```

CI（`.github/workflows/ci.yml`）が上記すべてを実行します：PHP 8.2/8.3 の単体テスト、PHP 8.0/8.1 の構文フロア、統合（サービスコンテナ内の実 etcd に対する差分を含む）、そして 3 つのドキュメント検証。

## アーキテクチャと設計図

3 つの図は「構造 → 能力 → 時系列」の順に構成されており、個別にクリックして閲覧できます：

| 図 | 答える問い | ファイル |
|----|-----------|------|
| アーキテクチャ設計 | どの層に分かれ、依存がどちらへ向き、エラーをどう振り分けるか | [`docs/architecture.svg`](diagrams/ja/architecture.svg) |
| 機能設計 | 各サブシステムがどのメソッドを提供し、どんな動作契約を持つか | [`docs/features.svg`](diagrams/ja/features.svg) |
| ライフサイクル | 1 リクエスト / 1 ウォッチ / 1 リース がそれぞれどう進むか | [`docs/lifecycle.svg`](diagrams/ja/lifecycle.svg) |

### アーキテクチャ設計

![アーキテクチャ設計図](diagrams/ja/architecture.svg)

### 機能設計

![機能設計図](diagrams/ja/features.svg)

### ライフサイクル

![ライフサイクル図](diagrams/ja/lifecycle.svg)

### コードから Etchy を使う

図はパッケージに同梱されており、`Mascot` が唯一の取得入口です。管理画面やステータスページを作るときに、わざわざコピーを置く必要はありません：

```php
use Erikwang2013\Etcd\Mascot;

echo Mascot::svg();                                          // SVG ソースをそのままインライン展開
echo '<img src="' . Mascot::dataUri() . '" alt="Etchy">';    // data URI。web ディレクトリ不要
copy(Mascot::path(), __DIR__ . '/public/etcd.svg');          // あるいは静的ディレクトリへ自分で配置
```

Laravel では `public/` へそのまま publish できます：

```bash
php artisan vendor:publish --tag=etcd-assets
# → public/vendor/etcd/pet.svg
```

## オープンソースの継続は容易ではありません。ぜひご支援を / Support This Project

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
