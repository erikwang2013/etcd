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
  <img src="../pet.svg" alt="Mascota del proyecto Etchy" width="200" />
</p>

<p align="center">
  <b>Etchy</b> · un elefantito con un clúster Raft de tres nodos en la cabeza y <code>k/v</code> y <code>rev</code> colgando de la trompa ——<br/>
  vigila tu configuración y tus leases: si se cae, se reconecta solo; si caduca, se limpia solo.
</p>

Cliente PHP para etcd v3 —— transporte dual gRPC + HTTP, cubre toda la API de etcd v3 (KV / Watch / Lease / Auth / Cluster / Maintenance) y funciona sin configuración en **Laravel / Hyperf / ThinkPHP / Webman**.

## Requisitos

- PHP >= 8.1
- Servidor etcd v3.x
- Cliente HTTP PSR-18 + PSR-17 (necesario para el transporte HTTP; la mayoría de los frameworks ya lo incluye)

## Instalación

```bash
composer require erikwang2013/etcd
```

## Inicio rápido

```php
use Erikwang2013\Etcd\EtcdClient;

$etcd = new EtcdClient(['endpoints' => ['127.0.0.1:2379']]);

// escritura
$etcd->kv()->put('/app/config', '{"debug":true}');

// lectura
$result = $etcd->kv()->get('/app/config');
print_r($result['kvs'][0]);  // ['key' => '/app/config', 'value' => '{"debug":true}', ...]

// lanza excepción si no se encuentra
$kv = $etcd->kv()->getOrFail('/app/config');

// escaneo por prefijo
$all = $etcd->kv()->getByPrefix('/app/');
echo "total {$all['count']} claves\n";

// borrado
$etcd->kv()->delete('/app/config');
$etcd->kv()->deleteByPrefix('/cache/');

// escritura con lease (se elimina a los 60 s)
$lease = $etcd->lease()->grant(60);
$etcd->kv()->put('/session/123', 'active', ['lease' => $lease['ID']]);

// renovación
$etcd->lease()->keepAlive($lease['ID']);
```

## Configuración

```php
$etcd = new EtcdClient([
    'endpoints' => ['192.168.1.10:2379', '192.168.1.11:2379'],  // varios nodos
    'transport' => 'auto',  // auto (por defecto) | http | grpc
    'scheme'    => 'http',  // http (por defecto) | https
    'timeout'   => 5.0,     // segundos (se configura en el cliente PSR-18)
    'retry'     => 3,       // reintentos si falla la conexión
    'auth'      => [        // opcional, Basic Auth
        'user'     => 'root',
        'password' => 'secret',
    ],
]);
```

### Variables de entorno

Si no se pasa configuración, se leen automáticamente las variables de entorno:

| Variable | Valor por defecto | Descripción |
|------|--------|------|
| `ETCD_ENDPOINTS` | `127.0.0.1:2379` | Direcciones de varios nodos separadas por comas |
| `ETCD_TRANSPORT` | `auto` | auto / http / grpc |
| `ETCD_TIMEOUT` | `5.0` | Timeout de petición (segundos) |
| `ETCD_SCHEME` | `http` | http / https |
| `ETCD_RETRY` | `2` | Número de reintentos de conexión |
| `ETCD_USER` | — | Usuario de etcd |
| `ETCD_PASSWORD` | — | Contraseña de etcd |

## Referencia de la API

### KV — operaciones clave-valor

```php
// escritura
$etcd->kv()->put('key', 'value', [
    'lease'       => 12345,    // ID del lease vinculado
    'prevKv'      => true,     // devuelve el valor anterior
    'ignoreValue' => false,
    'ignoreLease' => false,
]);

// leer una sola clave
$etcd->kv()->get('/exact/key');

// lanza excepción si no se encuentra
$kv = $etcd->kv()->getOrFail('/exact/key');

// escaneo por prefijo
$etcd->kv()->getByPrefix('/prefix/');

// consulta por rango (parámetros completos)
$etcd->kv()->get('/start', [
    'rangeEnd'    => '/startz',      // clave final del rango
    'limit'       => 100,             // máximo de resultados
    'revision'    => 42,              // revisión de la instantánea
    'sortOrder'   => 'ascend',        // none | ascend | descend
    'sortTarget'  => 'key',           // key | version | create | mod | value
    'serializable'=> true,            // omite el consenso Raft (más rápido, puede quedar obsoleto)
    'keysOnly'    => true,            // solo devuelve key, sin value
    'countOnly'   => false,           // solo devuelve el recuento
]);

// borrado
$etcd->kv()->delete('/key');
$etcd->kv()->deleteByPrefix('/prefix/');
$etcd->kv()->delete('/key', ['prevKv' => true]);  // devuelve también el valor borrado

// transacción (CAS atómico)
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

// compacta el historial (libera espacio)
$etcd->kv()->compact(1000);
```

**Constantes de destino de comparación (target):** `0`=VERSION, `1`=CREATE, `2`=MOD, `3`=VALUE, `4`=LEASE  
**Constantes de resultado de comparación (result):** `0`=EQUAL, `1`=GREATER, `2`=LESS, `3`=NOT_EQUAL

### Watch — escucha de cambios

```php
// vigila una sola clave (bloqueante; mejor en una corrutina o proceso aparte)
$etcd->watch()->watch('/config/key', function (array $events) {
    foreach ($events as $event) {
        // $event: ['type' => 'PUT'|'DELETE', 'kv' => [...], 'prev_kv' => [...]|null]
        echo "{$event['type']} {$event['kv']['key']} = {$event['kv']['value']}\n";
    }
});

// vigila los cambios de todas las claves bajo un prefijo
$etcd->watch()->watchPrefix('/config/', $callback, [
    'startRevision' => 100,       // empieza en la revisión indicada
    'prevKv'        => true,      // los eventos DELETE devuelven el valor anterior
    'progressNotify'=> true,      // envía eventos vacíos periódicos (latido)
]);
```

**Reconexión:** si la conexión de Watch se corta, se reanuda desde el último revision recibido sin perder eventos.

### Lease — gestión de leases

```php
// crear el lease
$lease = $etcd->lease()->grant(300);             // TTL de 300 s
$lease = $etcd->lease()->grant(300, 99999);      // ID de lease explícito

// renovación (una sola vez)
$result = $etcd->lease()->keepAlive($lease['ID']);
echo "TTL restante: {$result['TTL']} s";

// consultar el estado del lease
$info = $etcd->lease()->timeToLive($lease['ID']);
$info = $etcd->lease()->timeToLive($lease['ID'], true);  // incluye la lista de claves vinculadas

// listar todos los leases activos
$leases = $etcd->lease()->list();

// revocar el lease (borra al instante las claves vinculadas)
$etcd->lease()->revoke($lease['ID']);
```

**Escenario típico:** al registrar un servicio se crea un lease y se escribe la clave, y un temporizador llama a `keepAlive()` para renovar el latido; cuando el servicio se detiene, el lease caduca y se limpia solo.

### Auth — autenticación y permisos

```php
$auth = $etcd->auth();

// === gestión de usuarios ===
$auth->user()->add('alice', 'password123');          // crear usuario
$auth->user()->get('alice');                         // ver el usuario y sus roles
$auth->user()->list();                               // listar todos los usuarios
$auth->user()->changePassword('alice', 'newpass');    // cambiar la contraseña
$auth->user()->grantRole('alice', 'admin');          // asignar rol
$auth->user()->revokeRole('alice', 'admin');         // revocar rol
$auth->user()->delete('alice');                      // borrar usuario

// === gestión de roles ===
$auth->role()->add('reader');                        // crear rol
$auth->role()->get('reader');                        // ver los permisos del rol
$auth->role()->list();                               // listar todos los roles

// conceder permiso (permType: 0=READ, 1=WRITE, 2=READWRITE)
$auth->role()->grantPermission('reader', 0, '/data/', "\0");   // permiso de lectura sobre el prefijo /data/
$auth->role()->grantPermission('writer', 2, '/data/', "\0");   // permiso de lectura y escritura
$auth->role()->revokePermission('reader', '/data/', "\0");     // revocar permiso
$auth->role()->delete('reader');

// === interruptor de autenticación ===
$auth->enable();           // activar la autenticación
$auth->disable();          // desactivar la autenticación
$status = $auth->status(); // ['enabled' => true, 'authRevision' => 5]
```

**Atención:** con la autenticación activada, el cliente debe configurar `auth.user` y `auth.password` para poder seguir operando.

### Cluster — gestión del clúster

```php
// ver los miembros del clúster
$members = $etcd->cluster()->memberList();

// añadir un miembro
$etcd->cluster()->memberAdd(['http://node3:2380']);        // añadir un miembro Voting
$etcd->cluster()->memberAdd(['http://node4:2380'], true);  // añadir un miembro Learner

// modificar la peer URL de un miembro
$etcd->cluster()->memberUpdate(123456, ['http://newnode:2380']);

// promover un Learner a Voter
$etcd->cluster()->memberPromote(789012);

// eliminar un miembro
$etcd->cluster()->memberRemove(345678);
```

### Maintenance — operación y mantenimiento

```php
// ver el estado del nodo
$status = $etcd->maintenance()->status();
// ['version' => '3.5.0', 'dbSize' => 24576, 'leader' => 123, 'raftIndex' => 1000, ...]

// gestión de alarmas
$alarms = $etcd->maintenance()->alarm();                    // consultar las alarmas
$etcd->maintenance()->alarm(action: 2, alarm: 1);           // limpiar la alarma NOSPACE

// desfragmentar (recuperar espacio)
$etcd->maintenance()->defragment();

// verificación hash de KV
$hash = $etcd->maintenance()->hash();

// obtener una instantánea (binario; basta volcarlo a un archivo)
$snapshot = $etcd->maintenance()->snapshot();
file_put_contents('/backup/etcd-snapshot.db', $snapshot);
```

## Modos de transporte

| Modo | Estado | Dependencias | Cuándo usarlo |
|------|------|------|---------|
| **HTTP** | Disponible | PSR-18 + PSR-17 | Cero dependencias de extensiones, funciona al momento |
| **gRPC** | Esqueleto | ext-grpc + grpc/grpc + google/protobuf | Alto rendimiento, streaming nativo |
| **auto** | Por defecto | Detección automática | Usa gRPC si está disponible, si no HTTP |

Lógica de detección del modo `auto`:
1. `extension_loaded('grpc')` — ¿extensión C cargada?
2. `class_exists('Grpc\BaseStub')` — ¿paquete composer `grpc/grpc` instalado?

Solo si se cumplen ambas se usa gRPC; en caso contrario se recurre a HTTP.

### Configurar a mano el cliente HTTP PSR-18

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

## Integración con frameworks

### Laravel

Listo tras instalar. La clave `extra.laravel` de composer.json descubre automáticamente el ServiceProvider y la Facade.

```php
// con la Facade
use Etcd;
Etcd::kv()->put('/foo', 'bar');
$val = Etcd::kv()->get('/foo');

// con inyección de dependencias
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

Publicar el archivo de configuración:

```bash
php artisan vendor:publish --tag=etcd-config
# → config/etcd.php
```

Configuración en `.env`:

```env
ETCD_ENDPOINTS=10.0.0.1:2379,10.0.0.2:2379
ETCD_USER=root
ETCD_PASSWORD=secret
```

### Hyperf

Listo tras instalar. Hyperf descubre `ConfigProvider` automáticamente.

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

// o directamente con make
$etcd = make(EtcdClient::class);
```

Publicar la configuración:

```bash
php bin/hyperf.php vendor:publish erikwang2013/etcd
# → config/autoload/etcd.php
```

### ThinkPHP

1. Tras instalar, registrar en `app/service.php`:

```php
return [
    Erikwang2013\Etcd\Adapter\ThinkPHP\Service::class,
];
```

2. Crear el archivo de configuración `config/etcd.php`.

Uso:

```php
// con la Facade
use think\facade\Etcd;
Etcd::kv()->put('/key', 'value');

// con el contenedor
app('etcd')->kv()->get('/key');
```

### Webman

Listo tras instalar, sin configuración adicional.

```php
use Erikwang2013\Etcd\EtcdClient;

$etcd = EtcdClient::instance();
$etcd->kv()->put('/key', 'value');
```

Para personalizar la configuración, editar `plugin/erikwang2013/etcd/config/etcd.php`.

## Manejo de excepciones

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
    // no se puede conectar al nodo etcd (fallo de red, caída)
} catch (AuthException $e) {
    // fallo de autenticación (usuario o contraseña incorrectos)
} catch (KeyNotFoundException $e) {
    // la clave no existe en getOrFail()
} catch (EtcdException $e) {
    // otros errores del servidor etcd
}
```

## Estructura del proyecto

```
erikwang2013/etcd/
├── composer.json # definición del paquete: autoload PSR-4 + autodescubrimiento en Laravel / Hyperf
├── phpunit.xml # configuración de PHPUnit (suites unit / integration)
├── config/etcd.php # configuración por defecto que publican los frameworks (lee variables ETCD_*)
├── .github/workflows/release.yml # publica automáticamente al crear un tag
├── docs/ # documentación y diagramas
│   ├── design-cn.md # documento de diseño
│   ├── pet.svg # mascota del proyecto Etchy
│   ├── architecture.svg # diagrama de arquitectura
│   ├── features.svg # diagrama de diseño de funciones
│   └── lifecycle.svg # diagrama de ciclo de vida
├── src/
│   ├── EtcdClient.php # fachada de nivel superior + singleton: kv / watch / lease / auth / cluster / maintenance
│   ├── Mascot.php # punto de acceso a la mascota Etchy (svg / dataUri / path)
│   ├── Install.php # hook del plugin de Webman (WEBMAN_PLUGIN)
│   ├── Transport/ # capa de transporte
│   │   ├── TransportInterface.php # abstracción de transporte: send / sendRaw / watch
│   │   ├── TransportSelector.php # selección automática auto / http / grpc
│   │   ├── HttpTransport.php # transporte HTTP JSON (completo y funcional)
│   │   └── GrpcTransport.php # transporte gRPC (esqueleto)
│   ├── Kv/KvClient.php # KV: lectura/escritura / escaneo por prefijo / transacciones / compactación
│   ├── Watch/WatchClient.php # Watch: escucha de cambios + reanudación tras corte
│   ├── Lease/LeaseClient.php        # Lease: grant / keepAlive / revoke
│   ├── Auth/ # Auth: autenticación y autorización
│   │   ├── AuthClient.php # interruptor y estado de la autenticación
│   │   ├── UserClient.php # CRUD de usuarios + vínculo de roles
│   │   └── RoleClient.php # CRUD de roles + permisos
│   ├── Cluster/ClusterClient.php # Cluster: gestión de miembros del clúster
│   ├── Maintenance/                 # Maintenance: status / alarm / defrag / snapshot
│   ├── Exception/ # jerarquía de excepciones
│   ├── Protobuf/ # stubs de mensajes (clases de datos PHP sin heredar de Message)
│   │   ├── Mvccpb/ # KeyValue, Event
│   │   ├── Etcdserverpb/ # 60+ mensajes de petición / respuesta
│   │   └── Authpb/ # User, Role, Permission
│   └── Adapter/ # adaptadores de framework
│       ├── Laravel/                 #   ServiceProvider + Facade
│       ├── Hyperf/                  #   ConfigProvider
│       ├── ThinkPHP/                #   Service + Facade
│       └── Webman/                  #   Plugin
└── tests/
    ├── Unit/ # tests unitarios (por cliente / transporte / adaptador / clase de mensaje)
    ├── Integration/ # tests de integración (contra un etcd real)
    └── Support/ # FakeTransport, stubs HTTP PSR
```

## Arquitectura y diagramas

Los tres diagramas se organizan como «estructura → capacidades → secuencia» y pueden abrirse por separado:

| Diagrama | Pregunta que responde | Archivo |
|----|-----------|------|
| Arquitectura | En qué capas se divide, hacia dónde van las dependencias, cómo se separan los errores | [`docs/architecture.svg`](diagrams/es/architecture.svg) |
| Diseño de funciones | Qué métodos ofrece cada subsistema y qué contratos de comportamiento tiene | [`docs/features.svg`](diagrams/es/features.svg) |
| Ciclo de vida | Cómo recorre cada uno una petición / un watch / un lease | [`docs/lifecycle.svg`](diagrams/es/lifecycle.svg) |

### Arquitectura

![Diagrama de arquitectura](diagrams/es/architecture.svg)

### Diseño de funciones

![Diagrama de diseño de funciones](diagrams/es/features.svg)

### Ciclo de vida

![Diagrama de ciclo de vida](diagrams/es/lifecycle.svg)

### Usar Etchy en el código

Los gráficos se publican con el paquete y `Mascot` es el único punto de acceso, así no hay que copiarlos para un panel de administración o una página de estado:

```php
use Erikwang2013\Etcd\Mascot;

echo Mascot::svg();                                          // código SVG, para incrustar directamente
echo '<img src="' . Mascot::dataUri() . '" alt="Etchy">';    // data URI, sin depender del directorio web
copy(Mascot::path(), __DIR__ . '/public/etcd.svg');          // o copiarlo a un directorio estático
```

En Laravel pueden publicarse directamente en `public/`:

```bash
php artisan vendor:publish --tag=etcd-assets
# → public/vendor/etcd/pet.svg
```

## Mantener el open source no es fácil, tu apoyo es bienvenido / Support This Project

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
