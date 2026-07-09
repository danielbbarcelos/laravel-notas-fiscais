# Guia de integração — Laravel (API multi-tenant)

Este guia mostra como consumir o pacote **`danielbbarcelos/laravel-notas-fiscais`** dentro da
**sua própria API Laravel**, num cenário **multi-tenant / SaaS**: cada cliente (tenant) tem o
próprio CNPJ, município e credenciais, injetados **em runtime** — nunca fixos no `.env`.

O exemplo principal usa o driver **`ipm-abrasf`** (ABRASF 2.04 / SOAP — o padrão de
**Pouso Alegre/MG**), com uma nota de como trocar para o driver **`ipm`** (REST proprietário).

> Para os detalhes fiscais do provedor IPM (qual driver o município usa, gotchas de competência,
> formato do código do item, link do PDF etc.), veja o **[README](../README.md)**. Aqui o foco é a
> **arquitetura da integração** na sua API.

## Sumário

1. [Visão geral](#1-visão-geral)
2. [Instalação](#2-instalação)
3. [Modelagem de credenciais por tenant](#3-modelagem-de-credenciais-por-tenant)
4. [Resolver o gateway por tenant](#4-resolver-o-gateway-por-tenant)
5. [Camada de service](#5-camada-de-service)
6. [Montando os DTOs a partir do request](#6-montando-os-dtos-a-partir-do-request)
7. [Endpoints REST](#7-endpoints-rest)
8. [Persistência da nota emitida](#8-persistência-da-nota-emitida)
9. [PDF do comprovante](#9-pdf-do-comprovante)
10. [Exportação de arquivos (XML/TXT)](#10-exportação-de-arquivos-xmltxt)
11. [Fluxo ponta a ponta](#11-fluxo-ponta-a-ponta)
12. [Troubleshooting](#12-troubleshooting)

---

## 1. Visão geral

O pacote expõe um **contrato único** (`NotaFiscal` facade → `NfseGateway`) com **drivers por
provedor**. Você monta um DTO canônico (`NotaServico`) e chama `emitir()`; o driver traduz para o
XML/endpoint do município e devolve um `NotaEmitida`.

Num SaaS, a regra de ouro é: **não guarde credenciais de clientes no `.env`/config**. O `.env` é só
um default single-tenant opcional. Cada tenant guarda as próprias credenciais (cifradas) no banco e
você as injeta em runtime com `NotaFiscal::build([...])`. Instâncias construídas assim **não são
cacheadas pelo nome** e a sessão HTTP (`PHPSESSID`) é **isolada por credencial** — sem vazamento
entre tenants.

Contrato do gateway (o que você vai usar):

```php
interface NfseGateway
{
    public function emitir(NotaServico $dados): NotaEmitida;
    public function cancelar(Cancelamento $dados): NotaEmitida;
    public function consultar(int $numero, int $serie, string $cadastro): NotaEmitida;
    public function consultarPorAutenticidade(string $codigo): NotaEmitida;
}
```

Os gateways IPM também implementam o contrato **opcional** `ExportaArquivos`, que gera o XML nativo
do provedor e o TXT de exportação da nota — reproduzindo o menu *Download* do Atende.Net (ver
[seção 10](#10-exportação-de-arquivos-xmltxt)):

```php
interface ExportaArquivos
{
    public function xmlNota(NotaServico $dados, ?NotaEmitida $emitida = null): string;
    public function txtExportacao(NotaServico $dados, NotaEmitida $emitida): string;
}
```

---

## 2. Instalação

Na raiz da sua API:

```bash
composer require danielbbarcelos/laravel-notas-fiscais
php artisan vendor:publish --tag=notas-fiscais-config   # publica config/notas-fiscais.php
composer require dompdf/dompdf                           # opcional: comprovante em PDF
```

Num SaaS você pode **deixar o `config/notas-fiscais.php` praticamente vazio de credenciais** — os
valores virão do tenant. O arquivo publicado tem as chaves de cada driver; útil como referência do
formato esperado.

---

## 3. Modelagem de credenciais por tenant

Cada driver espera um conjunto de chaves diferente. Os nomes abaixo são os **exatos** que
`NotaFiscal::build()` consome:

| Chave (`build`)        | `ipm-abrasf` (ABRASF/SOAP)        | `ipm` (REST)                     |
|------------------------|-----------------------------------|----------------------------------|
| `driver`               | `'ipm-abrasf'`                    | `'ipm'`                          |
| `base_url`             | URL do WebService ABRASF          | URL do WebService REST           |
| `cpf_cnpj`             | CNPJ do prestador (login)         | CNPJ do prestador (login)        |
| `senha`                | senha **do WebService**           | senha **do WebService**          |
| `inscricao_municipal`  | inscrição municipal               | —                                |
| `codigo_ibge`          | **código IBGE** (7 díg.) do mun.  | —                                |
| `cidade`               | —                                 | **código TOM** do município      |
| `link_template`        | template do link do PDF (`{codigo}`) | idem (opcional)               |
| `timeout`              | opcional (default 60)             | opcional (default 30)            |

> **Atenção às chaves específicas:** ABRASF identifica o município por **`codigo_ibge`** e exige
> **`inscricao_municipal`**; o REST usa **`cidade`** (código TOM). Trocar uma pela outra é uma das
> causas comuns de erro.

### Migration

```php
// database/migrations/xxxx_create_nfse_credenciais_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('nfse_credenciais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('driver');                       // 'ipm-abrasf' | 'ipm'
            $table->string('base_url');
            $table->string('cpf_cnpj');
            $table->text('senha');                          // cifrada (cast 'encrypted')
            $table->string('inscricao_municipal')->nullable();
            $table->string('codigo_ibge')->nullable();      // ABRASF
            $table->string('cidade')->nullable();           // IPM REST (código TOM)
            $table->text('link_template')->nullable();
            $table->text('proxy')->nullable();              // cifrada (traz usuário:senha na URL)
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nfse_credenciais');
    }
};
```

### Model

O cast `encrypted` garante que a **senha nunca fica em claro** no banco (usa a `APP_KEY`). Vale
para o `proxy` também: a URL costuma embutir usuário e senha.

```php
// app/Models/NfseCredencial.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NfseCredencial extends Model
{
    protected $fillable = [
        'tenant_id', 'driver', 'base_url', 'cpf_cnpj', 'senha',
        'inscricao_municipal', 'codigo_ibge', 'cidade', 'link_template', 'proxy',
    ];

    protected $casts = [
        'senha' => 'encrypted',
        'proxy' => 'encrypted',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Array de config no formato que NotaFiscal::build() espera.
     * Só inclui as chaves relevantes ao driver, sem nulos.
     */
    public function paraConfig(): array
    {
        return array_filter([
            'driver'              => $this->driver,
            'base_url'            => $this->base_url,
            'cpf_cnpj'            => $this->cpf_cnpj,
            'senha'               => $this->senha,           // descriptografada pelo cast
            'inscricao_municipal' => $this->inscricao_municipal,
            'codigo_ibge'         => $this->codigo_ibge,
            'cidade'              => $this->cidade,
            'link_template'       => $this->link_template,
            'proxy'               => $this->proxy,           // só p/ município que exige IP nacional
        ], fn ($v) => $v !== null && $v !== '');
    }
}
```

> Se você já tem um model `Tenant`, pode guardar essas colunas nele em vez de uma tabela separada —
> o que importa é o método `paraConfig()` devolver o array no formato acima.

---

## 4. Resolver o gateway por tenant

Com o array de config em mãos, o `NfseGateway` sai em uma linha:

```php
use DanielBBarcelos\NotasFiscais\Facades\NotaFiscal;

$gateway = NotaFiscal::build($tenant->nfseCredencial->paraConfig())->nfse();
```

- `NotaFiscal::build(array $config, ?string $nome = null)` monta o `Provedor` **só com essa config**.
- `->nfse()` devolve o `NfseGateway`.
- Instâncias de `build()` **não são cacheadas pelo nome**, então tenants concorrentes não se
  misturam; cada credencial tem sua própria sessão HTTP.

Se quiser partir de um driver nomeado da config e **sobrepor** só alguns campos por tenant:

```php
NotaFiscal::driver('ipm-abrasf', [
    'cpf_cnpj' => $tenant->cnpj,
    'senha'    => decrypt($tenant->ipmSenha),
])->nfse();
```

---

## 5. Camada de service

Concentre a resolução do tenant e o **tratamento de exceções** numa única classe. Assim os
controllers ficam finos e o mapeamento de erro → HTTP fica em um lugar só.

```php
// app/Services/NotaFiscalService.php
namespace App\Services;

use App\Models\Tenant;
use DanielBBarcelos\NotasFiscais\Facades\NotaFiscal;
use DanielBBarcelos\NotasFiscais\Contracts\{NfseGateway, ExportaArquivos};
use DanielBBarcelos\NotasFiscais\Data\Nfse\{NotaServico, Cancelamento, NotaEmitida};
use DanielBBarcelos\NotasFiscais\Exceptions\OperacaoNaoSuportadaException;

class NotaFiscalService
{
    private function gateway(Tenant $tenant): NfseGateway
    {
        return NotaFiscal::build($tenant->nfseCredencial->paraConfig())->nfse();
    }

    public function emitir(Tenant $tenant, NotaServico $nota): NotaEmitida
    {
        return $this->gateway($tenant)->emitir($nota);
    }

    public function cancelar(Tenant $tenant, Cancelamento $cancelamento): NotaEmitida
    {
        return $this->gateway($tenant)->cancelar($cancelamento);
    }

    public function consultar(Tenant $tenant, int $numero, int $serie, string $cadastro): NotaEmitida
    {
        return $this->gateway($tenant)->consultar($numero, $serie, $cadastro);
    }

    public function consultarPorAutenticidade(Tenant $tenant, string $codigo): NotaEmitida
    {
        return $this->gateway($tenant)->consultarPorAutenticidade($codigo);
    }

    // --- Exportação de arquivos (ver seção 10) ---

    public function xmlNota(Tenant $tenant, NotaServico $nota, ?NotaEmitida $emitida = null): string
    {
        return $this->exportador($tenant)->xmlNota($nota, $emitida);
    }

    public function txtExportacao(Tenant $tenant, NotaServico $nota, NotaEmitida $emitida): string
    {
        return $this->exportador($tenant)->txtExportacao($nota, $emitida);
    }

    private function exportador(Tenant $tenant): ExportaArquivos
    {
        $gateway = $this->gateway($tenant);

        if (! $gateway instanceof ExportaArquivos) {
            throw new OperacaoNaoSuportadaException('Este driver não suporta exportação de arquivos.');
        }

        return $gateway;
    }
}
```

### Tratamento de exceções

Todas as exceções do pacote estendem `NotaFiscalException`. Trate-as num handler central e mapeie
para respostas HTTP claras:

```php
// app/Exceptions/Handler.php  (register())
use DanielBBarcelos\NotasFiscais\Exceptions\{
    NotaFiscalApiException, OperacaoNaoSuportadaException, NotaFiscalException
};

$this->renderable(function (NotaFiscalApiException $e, $request) {
    // Erro devolvido pelo provedor: tem ->codigo, ->corpo (array) e ->statusHttp
    return response()->json([
        'erro'    => 'Falha na emissão junto ao provedor.',
        'codigo'  => $e->codigo,     // ex.: "L1029", "00128"
        'mensagem'=> $e->getMessage(),
        'corpo'   => $e->corpo,      // retorno bruto parseado do provedor
    ], 422);
});

$this->renderable(fn (OperacaoNaoSuportadaException $e) =>
    response()->json(['erro' => $e->getMessage()], 400));

$this->renderable(fn (NotaFiscalException $e) =>          // base — catch-all
    response()->json(['erro' => $e->getMessage()], 500));
```

> **Sobre o `401 "Acesso Negado"`:** ele chega como `NotaFiscalApiException` (credencial/senha do
> WebService rejeitada, ou CNPJ não habilitado no município). Veja o
> [Troubleshooting](#12-troubleshooting) e o README para o passo a passo.

---

## 6. Montando os DTOs a partir do request

Os DTOs são `final readonly` (imutáveis) e usam **parâmetros nomeados**. Abaixo, um mapeamento de um
payload JSON da API para o `NotaServico`. As assinaturas são as reais do pacote.

```php
use DanielBBarcelos\NotasFiscais\Data\Nfse\{NotaServico, ItemServico, Tomador, Prestador};
use DanielBBarcelos\NotasFiscais\Data\Shared\{Valor, Endereco};
use DanielBBarcelos\NotasFiscais\Enums\{TipoTomador, SituacaoTributaria};

$d = $request->validated();

$nota = new NotaServico(
    serie:            (int) $d['serie'],
    dataFatoGerador:  $d['data_fato_gerador'],          // 'dd/mm/aaaa'
    valorTotal:       Valor::reais($d['valor_total']),  // "1000.00" ou float
    tomador: new Tomador(
        tipo:            TipoTomador::from($d['tomador']['tipo']),   // 'F' | 'J' | 'E'
        identificacao:   $d['tomador']['documento'],
        nomeRazaoSocial: $d['tomador']['nome'],
        endereco: new Endereco(
            logradouro:      $d['tomador']['endereco']['logradouro'] ?? null,
            numero:          $d['tomador']['endereco']['numero'] ?? null,
            bairro:          $d['tomador']['endereco']['bairro'] ?? null,
            codigoMunicipio: $d['tomador']['endereco']['codigo_municipio'] ?? null,
            uf:              $d['tomador']['endereco']['uf'] ?? null,
            cep:             $d['tomador']['endereco']['cep'] ?? null,
        ),
        email: $d['tomador']['email'] ?? null,
    ),
    itens: array_map(fn (array $i) => new ItemServico(
        codigoItemListaServico: $i['codigo_item'],      // ABRASF: pontuado, ex. '01.01.01'
        descritivo:             $i['descricao'],
        aliquota:               $i['aliquota'],         // float|string, ex. 3.0
        situacaoTributaria:     SituacaoTributaria::from((int) $i['situacao_tributaria']),
        valorTributavel:        Valor::reais($i['valor']),
        codigoLocalPrestacao:   $i['codigo_local_prestacao'],   // código do município
        codigoCnae:             $i['cnae'] ?? null,
    ), $d['itens']),

    // Opcional: se a nota não usa o prestador do .env, informe-o explicitamente.
    prestador: new Prestador(
        cpfCnpj:            $tenant->nfseCredencial->cpf_cnpj,
        codigoMunicipio:    $tenant->nfseCredencial->codigo_ibge,        // ABRASF
        inscricaoMunicipal: $tenant->nfseCredencial->inscricao_municipal,
    ),

    observacao:  $d['observacao'] ?? null,
    competencia: $d['competencia'] ?? null,   // ABRASF: use a data atual (ver README)
);
```

Pontos que valem lembrar (detalhados no README, seção ABRASF):

- **`competencia`**: no ABRASF, use a **data atual** — competência retroativa é recusada (`L1029`).
- **`codigoItemListaServico`**: formato **pontuado** (`99.99`, `99.99.99`, `99.99.99.999`) e
  **vinculado ao cadastro econômico** do prestador. Formato errado → `L1099`; não vinculado → `L1003`.
- **`codigoCnae`** e o item devem ser **coerentes** entre si.
- **`Valor`**: crie sempre via `Valor::reais('1000.00')` ou `Valor::centavos(100000)` — não instancie
  direto (construtor privado). Formate com `->paraApi()` ("1000.00") ou `->paraReal()` ("1.000,00").

Enums úteis:

- `TipoTomador`: `Fisica='F'`, `Juridica='J'`, `Estrangeiro='E'`.
- `SituacaoTributaria` (int): `TributadaIntegralmente=0`, `Isenta=6`, `Imune=7`, `NaoTributada=14`, … (ver `src/Enums/SituacaoTributaria.php`).
- `SituacaoNota` (retorno): `Emitida=1`, `Cancelada=2`.

---

## 7. Endpoints REST

### Rotas

```php
// routes/api.php
use App\Http\Controllers\Api\NfseController;

Route::middleware(['auth:sanctum'])->prefix('nfse')->group(function () {
    Route::post('/',                    [NfseController::class, 'emitir']);
    Route::post('/{nota}/cancelar',     [NfseController::class, 'cancelar']);
    Route::get('/{nota}',               [NfseController::class, 'mostrar']);
    Route::get('/autenticidade/{codigo}', [NfseController::class, 'porAutenticidade']);
    Route::get('/{nota}/comprovante',   [NfseController::class, 'comprovante']);
});
```

### FormRequest (validação)

```php
// app/Http/Requests/EmitirNfseRequest.php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EmitirNfseRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'serie'                    => ['required', 'integer'],
            'data_fato_gerador'        => ['required', 'date_format:d/m/Y'],
            'valor_total'              => ['required', 'numeric', 'min:0'],
            'competencia'              => ['nullable', 'date_format:d/m/Y'],
            'observacao'               => ['nullable', 'string'],
            'tomador.tipo'             => ['required', 'in:F,J,E'],
            'tomador.documento'        => ['required', 'string'],
            'tomador.nome'             => ['required', 'string'],
            'tomador.email'            => ['nullable', 'email'],
            'itens'                    => ['required', 'array', 'min:1'],
            'itens.*.codigo_item'      => ['required', 'string'],
            'itens.*.descricao'        => ['required', 'string'],
            'itens.*.aliquota'         => ['required', 'numeric'],
            'itens.*.situacao_tributaria' => ['required', 'integer'],
            'itens.*.valor'            => ['required', 'numeric', 'min:0'],
            'itens.*.codigo_local_prestacao' => ['required', 'string'],
            'itens.*.cnae'             => ['nullable', 'string'],
        ];
    }
}
```

### Controller

```php
// app/Http/Controllers/Api/NfseController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\EmitirNfseRequest;
use App\Models\NotaFiscal as NotaFiscalModel;
use App\Services\NotaFiscalService;
use DanielBBarcelos\NotasFiscais\Data\Nfse\Cancelamento;
use DanielBBarcelos\NotasFiscais\Pdf\{ComprovanteNfse, Emitente};
use Illuminate\Http\Request;

class NfseController extends Controller
{
    public function __construct(private NotaFiscalService $service) {}

    public function emitir(EmitirNfseRequest $request)
    {
        $tenant = $request->user()->tenant;
        $nota   = $this->montarNotaServico($request, $tenant);   // ver seção 6

        $emitida = $this->service->emitir($tenant, $nota);

        $registro = NotaFiscalModel::create([
            'tenant_id'          => $tenant->id,
            'numero'             => $emitida->numero,
            'serie'              => $emitida->serie,
            'data'               => $emitida->data,
            'hora'               => $emitida->hora,
            'situacao'           => $emitida->situacao?->value,
            'codigo_verificacao' => $emitida->codigoVerificacao,
            'link'               => $emitida->link,
            'bruto'              => $emitida->bruto,
            'payload'            => $request->validated(),        // p/ regerar o PDF depois
        ]);

        return response()->json([
            'id'                 => $registro->id,
            'numero'             => $emitida->numero,
            'serie'              => $emitida->serie,
            'situacao'           => $emitida->situacao?->name,     // 'Emitida'
            'codigo_verificacao' => $emitida->codigoVerificacao,
            'link'               => $emitida->link,
        ], 201);
    }

    public function cancelar(Request $request, NotaFiscalModel $nota)
    {
        $request->validate(['motivo' => ['required', 'string', 'min:5']]);
        $tenant = $request->user()->tenant;

        $emitida = $this->service->cancelar($tenant, new Cancelamento(
            numero: $nota->numero,
            serie:  $nota->serie,
            motivo: $request->string('motivo'),
        ));

        $nota->update(['situacao' => $emitida->situacao?->value]);

        return response()->json(['situacao' => $emitida->situacao?->name]); // 'Cancelada'
    }

    public function mostrar(Request $request, NotaFiscalModel $nota)
    {
        $tenant  = $request->user()->tenant;
        $emitida = $this->service->consultar(
            $tenant, $nota->numero, $nota->serie, $tenant->nfseCredencial->codigo_ibge
        );

        return response()->json([
            'numero'   => $emitida->numero,
            'situacao' => $emitida->situacao?->name,
            'link'     => $emitida->link,
        ]);
    }

    public function porAutenticidade(Request $request, string $codigo)
    {
        $emitida = $this->service->consultarPorAutenticidade($request->user()->tenant, $codigo);

        return response()->json(['numero' => $emitida->numero, 'situacao' => $emitida->situacao?->name]);
    }

    public function comprovante(Request $request, NotaFiscalModel $nota)
    {
        // ver seção 9
    }
}
```

---

## 8. Persistência da nota emitida

Guarde o retorno (`NotaEmitida`) para não depender de nova consulta ao provedor. Salve também o
`payload` original, útil para **regerar o PDF** do comprovante depois.

### Migration

```php
// database/migrations/xxxx_create_notas_fiscais_table.php
Schema::create('notas_fiscais', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
    $table->unsignedInteger('numero')->nullable();
    $table->unsignedInteger('serie')->nullable();
    $table->string('data')->nullable();                  // 'dd/mm/aaaa'
    $table->string('hora')->nullable();                  // 'HH:MM:SS'
    $table->unsignedTinyInteger('situacao')->nullable(); // SituacaoNota: 1=Emitida, 2=Cancelada
    $table->string('codigo_verificacao')->nullable();
    $table->text('link')->nullable();
    $table->json('bruto')->nullable();                   // retorno bruto do provedor
    $table->json('payload')->nullable();                 // request original (p/ regerar PDF)
    $table->timestamps();
});
```

### Model

```php
// app/Models/NotaFiscal.php
namespace App\Models;

use DanielBBarcelos\NotasFiscais\Enums\SituacaoNota;
use Illuminate\Database\Eloquent\Model;

class NotaFiscal extends Model
{
    protected $fillable = [
        'tenant_id', 'numero', 'serie', 'data', 'hora',
        'situacao', 'codigo_verificacao', 'link', 'bruto', 'payload',
    ];

    protected $casts = [
        'situacao' => SituacaoNota::class,   // enum backed do próprio pacote
        'bruto'    => 'array',
        'payload'  => 'array',
    ];
}
```

> `NotaEmitida` expõe (readonly): `numero`, `serie`, `data`, `hora`, `situacao` (`SituacaoNota`),
> `codigoVerificacao`, `link`, `bruto`. Métodos `->emitida()` e `->cancelada()` são atalhos sobre
> `situacao`.

> **Guarde o `bruto`.** No ABRASF ele contém `xml_response` (a resposta do provedor), de onde a
> exportação extrai o **XML oficial assinado** da nota — ver [seção 10](#10-exportação-de-arquivos-xmltxt).
> Reconstrua o `NotaEmitida` com o `bruto` salvo para que `xmlNota()` devolva o documento oficial em
> vez do RPS local.

---

## 9. PDF do comprovante

O PDF **oficial** do município é protegido por captcha e não é automatizável. O pacote gera um
**comprovante próprio** (dompdf, PHP puro) a partir dos dados canônicos, deixando explícito que
**não é o documento fiscal oficial** e apontando o **link de autenticidade** (que vem em
`NotaEmitida::link`, dependente do `link_template` configurado — ver README).

```php
public function comprovante(Request $request, NotaFiscalModel $nota)
{
    $tenant = $request->user()->tenant;

    // Reconstrói os DTOs a partir do payload salvo (mesma montagem da seção 6):
    $dados   = $this->montarNotaServicoDePayload($nota->payload, $tenant);
    $emitida = $this->emitidaDoRegistro($nota);   // new NotaEmitida(numero: ..., ...)

    $prestador = new \DanielBBarcelos\NotasFiscais\Data\Nfse\Prestador(
        cpfCnpj:            $tenant->nfseCredencial->cpf_cnpj,
        codigoMunicipio:    $tenant->nfseCredencial->codigo_ibge,
        inscricaoMunicipal: $tenant->nfseCredencial->inscricao_municipal,
    );

    $bytes = ComprovanteNfse::gerar($dados, $emitida, $prestador, new Emitente(
        nome: $tenant->razao_social,
        logo: $tenant->logo_path,   // caminho ABSOLUTO (png, jpg, gif, svg) — opcional
    ));

    return response()->streamDownload(
        fn () => print($bytes),
        "nfse-{$nota->numero}.pdf",
        ['Content-Type' => 'application/pdf'],
    );
}
```

Assinaturas:

```php
ComprovanteNfse::gerar(NotaServico $dados, NotaEmitida $emitida, ?Prestador $prestador = null, ?Emitente $emitente = null): string  // bytes
ComprovanteNfse::salvar(string $caminho, NotaServico $dados, NotaEmitida $emitida, ?Prestador $prestador = null, ?Emitente $emitente = null): string  // retorna o caminho
```

Requer `dompdf/dompdf` instalado (seção 2). Sem `Emitente`, o comprovante usa placeholders no
cabeçalho.

---

## 10. Exportação de arquivos (XML/TXT)

Reproduz o menu **Download** do Atende.Net: **XML IPM**, **XML Abrasf** e **TXT**. O ponto
importante: o Web Service **não devolve** esses arquivos prontos — o pacote os **gera localmente** a
partir da nota enviada (`NotaServico`) e do retorno da emissão (`NotaEmitida`). Por isso os métodos
recebem os DTOs, exatamente como o PDF da seção 9.

| Item do menu | Método | O que sai |
|---|---|---|
| Download › XML IPM / XML Abrasf | `xmlNota($nota, $emitida)` | XML nativo do provedor |
| Download › TXT | `txtExportacao($nota, $emitida)` | arquivo posicional (NT 65/2020) |
| Download › PDF / Impressão | — | é o `NotaEmitida::link` (seção 9) |
| E-mail · Anexos · Consulta Nota Nacional | — | recursos só do portal, sem endpoint no WebService |

**Semântica do `xmlNota()`:**

- **ABRASF** — se o `$emitida` passado trouxer o XML oficial assinado pela prefeitura (no
  `bruto['xml_response']`, presente no retorno de emissão/consulta), devolve **esse XML oficial**;
  senão, monta o `<Rps>`/declaração a partir de `$nota`. Guarde o `bruto` (seção 8) para obter o
  documento oficial.
- **REST proprietário** — sempre gera o `<nfse>` do IPM a partir de `$nota` (esse padrão não tem XML
  assinado; o `$emitida` é ignorado).

**Endpoint de download** — reconstrói os DTOs do `payload`/registro salvo (igual ao PDF) e faz o
stream do arquivo:

```php
// routes/api.php
Route::get('/nfse/{nota}/download/{formato}', [NfseController::class, 'download'])
    ->whereIn('formato', ['xml', 'txt']);

// app/Http/Controllers/NfseController.php
public function download(Request $request, NotaFiscalModel $nota, string $formato)
{
    $tenant = $request->user()->tenant;

    // Mesma reconstrução da seção 9 (PDF):
    $dados   = $this->montarNotaServicoDePayload($nota->payload, $tenant);
    $emitida = $this->emitidaDoRegistro($nota);   // inclua o `bruto` salvo p/ o XML oficial ABRASF

    [$conteudo, $mime, $ext] = match ($formato) {
        'xml' => [$this->service->xmlNota($tenant, $dados, $emitida),      'application/xml', 'xml'],
        'txt' => [$this->service->txtExportacao($tenant, $dados, $emitida), 'text/plain',     'txt'],
    };

    return response()->streamDownload(
        fn () => print($conteudo),
        "nfse-{$nota->numero}.{$ext}",
        ['Content-Type' => $mime],
    );
}
```

**Arquivar no momento da emissão** (alternativa a gerar sob demanda): logo após `emitir()`, persista
os artefatos no `Storage` para não reconstruir DTOs depois.

```php
$emitida = $this->service->emitir($tenant, $nota);

Storage::disk('nfse')->put("{$emitida->numero}.xml", $this->service->xmlNota($tenant, $nota, $emitida));
Storage::disk('nfse')->put("{$emitida->numero}.txt", $this->service->txtExportacao($tenant, $nota, $emitida));
```

> Drivers que não implementam `ExportaArquivos` fazem o service lançar
> `OperacaoNaoSuportadaException` (mapeada para HTTP 400 no handler da seção 5). Os dois drivers IPM
> suportam; a checagem protege provedores futuros.

O **TXT** segue o layout posicional de largura fixa da Nota Técnica IPM 65/2020 — registro **10**
(documento), um **20** por item e **30** (tomador), terminados em CRLF. Formato único do IPM,
idêntico nos dois drivers.

---

## 11. Fluxo ponta a ponta

```
POST /api/nfse
   │
   ├─ EmitirNfseRequest valida o payload
   │
   ├─ NfseController::emitir
   │     ├─ monta NotaServico (DTOs)                        (seção 6)
   │     ├─ NotaFiscalService::emitir(tenant, nota)
   │     │     └─ NotaFiscal::build($tenant->config)->nfse()->emitir($nota)
   │     │            └─ driver traduz p/ XML/SOAP e chama o município
   │     ├─ persiste NotaEmitida em notas_fiscais           (seção 8)
   │     └─ responde 201 { numero, codigo_verificacao, link, situacao }
   │
   └─ erro do provedor → NotaFiscalApiException → 422 { codigo, mensagem, corpo }  (seção 5)

GET /api/nfse/{id}/comprovante
   └─ reconstrói DTOs do payload salvo → ComprovanteNfse::gerar → stream PDF        (seção 9)

GET /api/nfse/{id}/download/{xml|txt}
   └─ reconstrói DTOs (com o `bruto`) → xmlNota()/txtExportacao() → stream arquivo  (seção 10)
```

**Trocar de driver por tenant** é só mudar a config: `driver: 'ipm'` + `cidade` (TOM) em vez de
`'ipm-abrasf'` + `codigo_ibge` + `inscricao_municipal`. Nada no service/controller muda — o contrato
`NfseGateway` é o mesmo.

---

## 12. Troubleshooting

| Sintoma | Causa provável | Onde olhar |
|---|---|---|
| `401 "Acesso Negado"` | Senha não é a **do WebService** (difere da do portal), CNPJ não habilitado p/ WebService, ou CNPJ×município descasando | `->corpo` da `NotaFiscalApiException`; README, seção ABRASF |
| Timeout ou bloqueio **só a partir de servidor fora do Brasil** | Município aceita apenas IP nacional | Configure `proxy` na credencial do tenant (seção 3); confira antes que não é `401` de credencial |
| `L1029` | **Competência** retroativa | Use a data atual em `competencia` |
| `L1099` | `codigoItemListaServico` em **formato errado** | Use pontuado: `01.01.01` |
| `L1003` | Código do item **não vinculado** ao cadastro econômico | Ajuste no cadastro do prestador na prefeitura |
| `OperacaoNaoSuportadaException` | Documento/operação não implementado pelo driver (ex.: exportação num driver sem `ExportaArquivos`) | Confira o que o driver suporta (seções 1 e 10) |
| `xmlNota()` devolve o RPS, não o XML oficial | `NotaEmitida` reconstruído **sem** o `bruto['xml_response']` | Persista e recarregue o `bruto` (seções 8 e 10) |

Todo erro do provedor chega como `NotaFiscalApiException` com `->codigo`, `->corpo` (retorno
parseado) e `->statusHttp` — logue o `->corpo` para diagnosticar rápido.

---

> Dúvidas sobre os detalhes fiscais/IPM (qual driver, gotchas de cadastro, link do PDF): veja o
> **[README](../README.md)**. Este guia cobre a **integração na sua API**.
