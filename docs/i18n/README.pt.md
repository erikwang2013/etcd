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
  <img src="../pet.svg" alt="Mascote do projeto Etchy" width="200" />
</p>

<p align="center">
  <b>Etchy</b> · um elefantinho com um cluster Raft de três nós na cabeça e <code>k/v</code> e <code>rev</code> na ponta da tromba —<br/>
  ele guarda sua config e seus leases: reconecta sozinho quando cai, limpa sozinho quando expira.
</p>

Cliente PHP para etcd v3 — transporte duplo gRPC + HTTP, cobrindo toda a API do etcd v3 (KV / Watch / Lease / Auth / Cluster / Maintenance), com adaptadores prontos para **Laravel / Hyperf / ThinkPHP / Webman**.

## Requisitos

- PHP >= 8.1
- Servidor etcd v3.x
- Cliente HTTP PSR-18 + PSR-17 (obrigatório no transporte HTTP; a maioria dos frameworks já traz um)

## Instalação

```bash
composer require erikwang2013/etcd
```

## Início rápido

```php
use Erikwang2013\Etcd\EtcdClient;

$etcd = new EtcdClient(['endpoints' => ['127.0.0.1:2379']]);

// escreve
$etcd->kv()->put('/app/config', '{"debug":true}');

// lê
$result = $etcd->kv()->get('/app/config');
print_r($result['kvs'][0]);  // ['key' => '/app/config', 'value' => '{"debug":true}', ...]

// lança exceção quando não encontra
$kv = $etcd->kv()->getOrFail('/app/config');

// varredura por prefixo
$all = $etcd->kv()->getByPrefix('/app/');
echo "{$all['count']} chaves\n";

// exclui
$etcd->kv()->delete('/app/config');
$etcd->kv()->deleteByPrefix('/cache/');

// escreve com lease (excluído automaticamente após 60 segundos)
$lease = $etcd->lease()->grant(60);
$etcd->kv()->put('/session/123', 'active', ['lease' => $lease['ID']]);

// renova
$etcd->lease()->keepAlive($lease['ID']);
```

## Configuração

```php
$etcd = new EtcdClient([
    'endpoints' => ['192.168.1.10:2379', '192.168.1.11:2379'],  // vários nós
    'transport' => 'auto',  // auto (padrão) | http | grpc
    'scheme'    => 'http',  // http (padrão) | https
    'timeout'   => 5.0,     // segundos (configure no cliente PSR-18)
    'retry'     => 3,       // tentativas em falha de conexão
    'auth'      => [        // opcional, Basic Auth
        'user'     => 'root',
        'password' => 'secret',
    ],
]);
```

### Variáveis de ambiente

Sem config, estas são lidas automaticamente:

| Variável | Padrão | Descrição |
|------|--------|------|
| `ETCD_ENDPOINTS` | `127.0.0.1:2379` | endereços de nós separados por vírgula |
| `ETCD_TRANSPORT` | `auto` | auto / http / grpc |
| `ETCD_TIMEOUT` | `5.0` | timeout da requisição (segundos) |
| `ETCD_SCHEME` | `http` | http / https |
| `ETCD_RETRY` | `2` | tentativas de conexão |
| `ETCD_USER` | — | usuário do etcd |
| `ETCD_PASSWORD` | — | senha do etcd |

## Referência da API

### KV — operações de chave/valor

```php
// escreve
$etcd->kv()->put('key', 'value', [
    'lease'       => 12345,    // vincula um ID de lease
    'prevKv'      => true,     // retorna o valor anterior
    'ignoreValue' => false,
    'ignoreLease' => false,
]);

// lê uma única chave
$etcd->kv()->get('/exact/key');

// lança se não encontrar
$kv = $etcd->kv()->getOrFail('/exact/key');

// varredura por prefixo
$etcd->kv()->getByPrefix('/prefix/');

// consulta por faixa (todos os parâmetros)
$etcd->kv()->get('/start', [
    'rangeEnd'    => '/startz',      // fim da faixa
    'limit'       => 100,             // máximo de resultados
    'revision'    => 42,              // revisão do snapshot
    'sortOrder'   => 'ascend',        // none | ascend | descend
    'sortTarget'  => 'key',           // key | version | create | mod | value
    'serializable'=> true,            // pula o consenso Raft (mais rápido, pode estar desatualizado)
    'keysOnly'    => true,            // só keys, sem values
    'countOnly'   => false,           // somente a contagem
]);

// exclui
$etcd->kv()->delete('/key');
$etcd->kv()->deleteByPrefix('/prefix/');
$etcd->kv()->delete('/key', ['prevKv' => true]);  // também retorna o valor excluído

// transação (CAS atômico)
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

// compacta o histórico (libera espaço)
$etcd->kv()->compact(1000);
```

**Alvos de comparação (target):** `0`=VERSION, `1`=CREATE, `2`=MOD, `3`=VALUE, `4`=LEASE  
**Resultados de comparação (result):** `0`=EQUAL, `1`=GREATER, `2`=LESS, `3`=NOT_EQUAL

### Watch — notificações de mudança

```php
// observa uma única key (bloqueante; rode em corrotina ou processo separado)
$etcd->watch()->watch('/config/key', function (array $events) {
    foreach ($events as $event) {
        // $event: ['type' => 'PUT'|'DELETE', 'kv' => [...], 'prev_kv' => [...]|null]
        echo "{$event['type']} {$event['kv']['key']} = {$event['kv']['value']}\n";
    }
});

// observa todos os keys sob um prefixo
$etcd->watch()->watchPrefix('/config/', $callback, [
    'startRevision' => 100,       // começa de uma revisão
    'prevKv'        => true,      // eventos DELETE trazem o valor antigo
    'progressNotify'=> true,      // eventos vazios periódicos (heartbeat)
]);
```

**Reconexão:** quando a conexão do watch cai, ela se reinscreve automaticamente a partir da última revisão vista, sem perder eventos.

### Lease — TTL

```php
// cria um lease
$lease = $etcd->lease()->grant(300);             // TTL de 300 segundos
$lease = $etcd->lease()->grant(300, 99999);      // com ID de lease explícito

// renova uma vez
$result = $etcd->lease()->keepAlive($lease['ID']);
echo "TTL restante: {$result['TTL']}s";

// consulta o lease
$info = $etcd->lease()->timeToLive($lease['ID']);
$info = $etcd->lease()->timeToLive($lease['ID'], true);  // inclui as keys vinculadas

// lista todos os leases ativos
$leases = $etcd->lease()->list();

// revoga (todas as keys vinculadas são excluídas na hora)
$etcd->lease()->revoke($lease['ID']);
```

**Uso típico:** ao registrar o serviço, crie um lease e escreva sua key; depois chame `keepAlive()` periodicamente. Quando o serviço para, o lease expira e tudo é limpo automaticamente.

### Auth — autenticação e permissões

```php
$auth = $etcd->auth();

// === gestão de usuários ===
$auth->user()->add('alice', 'password123');          // cria um usuário
$auth->user()->get('alice');                         // inspeciona um usuário e seus papéis
$auth->user()->list();                               // lista os usuários
$auth->user()->changePassword('alice', 'newpass');    // troca a senha
$auth->user()->grantRole('alice', 'admin');          // concede um papel
$auth->user()->revokeRole('alice', 'admin');         // revoga um papel
$auth->user()->delete('alice');                      // exclui um usuário

// === gestão de papéis ===
$auth->role()->add('reader');                        // cria um papel
$auth->role()->get('reader');                        // inspeciona as permissões
$auth->role()->list();                               // lista os papéis

// concede uma permissão (permType: 0=READ, 1=WRITE, 2=READWRITE)
$auth->role()->grantPermission('reader', 0, '/data/', "\0");   // leitura do prefixo /data/
$auth->role()->grantPermission('writer', 2, '/data/', "\0");   // leitura e escrita
$auth->role()->revokePermission('reader', '/data/', "\0");     // revoga
$auth->role()->delete('reader');

// === chave de autenticação ===
$auth->enable();           // liga a autenticação
$auth->disable();          // desliga a autenticação
$status = $auth->status(); // ['enabled' => true, 'authRevision' => 5]
```

**Atenção:** com a autenticação ligada, o cliente precisa de `auth.user` e `auth.password` configurados para continuar operando.

### Cluster — gerenciamento do cluster

```php
// lista os membros
$members = $etcd->cluster()->memberList();

// adiciona um membro
$etcd->cluster()->memberAdd(['http://node3:2380']);        // membro votante
$etcd->cluster()->memberAdd(['http://node4:2380'], true);  // learner

// altera a peer URL de um membro
$etcd->cluster()->memberUpdate(123456, ['http://newnode:2380']);

// promove um learner a voter
$etcd->cluster()->memberPromote(789012);

// remove um membro
$etcd->cluster()->memberRemove(345678);
```

### Maintenance — operações

```php
// status do nó
$status = $etcd->maintenance()->status();
// ['version' => '3.5.0', 'dbSize' => 24576, 'leader' => 123, 'raftIndex' => 1000, ...]

// alarmes
$alarms = $etcd->maintenance()->alarm();                    // inspeciona os alarmes
$etcd->maintenance()->alarm(action: 2, alarm: 1);           // limpa o alarme NOSPACE

// desfragmenta (recupera espaço)
$etcd->maintenance()->defragment();

// verificação de hash do KV
$hash = $etcd->maintenance()->hash();

// snapshot (binário bruto, grave em arquivo)
$snapshot = $etcd->maintenance()->snapshot();
file_put_contents('/backup/etcd-snapshot.db', $snapshot);
```

## Modos de transporte

| Modo | Status | Requer | Ideal para |
|------|--------|------|---------|
| **HTTP** | disponível | PSR-18 + PSR-17 | zero dependências de extensão, funciona na hora |
| **gRPC** | esqueleto | ext-grpc + grpc/grpc + google/protobuf | alta vazão, streaming nativo |
| **auto** | padrão | detecção automática | gRPC quando disponível, senão HTTP |

Como o modo `auto` decide:
1. `extension_loaded('grpc')` — a extensão C está carregada?
2. `class_exists('Grpc\BaseStub')` — o pacote composer `grpc/grpc` está instalado?

Só quando os dois valem ele usa gRPC; caso contrário, cai para HTTP.

### Configurar um cliente HTTP PSR-18 manualmente

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

## Integração com frameworks

### Laravel

Instala e já funciona — o `extra.laravel` do composer.json descobre o ServiceProvider e o Facade automaticamente.

```php
// via Facade
use Etcd;
Etcd::kv()->put('/foo', 'bar');
$val = Etcd::kv()->get('/foo');

// via injeção de dependência
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

Publique o arquivo de config:

```bash
php artisan vendor:publish --tag=etcd-config
# → config/etcd.php
```

`.env`:

```env
ETCD_ENDPOINTS=10.0.0.1:2379,10.0.0.2:2379
ETCD_USER=root
ETCD_PASSWORD=secret
```

### Hyperf

Instala e já funciona — o Hyperf descobre o `ConfigProvider` automaticamente.

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

// ou resolva direto
$etcd = make(EtcdClient::class);
```

Publique a config:

```bash
php bin/hyperf.php vendor:publish erikwang2013/etcd
# → config/autoload/etcd.php
```

### ThinkPHP

1. Depois de instalar, registre o service em `app/service.php`:

```php
return [
    Erikwang2013\Etcd\Adapter\ThinkPHP\Service::class,
];
```

2. Crie o arquivo `config/etcd.php`.

Uso:

```php
// via Facade
use think\facade\Etcd;
Etcd::kv()->put('/key', 'value');

// via container
app('etcd')->kv()->get('/key');
```

### Webman

Instala e já funciona, sem configuração extra.

```php
use Erikwang2013\Etcd\EtcdClient;

$etcd = EtcdClient::instance();
$etcd->kv()->put('/key', 'value');
```

Para personalizar, edite `plugin/erikwang2013/etcd/config/etcd.php`.

## Tratamento de erros

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
    // o nó etcd está inacessível (falha de rede, nó fora do ar)
} catch (AuthException $e) {
    // falha de autenticação (usuário ou senha errados)
} catch (KeyNotFoundException $e) {
    // a key não existe (via getOrFail())
} catch (EtcdException $e) {
    // qualquer outro erro do servidor etcd
}
```

## Estrutura do projeto

```
erikwang2013/etcd/
├── composer.json                    # definição do pacote: autoload PSR-4 + descoberta Laravel / Hyperf
├── phpunit.xml                      # config do PHPUnit (suítes unit / integration)
├── config/etcd.php                  # config padrão publicada em cada framework (lê as variáveis ETCD_*)
├── .github/workflows/release.yml    # publicação automática ao criar a tag
├── scripts/i18n/                    #   ferramentas de docs: catálogos, gerador de diagramas, verificação
├── docs/                            # documentação e diagramas
│   ├── design-cn.md                 #   documento de design
│   ├── i18n/                        #   READMEs e diagramas localizados em 13 idiomas
│   ├── pet.svg                      #   mascote do projeto Etchy
│   ├── architecture.svg             #   diagrama de arquitetura
│   ├── features.svg                 #   diagrama de design de recursos
│   └── lifecycle.svg                #   diagrama de ciclo de vida
├── src/
│   ├── EtcdClient.php               # fachada principal + singleton: kv / watch / lease / auth / cluster / maintenance
│   ├── Mascot.php                   # acesso ao mascote Etchy (svg / dataUri / path)
│   ├── Install.php                  # hook do plugin Webman (WEBMAN_PLUGIN)
│   ├── Transport/                   # camada de transporte
│   │   ├── TransportInterface.php   #   abstração: send / sendRaw / watch
│   │   ├── TransportSelector.php    #   seleção auto / http / grpc
│   │   ├── HttpTransport.php        #   transporte HTTP JSON (completo, pronto para uso)
│   │   └── GrpcTransport.php        #   transporte gRPC (esqueleto)
│   ├── Kv/KvClient.php              # leitura/escrita KV / varredura por prefixo / transações / compactação
│   ├── Watch/WatchClient.php        # notificações de Watch + retomada após queda
│   ├── Lease/LeaseClient.php        # lease grant / keepAlive / revoke
│   ├── Auth/                        # autenticação e autorização
│   │   ├── AuthClient.php           #   chave de autenticação e status
│   │   ├── UserClient.php           #   CRUD de usuários + vínculo de papéis
│   │   └── RoleClient.php           #   CRUD de papéis + permissões
│   ├── Cluster/ClusterClient.php    # gerenciamento de membros do cluster
│   ├── Maintenance/                 # operações: status / alarm / defrag / snapshot
│   ├── Exception/                   # hierarquia de exceções
│   ├── Protobuf/                    # stubs de mensagem (classes PHP puras, sem herdar de Message)
│   │   ├── Mvccpb/                  #   KeyValue, Event
│   │   ├── Etcdserverpb/            #   60+ mensagens de requisição / resposta
│   │   └── Authpb/                  #   User, Role, Permission
│   └── Adapter/                     # adaptadores de framework
│       ├── Laravel/                 #   ServiceProvider + Facade
│       ├── Hyperf/                  #   ConfigProvider
│       ├── ThinkPHP/                #   Service + Facade
│       └── Webman/                  #   Plugin
└── tests/
    ├── Unit/                        # testes unitários (por cliente / transporte / adaptador / classe de mensagem)
    ├── Integration/                 # testes de integração (contra um etcd real)
    └── Support/                     # FakeTransport, stubs HTTP PSR
```

## Arquitetura e diagramas de design

Três diagramas, organizados como estrutura → capacidades → linha do tempo. Clique em qualquer um para abrir o arquivo em tamanho real:

| Diagrama | Que pergunta responde | Arquivo |
|----|-----------|------|
| Arquitetura | em quais camadas se divide, para onde apontam as dependências, como os erros se ramificam | [`diagrams/pt/architecture.svg`](diagrams/pt/architecture.svg) |
| Design de recursos | quais métodos cada subsistema oferece e quais contratos de comportamento tem | [`diagrams/pt/features.svg`](diagrams/pt/features.svg) |
| Ciclo de vida | como uma requisição / um watch / um lease se desenrola | [`diagrams/pt/lifecycle.svg`](diagrams/pt/lifecycle.svg) |

### Arquitetura

![Diagrama de arquitetura](diagrams/pt/architecture.svg)

### Design de recursos

![Diagrama de design de recursos](diagrams/pt/features.svg)

### Ciclo de vida

![Diagrama de ciclo de vida](diagrams/pt/lifecycle.svg)

### Usando o Etchy no código

A arte vem junto com o pacote e o `Mascot` é o único ponto de acesso, então um painel admin ou uma página de status não precisa de uma cópia própria:

```php
use Erikwang2013\Etcd\Mascot;

echo Mascot::svg();                                          // SVG bruto, inline direto
echo '<img src="' . Mascot::dataUri() . '" alt="Etchy">';    // data URI, sem precisar de web root
copy(Mascot::path(), __DIR__ . '/public/etcd.svg');          // ou copie para onde você serve os assets
```

No Laravel você pode publicar direto em `public/`:

```bash
php artisan vendor:publish --tag=etcd-assets
# → public/vendor/etcd/pet.svg
```

## Apoie este projeto

<p align="center">
  <table>
    <tr>
      <td align="center"><b>WeChat</b></td>
      <td align="center"><b>Alipay</b></td>
    </tr>
    <tr>
      <td align="center"><img src="../weixinpay.png" alt="Pagamento via WeChat" width="130" height="130" /></td>
      <td align="center"><img src="../alipay.png" alt="Pagamento via Alipay" width="130" height="130" /></td>
    </tr>
  </table>
</p>

---

## License

MIT — Copyright (c) 2026 [erik](https://erik.xyz) <erik@erik.xyz>
