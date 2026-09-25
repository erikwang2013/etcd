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
  <img src="../pet.svg" alt="Projekt-Maskottchen Etchy" width="200" />
</p>

<p align="center">
  <b>Etchy</b> · ein kleiner Elefant mit einem Drei-Knoten-Raft-Cluster auf dem Kopf und <code>k/v</code> sowie <code>rev</code> am Rüssel —<br/>
  er bewacht Konfiguration und Leases: bei Abbruch verbindet er sich neu, bei Ablauf räumt er auf.
</p>

PHP etcd v3 Client — gRPC + HTTP als dualer Transport, vollständige Abdeckung der etcd v3 API (KV / Watch / Lease / Auth / Cluster / Maintenance), out of the box passend für **Laravel / Hyperf / ThinkPHP / Webman**.

## Voraussetzungen

- PHP >= 8.1
- etcd v3.x-Server
- PSR-18 + PSR-17 HTTP-Client (für den HTTP-Transport erforderlich; die meisten Frameworks bringen einen mit)

## Installation

```bash
composer require erikwang2013/etcd
```

## Schnellstart

```php
use Erikwang2013\Etcd\EtcdClient;

$etcd = new EtcdClient(['endpoints' => ['127.0.0.1:2379']]);

// schreiben
$etcd->kv()->put('/app/config', '{"debug":true}');

// lesen
$result = $etcd->kv()->get('/app/config');
print_r($result['kvs'][0]);  // ['key' => '/app/config', 'value' => '{"debug":true}', ...]

// wirft eine Ausnahme, wenn nicht gefunden
$kv = $etcd->kv()->getOrFail('/app/config');

// Präfix-Scan
$all = $etcd->kv()->getByPrefix('/app/');
echo "{$all['count']} Keys\n";

// löschen
$etcd->kv()->delete('/app/config');
$etcd->kv()->deleteByPrefix('/cache/');

// Schreiben mit Lease (automatisch nach 60 s gelöscht)
$lease = $etcd->lease()->grant(60);
$etcd->kv()->put('/session/123', 'active', ['lease' => $lease['ID']]);

// verlängern
$etcd->lease()->keepAlive($lease['ID']);
```

## Konfiguration

```php
$etcd = new EtcdClient([
    'endpoints' => ['192.168.1.10:2379', '192.168.1.11:2379'],  // mehrere Knoten
    'transport' => 'auto',  // auto (Standard) | http | grpc
    'scheme'    => 'http',  // http (Standard) | https
    'timeout'   => 5.0,     // Sekunden (im PSR-18-Client zu konfigurieren)
    'retry'     => 3,       // Anzahl Wiederholungen bei Verbindungsfehlern
    'auth'      => [        // optional, Basic Auth
        'user'     => 'root',
        'password' => 'secret',
    ],
]);
```

### Umgebungsvariablen

Ohne übergebene Konfiguration werden die Umgebungsvariablen automatisch gelesen:

| Variable | Standard | Beschreibung |
|------|--------|------|
| `ETCD_ENDPOINTS` | `127.0.0.1:2379` | kommagetrennte Adressen mehrerer Knoten |
| `ETCD_TRANSPORT` | `auto` | auto / http / grpc |
| `ETCD_TIMEOUT` | `5.0` | Request-Timeout (Sekunden) |
| `ETCD_SCHEME` | `http` | http / https |
| `ETCD_RETRY` | `2` | Anzahl der Verbindungswiederholungen |
| `ETCD_USER` | — | etcd-Benutzername |
| `ETCD_PASSWORD` | — | etcd-Passwort |

## API-Referenz

### KV — Schlüssel-Wert-Operationen

```php
// schreiben
$etcd->kv()->put('key', 'value', [
    'lease'       => 12345,    // Lease-ID binden
    'prevKv'      => true,     // vorherigen Wert zurückgeben
    'ignoreValue' => false,
    'ignoreLease' => false,
]);

// einzelnen Key lesen
$etcd->kv()->get('/exact/key');

// wirft eine Ausnahme, wenn nicht gefunden
$kv = $etcd->kv()->getOrFail('/exact/key');

// Präfix-Scan
$etcd->kv()->getByPrefix('/prefix/');

// Bereichsabfrage (alle Parameter)
$etcd->kv()->get('/start', [
    'rangeEnd'    => '/startz',      // Ende des Bereichs
    'limit'       => 100,             // maximale Trefferzahl
    'revision'    => 42,              // Snapshot-Revision
    'sortOrder'   => 'ascend',        // none | ascend | descend
    'sortTarget'  => 'key',           // key | version | create | mod | value
    'serializable'=> true,            // Raft-Konsens überspringen (schneller, evtl. veraltet)
    'keysOnly'    => true,            // nur Keys, ohne Values
    'countOnly'   => false,           // nur die Anzahl
]);

// löschen
$etcd->kv()->delete('/key');
$etcd->kv()->deleteByPrefix('/prefix/');
$etcd->kv()->delete('/key', ['prevKv' => true]);  // gibt auch den gelöschten Wert zurück

// Transaktion (atomares CAS)
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

// Historie komprimieren (Speicher freigeben)
$etcd->kv()->compact(1000);
```

**Vergleichsziele (target):** `0`=VERSION, `1`=CREATE, `2`=MOD, `3`=VALUE, `4`=LEASE  
**Vergleichsergebnisse (result):** `0`=EQUAL, `1`=GREATER, `2`=LESS, `3`=NOT_EQUAL

### Watch — Änderungsbenachrichtigungen

```php
// einzelnen Key überwachen (blockierend; in einer Coroutine oder einem eigenen Prozess ausführen)
$etcd->watch()->watch('/config/key', function (array $events) {
    foreach ($events as $event) {
        // $event: ['type' => 'PUT'|'DELETE', 'kv' => [...], 'prev_kv' => [...]|null]
        echo "{$event['type']} {$event['kv']['key']} = {$event['kv']['value']}\n";
    }
});

// alle Key-Änderungen unter einem Präfix überwachen
$etcd->watch()->watchPrefix('/config/', $callback, [
    'startRevision' => 100,       // ab einer bestimmten Revision
    'prevKv'        => true,      // DELETE-Events liefern den alten Wert
    'progressNotify'=> true,      // regelmäßige leere Events (Herzschlag)
]);
```

**Reconnect:** Bricht die Watch-Verbindung ab, setzt der Client ab der zuletzt erhaltenen Revision fort — kein Event geht verloren.

### Lease — TTL

```php
// Lease anlegen
$lease = $etcd->lease()->grant(300);             // 300 Sekunden TTL
$lease = $etcd->lease()->grant(300, 99999);      // mit expliziter Lease-ID

// einmal verlängern
$result = $etcd->lease()->keepAlive($lease['ID']);
echo "Restliche TTL: {$result['TTL']} s";

// Lease-Status prüfen
$info = $etcd->lease()->timeToLive($lease['ID']);
$info = $etcd->lease()->timeToLive($lease['ID'], true);  // inklusive gebundener Keys

// alle aktiven Leases auflisten
$leases = $etcd->lease()->list();

// widerrufen (alle gebundenen Keys sofort löschen)
$etcd->lease()->revoke($lease['ID']);
```

**Typischer Einsatz:** Bei der Dienst-Registrierung eine Lease anlegen und den Key schreiben, dann ruft ein Timer `keepAlive()` als Herzschlag auf; stoppt der Dienst, läuft die Lease ab und alles wird automatisch aufgeräumt.

### Auth — Authentifizierung und Berechtigungen

```php
$auth = $etcd->auth();

// === Benutzerverwaltung ===
$auth->user()->add('alice', 'password123');          // Benutzer anlegen
$auth->user()->get('alice');                         // Benutzer und seine Rollen ansehen
$auth->user()->list();                               // alle Benutzer auflisten
$auth->user()->changePassword('alice', 'newpass');    // Passwort ändern
$auth->user()->grantRole('alice', 'admin');          // Rolle zuweisen
$auth->user()->revokeRole('alice', 'admin');         // Rolle entziehen
$auth->user()->delete('alice');                      // Benutzer löschen

// === Rollenverwaltung ===
$auth->role()->add('reader');                        // Rolle anlegen
$auth->role()->get('reader');                        // Berechtigungen der Rolle ansehen
$auth->role()->list();                               // alle Rollen auflisten

// Berechtigung vergeben (permType: 0=READ, 1=WRITE, 2=READWRITE)
$auth->role()->grantPermission('reader', 0, '/data/', "\0");   // Leserecht auf das Präfix /data/
$auth->role()->grantPermission('writer', 2, '/data/', "\0");   // Lese- und Schreibrecht
$auth->role()->revokePermission('reader', '/data/', "\0");     // Berechtigung entziehen
$auth->role()->delete('reader');

// === Auth-Schalter ===
$auth->enable();           // Auth einschalten
$auth->disable();          // Auth ausschalten
$status = $auth->status(); // ['enabled' => true, 'authRevision' => 5]
```

**Hinweis:** Nach dem Aktivieren der Authentifizierung muss der Client `auth.user` und `auth.password` konfigurieren, um weiterzuarbeiten.

### Cluster — Cluster-Verwaltung

```php
// Cluster-Mitglieder ansehen
$members = $etcd->cluster()->memberList();

// Mitglied hinzufügen
$etcd->cluster()->memberAdd(['http://node3:2380']);        // Voting-Mitglied hinzufügen
$etcd->cluster()->memberAdd(['http://node4:2380'], true);  // Learner-Mitglied hinzufügen

// peer-URL eines Mitglieds ändern
$etcd->cluster()->memberUpdate(123456, ['http://newnode:2380']);

// Learner zum Voter befördern
$etcd->cluster()->memberPromote(789012);

// Mitglied entfernen
$etcd->cluster()->memberRemove(345678);
```

### Maintenance — Betrieb

```php
// Knotenstatus ansehen
$status = $etcd->maintenance()->status();
// ['version' => '3.5.0', 'dbSize' => 24576, 'leader' => 123, 'raftIndex' => 1000, ...]

// Alarm-Verwaltung
$alarms = $etcd->maintenance()->alarm();                    // Alarme ansehen
$etcd->maintenance()->alarm(action: 2, alarm: 1);           // NOSPACE-Alarm löschen

// defragmentieren (Speicher freigeben)
$etcd->maintenance()->defragment();

// KV-Hash-Prüfung
$hash = $etcd->maintenance()->hash();

// Snapshot holen (Rohbinärdaten, einfach in eine Datei schreiben)
$snapshot = $etcd->maintenance()->snapshot();
file_put_contents('/backup/etcd-snapshot.db', $snapshot);
```

## Transportmodi

| Modus | Status | Abhängigkeit | Geeignet für |
|------|------|------|---------|
| **HTTP** | verfügbar | PSR-18 + PSR-17 | keine Extension nötig, sofort einsatzbereit |
| **gRPC** | Skelett | ext-grpc + grpc/grpc + google/protobuf | hoher Durchsatz, natives Streaming |
| **auto** | Standard | automatische Erkennung | gRPC wenn vorhanden, sonst HTTP |

Erkennungslogik im Modus `auto`:
1. `extension_loaded('grpc')` — ist die C-Extension geladen?
2. `class_exists('Grpc\BaseStub')` — ist das Composer-Paket `grpc/grpc` installiert?

Nur wenn beides zutrifft, läuft der Verkehr über gRPC, sonst fällt der Client auf HTTP zurück.

### PSR-18-HTTP-Client manuell konfigurieren

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

## Framework-Integration

### Laravel

Funktioniert nach der Installation. `extra.laravel` in der composer.json entdeckt ServiceProvider und Facade automatisch.

```php
// per Facade
use Etcd;
Etcd::kv()->put('/foo', 'bar');
$val = Etcd::kv()->get('/foo');

// per Dependency Injection
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

Konfigurationsdatei veröffentlichen:

```bash
php artisan vendor:publish --tag=etcd-config
# → config/etcd.php
```

`.env`-Konfiguration:

```env
ETCD_ENDPOINTS=10.0.0.1:2379,10.0.0.2:2379
ETCD_USER=root
ETCD_PASSWORD=secret
```

### Hyperf

Funktioniert nach der Installation. Hyperf entdeckt den `ConfigProvider` automatisch.

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

// oder direkt mit make auflösen
$etcd = make(EtcdClient::class);
```

Konfiguration veröffentlichen:

```bash
php bin/hyperf.php vendor:publish erikwang2013/etcd
# → config/autoload/etcd.php
```

### ThinkPHP

1. Nach der Installation in `app/service.php` registrieren:

```php
return [
    Erikwang2013\Etcd\Adapter\ThinkPHP\Service::class,
];
```

2. Die Datei `config/etcd.php` anlegen.

Verwendung:

```php
// per Facade
use think\facade\Etcd;
Etcd::kv()->put('/key', 'value');

// per Container
app('etcd')->kv()->get('/key');
```

### Webman

Funktioniert nach der Installation, ohne zusätzliche Konfiguration.

```php
use Erikwang2013\Etcd\EtcdClient;

$etcd = EtcdClient::instance();
$etcd->kv()->put('/key', 'value');
```

Für eigene Einstellungen die Datei `plugin/erikwang2013/etcd/config/etcd.php` bearbeiten.

## Fehlerbehandlung

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
    // der etcd-Knoten ist nicht erreichbar (Netzwerkfehler, Knoten down)
} catch (AuthException $e) {
    // Authentifizierung fehlgeschlagen (falscher Benutzername oder falsches Passwort)
} catch (KeyNotFoundException $e) {
    // der Key existiert nicht (bei getOrFail())
} catch (EtcdException $e) {
    // sonstiger etcd-Serverfehler
}
```

## Projektstruktur

```
erikwang2013/etcd/
├── composer.json                    # Paketdefinition: PSR-4-Autoload + Laravel- / Hyperf-Discovery
├── phpunit.xml                      # PHPUnit-Konfiguration (Suites unit / integration)
├── config/etcd.php                  # Standardkonfiguration zum Veröffentlichen (liest ETCD_*-Umgebungsvariablen)
├── .github/workflows/release.yml    # automatische Veröffentlichung beim Tag
├── docs/                            # Dokumentation und Diagramme
│   ├── design-cn.md                 # Designdokument
│   ├── pet.svg                      # Projekt-Maskottchen Etchy
│   ├── architecture.svg             # Architekturdiagramm
│   ├── features.svg                 # Funktionsdiagramm
│   └── lifecycle.svg                # Lebenszyklusdiagramm
├── src/
│   ├── EtcdClient.php               # oberste Fassade + Singleton: kv / watch / lease / auth / cluster / maintenance
│   ├── Mascot.php                   # Zugriff auf das Maskottchen Etchy (svg / dataUri / path)
│   ├── Install.php                  # Webman-Plugin-Hook (WEBMAN_PLUGIN)
│   ├── Transport/                   # Transportschicht
│   │   ├── TransportInterface.php   # Transport-Abstraktion: send / sendRaw / watch
│   │   ├── TransportSelector.php    # automatische Auswahl auto / http / grpc
│   │   ├── HttpTransport.php        # HTTP-JSON-Transport (vollständig nutzbar)
│   │   └── GrpcTransport.php        # gRPC-Transport (Skelett)
│   ├── Kv/KvClient.php              # KV lesen/schreiben / Präfix-Scan / Transaktionen / Kompaktierung
│   ├── Watch/WatchClient.php        # Watch-Benachrichtigungen + Fortsetzen nach Abbruch
│   ├── Lease/LeaseClient.php        # Lease: grant / keepAlive / revoke
│   ├── Auth/                        # Auth: Authentifizierung und Autorisierung
│   │   ├── AuthClient.php           # Auth-Schalter und Status
│   │   ├── UserClient.php           # Benutzer-CRUD + Rollenbindung
│   │   └── RoleClient.php           # Rollen-CRUD + Berechtigungen
│   ├── Cluster/ClusterClient.php    # Cluster: Mitgliederverwaltung
│   ├── Maintenance/                 # Maintenance: status / alarm / defrag / snapshot
│   ├── Exception/                   # Ausnahmehierarchie
│   ├── Protobuf/                    # Nachrichten-Stubs (reine PHP-Datenklassen, ohne Message zu erben)
│   │   ├── Mvccpb/                  #   KeyValue, Event
│   │   ├── Etcdserverpb/            # 60+ Request-/Response-Nachrichten
│   │   └── Authpb/                  #   User, Role, Permission
│   └── Adapter/                     # Framework-Adapter
│       ├── Laravel/                 #   ServiceProvider + Facade
│       ├── Hyperf/                  #   ConfigProvider
│       ├── ThinkPHP/                #   Service + Facade
│       └── Webman/                  #   Plugin
└── tests/
    ├── Unit/                        # Unit-Tests (je Client / Transport / Adapter / Nachrichtenklasse)
    ├── Integration/                 # Integrationstests (gegen echtes etcd)
    └── Support/                     # FakeTransport, PSR-HTTP-Stubs
```

## Architektur und Design-Diagramme

Drei Diagramme, gegliedert nach Struktur → Fähigkeiten → Ablauf; jedes lässt sich einzeln in voller Größe öffnen:

| Diagramm | Beantwortete Frage | Datei |
|----|-----------|------|
| Architektur | In welche Schichten aufgeteilt, wohin zeigen die Abhängigkeiten, wie werden Fehler verteilt | [`diagrams/de/architecture.svg`](diagrams/de/architecture.svg) |
| Funktionsdesign | Welche Methoden bietet jedes Subsystem, welche Verhaltensverträge gelten | [`diagrams/de/features.svg`](diagrams/de/features.svg) |
| Lebenszyklus | Wie läuft eine Anfrage / ein Watch / eine Lease jeweils ab | [`diagrams/de/lifecycle.svg`](diagrams/de/lifecycle.svg) |

### Architektur

![Architekturdigramm](diagrams/de/architecture.svg)

### Funktionsdesign

![Funktionsdiagramm](diagrams/de/features.svg)

### Lebenszyklus

![Lebenszyklusdiagramm](diagrams/de/lifecycle.svg)

### Etchy im Code verwenden

Die Grafik wird mit dem Paket ausgeliefert, `Mascot` ist der einzige Zugriffspunkt — für ein Admin-Panel oder eine Statusseite ist keine eigene Kopie nötig:

```php
use Erikwang2013\Etcd\Mascot;

echo Mascot::svg();                                          // SVG-Quelltext, direkt inline einbetten
echo '<img src="' . Mascot::dataUri() . '" alt="Etchy">';    // data URI, ohne Web-Verzeichnis
copy(Mascot::path(), __DIR__ . '/public/etcd.svg');          // oder selbst ins Static-Verzeichnis kopieren
```

Unter Laravel lässt sich die Grafik direkt nach `public/` veröffentlichen:

```bash
php artisan vendor:publish --tag=etcd-assets
# → public/vendor/etcd/pet.svg
```

## Open Source lebt von Unterstützung / Support This Project

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
