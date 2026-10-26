# laravel-notas-fiscais

[![Latest Version on Packagist](https://img.shields.io/packagist/v/danielbbarcelos/laravel-notas-fiscais.svg)](https://packagist.org/packages/danielbbarcelos/laravel-notas-fiscais)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP Version](https://img.shields.io/badge/php-%5E8.2-8892BF.svg)](https://php.net)
[![Laravel](https://img.shields.io/badge/laravel-11%2B-FF2D20.svg)](https://laravel.com)

> Repositório: **[github.com/danielbbarcelos/laravel-notas-fiscais](https://github.com/danielbbarcelos/laravel-notas-fiscais)**

Emissão de notas fiscais de serviço (NFS-e) sob um **contrato único**, com **drivers por provedor**. Laravel 11+.

Hoje implementa o provedor **IPM Atende.Net** (NFS-e municipal) em dois padrões e o
**Sistema Nacional NFS-e** (Emissor Nacional / Sefin Nacional). A arquitetura de
contracts é genérica para acomodar novos provedores — e, futuramente, outros tipos de
documento (NF-e, NFC-e).

## Qual driver usar (IPM)

O Atende.Net expõe a NFS-e em **dois padrões diferentes**, por município:

| Driver | Padrão | Serviço | Quando usar |
|---|---|---|---|
| `ipm` | REST proprietário (NTE 35/2021) | `WNERestServiceNFSe` | Municípios com o REST `<nfse>`/multipart |
| `ipm-abrasf` | **ABRASF 2.04 (SOAP)** | `WNENotaFiscalEletronicaNfe` | Municípios ABRASF — ex.: **Pouso Alegre/MG** |

Ambos autenticam por **login/senha (Basic)** — sem certificado. Para descobrir qual
o município usa, teste o endpoint: se o REST responder `503 "Serviço não disponível"`
e o SOAP responder, use `ipm-abrasf`. Note que o ABRASF identifica o município pelo
**código IBGE** (7 dígitos), enquanto o REST usa o **código TOM**.

### Notas práticas do ABRASF (validado em produção — Pouso Alegre/MG)

Emissão e cancelamento reais já validados ponta a ponta. Pontos que costumam barrar:

- **Autenticação**: a senha é a **específica do WebService** (pode diferir da senha
  do portal). `401 "Acesso Negado"` = credencial/login rejeitado.
- **Competência (`NotaServico::competencia`)**: use a **data atual**; competência
  retroativa é recusada (`L1029`) salvo autorização do município.
- **`ItemServico::codigoItemListaServico`**: deve estar no formato pontuado
  (`99.99`, `99.99.99` ou `99.99.99.999`) e **vinculado ao cadastro econômico** do
  prestador. Ex.: o código `10101` do portal vira `01.01.01` (zero à esquerda). Em
  formato errado → `L1099`; código não vinculado → `L1003`.
- **CNAE (`ItemServico::codigoCnae`)** e item devem ser **coerentes** entre si.
- **NBS (`ItemServico::codigoNbs`)**: obrigatório desde o layout **2.03**; sem ele a emissão
  volta com `E351`. São nove dígitos da Receita Federal (ex.: `1.1403.21.10`), coerentes com
  o item da lista de serviço. Como quase toda empresa usa um NBS fixo, o normal é configurar
  o padrão em `IPM_ABRASF_CODIGO_NBS` e só sobrescrever por item quando precisar. Detalhes,
  precedência e tabela de erros correlatos no
  **[guia do código NBS](docs/codigo-nbs-abrasf.md)**.
- **Modo teste**: o `<EnvioTeste>` exige a operação de lote síncrono, que em alguns
  municípios (Pouso Alegre) tem bug no servidor. Para validar, prefira **emitir real
  + cancelar** (a operação `GerarNfse`, usada na emissão padrão, é a estável).
- **Link do PDF**: o `GerarNfseResposta` desses municípios **não** retorna o link.
  Configure `link_template` (config `notas-fiscais.drivers.ipm-abrasf.link_template`,
  env `IPM_ABRASF_LINK_TEMPLATE`) com a URL de consulta/autenticidade do Atende.Net e o
  placeholder `{codigo}` (e opcionalmente `{numero}`); o driver preenche
  `NotaEmitida::link` automaticamente. Para achar a URL e o `{ID}` do serviço, abra uma
  NFS-e na consulta de autenticidade do portal e copie a URL, trocando o código por
  `{codigo}`. Ex.:
  `https://{cidade}.atende.net/?pg=autoatendimento#!/tipo/servico/valor/213/padrao/1/load/1/identificador/{codigo}`
  (`valor/213` é o serviço de autenticidade de NFS-e, global no Atende.Net). Confirmado
  para **Pouso Alegre/MG** (abre a nota com botões XML IPM / XML Abrasf / Imprimir):
  `https://pousoalegre.atende.net/autoatendimento/servicos/consulta-de-autenticidade-de-nota-fiscal-eletronica-nfse/detalhar/1/identificador/{codigo}`

## Padrão nacional (driver `nacional`)

O Sistema Nacional NFS-e recebe a **DPS** (Declaração de Prestação de Serviço, leiaute
**v1.01**) pela API da Sefin Nacional e devolve a NFS-e na hora. Tudo muda em relação ao IPM:

| | IPM (`ipm`, `ipm-abrasf`) | Nacional (`nacional`) |
|---|---|---|
| Autenticação | login/senha (Basic) | **mTLS com certificado A1** (ICP-Brasil) |
| Assinatura | não há | XMLDSig (RSA-SHA1, C14N) na DPS e nos eventos |
| Transporte | SOAP/multipart | REST + JSON, XML em GZip+Base64 (`dpsXmlGZipB64`) |
| Quem numera | o município | **o emitente** (série + `numeroDps`) |
| Código do serviço | LC116 `NN.NN.NN` | `cTribNac` de **6 dígitos** (item, subitem, desdobro) |
| Identifica a nota | número + código de verificação | **chave de acesso** (50 dígitos) |
| Cancelamento | operação própria | **evento** `e101101` em `/nfse/{chave}/eventos` |
| Homologação | `<EnvioTeste>` (só valida) | **produção restrita** (gera NFS-e sem valor fiscal) |

```php
$gateway = NotaFiscal::build([
    'driver'            => 'nacional',
    'certificado'       => base64_encode(file_get_contents('a1.pfx')), // ou 'certificado_arquivo' => '/caminho/a1.pfx'
    'certificado_senha' => $senha,
    'cpf_cnpj'          => '24100499000177',
    'codigo_ibge'       => '3152501',  // município emissor
    'simples_nacional'  => 1,          // 1 não optante, 2 MEI, 3 ME/EPP
])->nfse();

$emitida = $gateway->emitir(new NotaServico(
    serie: 1,
    numeroDps: $minhaSequencia,        // obrigatório: único por CNPJ + série
    dataFatoGerador: '30/06/2026',
    valorTotal: Valor::reais('1000.00'),
    tomador: $tomador,
    itens: [new ItemServico(codigoItemListaServico: '01.07.01', /* ... */)],
    teste: false,                      // true = produção restrita (tpAmb 2)
));

$emitida->codigoVerificacao; // a chave de acesso, que consulta e cancela
$emitida->link;              // consulta pública: nfse.gov.br/ConsultaPublica
$emitida->bruto['xml_nfse']; // a NFS-e oficial assinada pela Sefin

$gateway->consultarPorAutenticidade($chave);
$gateway->cancelar(new Cancelamento(numero: 126, serie: 1, chaveAcesso: $chave,
    motivo: 'Valor do serviço informado errado')); // 15 a 255 caracteres
```

O que o driver já resolve sozinho, e por quê:

- **Reenvio da mesma DPS (E0014).** Se a primeira tentativa passou e a resposta se perdeu,
  reenviar o mesmo `numeroDps` recupera a NFS-e já gerada (`GET /dps/{id}` e `GET /nfse/{chave}`)
  em vez de falhar. Quando a nota recuperada tem outro valor ou outro tomador, o número já foi
  usado por outra emissão do mesmo CNPJ e o driver lança, em vez de devolver a nota alheia.
- **Nome e IM do prestador não vão na DPS** (E0121 e E0120): a Sefin os conhece pelo cadastro
  nacional. Ligue `informar_inscricao_municipal` só se o município registrou a IM lá.
- **Alíquota (`pAliq`).** Com o município de incidência conveniado, a Sefin aplica a alíquota
  parametrizada e **rejeita** a informada; sem convênio, ela é obrigatória. O ME/EPP que apura
  ISS pelo Simples só informa quando há retenção. Ajuste `municipio_conveniado` se for o caso.
- **Total de tributos (`totTrib`).** Não optante vai com percentuais (0,00 quando não
  configurados); ME/EPP com `tributos_simples` vai em `pTotTribSN`; MEI vai com `indTotTrib`.
- **`dhEmi` um minuto para trás**, porque a Sefin rejeita emissão posterior ao processamento.

### DANFSe (NT 008/2026)

Desde 03/08/2026 a API do ADN que devolvia o DANFSe pronto está suspensa: o emissor gera o PDF.
`Pdf\Danfse` segue o Anexo I da Nota Técnica SE/CGNFS-e nº 008/2026 (v1.02) e lê **só o XML da
NFS-e** devolvido pela Sefin, como a NT exige:

```php
use DanielBBarcelos\NotasFiscais\Pdf\Danfse;

$pdf = Danfse::gerar($emitida->bruto['xml_nfse'], [
    'cancelada' => false,      // marca d'água CANCELADA (o cancelamento é evento, não está no XML)
    'substituida' => false,    // marca d'água SUBSTITUÍDA
    'canhoto' => false,        // bloco de canhoto, opcional
    'municipio' => fn (string $ibge) => $nomes[$ibge] ?? null, // o XML traz só o código do tomador
]);
```

Uma página A4, QR Code da consulta pública, "NFS-e SEM VALIDADE JURÍDICA" quando `tpAmb` = 2, blocos
sem dados reduzidos a uma linha, e descrição e informações complementares cortadas com "(...)" pela
altura disponível, para nunca passar de uma página. Requer `dompdf/dompdf` e `bacon/bacon-qr-code`.

### Parâmetros municipais

```php
$gateway->consultarConvenio('3152501');           // ['conveniado' => true|false|null, 'bruto' => [...]]
$gateway->consultarAliquota('3152501', '010701'); // ['aliquota' => 2.0|null, 'bruto' => [...]]
$gateway->consultarAliquota('3152501', '010701', '2026-11-03'); // numa competência específica
```

Os parâmetros ficam no **ADN** (`adn.nfse.gov.br/parametrizacao`, e `adn.producaorestrita…` na
produção restrita), não na Sefin: os caminhos `/parametros_municipais/...` do guia de 2025 respondem
404. O convênio vem em `/{cMun}/convenio` (`parametrosConvenio.aderenteAmbienteNacional`: 1, 0 ou
-1, que é "não informado") e a alíquota em `/{cMun}/{01.07.01.000}/{AAAA-MM-DD}/aliquota`, com o
código pontuado e o complemento municipal `000`. As URLs podem ser trocadas por `parametros_url` e
`parametros_url_homologacao` na config. O JSON original volta em `bruto`.

Fora do pacote por enquanto: grupo IBS/CBS da reforma tributária (opcional no leiaute v1.01),
substituição de NFS-e e o TXT de exportação, que é um layout do IPM. Consulta por número não existe neste padrão:
`consultar()` lança e indica `consultarPorAutenticidade()`.

Leiaute, regras de negócio e XSD oficiais em [`docs/nfse-nacional/`](docs/nfse-nacional/). A
OpenSSL 3 do sistema precisa permitir assinatura SHA-1 (o padrão do Ubuntu permite).

## Instalação

```bash
composer require danielbbarcelos/laravel-notas-fiscais
php artisan vendor:publish --tag=notas-fiscais-config
```

> **Integrando numa API?** Veja o **[Guia de integração Laravel](docs/integracao-laravel.md)** —
> passo a passo multi-tenant/SaaS com endpoints REST, service, persistência e PDF.

## Configuração

`config/notas-fiscais.php` (ou via `.env`):

```dotenv
NFSE_DRIVER=ipm
IPM_BASE_URL=https://riodosul.atende.net/atende.php?pg=rest&service=WNERestServiceNFSe&cidade=padrao
IPM_CPF_CNPJ=12345678000199   # login do Web Service (prestador)
IPM_SENHA=sua-senha
IPM_CIDADE_TOM=8055           # código TOM do município do prestador
```

Para o driver ABRASF:

```dotenv
NFSE_DRIVER=ipm-abrasf
IPM_ABRASF_URL=https://pousoalegre.atende.net/?pg=services&service=WNENotaFiscalEletronicaNfe
IPM_ABRASF_CNPJ=24100499000177
IPM_ABRASF_SENHA=sua-senha-do-webservice
IPM_ABRASF_INSCRICAO_MUNICIPAL=84592
IPM_ABRASF_CODIGO_IBGE=3152501
IPM_ABRASF_CODIGO_NBS=1.0105.10.00   # obrigatório desde o layout 2.03 (erro E351)
```

> A URL do Web Service varia por município. Solicite acesso no Portal do Cidadão
> da prefeitura ("Emissão de NFS-e por WebService").

### Proxy de saída (servidor fora do Brasil)

Alguns municípios só respondem a requisições vindas de um IP brasileiro. Se a
aplicação roda fora do país, aponte o driver para um proxy no Brasil:

```dotenv
IPM_PROXY=http://usuario:senha@proxy-br.exemplo.com:3128
IPM_ABRASF_PROXY=socks5://proxy-br.exemplo.com:1080
```

Sem a variável, nada muda: a requisição sai direto do servidor. O valor é
repassado ao Guzzle, então vale qualquer esquema que o cURL suporte
(`http://`, `https://`, `socks5://`).

Dois avisos antes de montar essa infra:

- **Confirme que o bloqueio é geográfico.** Um `401 "Acesso Negado"` é problema
  de credencial e nenhum proxy resolve — veja a seção ABRASF acima.
- **O proxy vira ponto único de falha** no caminho da emissão. Se ele cair, as
  notas param e cada requisição fica pendurada até o `timeout` do driver.
  Hospedar a aplicação no Brasil elimina o problema em vez de contorná-lo.

## Uso

```php
use DanielBBarcelos\NotasFiscais\Facades\NotaFiscal;
use DanielBBarcelos\NotasFiscais\Data\Nfse\{NotaServico, ItemServico, Tomador};
use DanielBBarcelos\NotasFiscais\Data\Shared\{Valor, Endereco};
use DanielBBarcelos\NotasFiscais\Enums\{TipoTomador, SituacaoTributaria};

$nota = new NotaServico(
    serie: 1,
    dataFatoGerador: '15/01/2026',
    valorTotal: Valor::reais('1000.00'),
    tomador: new Tomador(
        tipo: TipoTomador::Juridica,
        identificacao: '12.345.678/0001-95',
        nomeRazaoSocial: 'Empresa Tomadora LTDA',
        endereco: new Endereco(logradouro: 'Rua das Flores', numero: '123', bairro: 'Centro', codigoMunicipio: '8055', cep: '89160-000'),
        email: 'tomador@exemplo.com.br',
    ),
    itens: [
        new ItemServico(
            codigoItemListaServico: '010700',
            descritivo: 'Desenvolvimento de software sob encomenda',
            aliquota: 3.0,
            situacaoTributaria: SituacaoTributaria::TributadaIntegralmente,
            valorTributavel: Valor::reais('1000.00'),
            codigoLocalPrestacao: '8055',
        ),
    ],
);

// Driver padrão (config) ou explícito:
$emitida = NotaFiscal::nfse()->emitir($nota);
$emitida = NotaFiscal::driver('ipm')->nfse()->emitir($nota);

$emitida->numero;             // 1293
$emitida->codigoVerificacao;  // 8357...913
$emitida->link;               // URL do PDF
$emitida->emitida();          // true
```

Cancelamento e consulta:

```php
use DanielBBarcelos\NotasFiscais\Data\Nfse\Cancelamento;

NotaFiscal::nfse()->cancelar(new Cancelamento(numero: 1293, serie: 1, motivo: 'Erro de digitação'));

NotaFiscal::nfse()->consultar(numero: 1293, serie: 1, cadastro: '8055');
NotaFiscal::nfse()->consultarPorAutenticidade('8357...913');
```

Erros do provedor lançam `NotaFiscalApiException` (com `->codigo` e `->corpo`);
documento não suportado lança `OperacaoNaoSuportadaException`. Ambas estendem
`NotaFiscalException`.

## Exportação de arquivos (XML e TXT)

Reproduz o menu **Download** do Atende.Net (XML IPM / XML Abrasf / TXT). Como o
Web Service **não devolve** esses arquivos prontos, o package os **gera
localmente** a partir da nota enviada (`NotaServico`) e do retorno da emissão
(`NotaEmitida`). Os gateways que suportam isso implementam o contrato
`ExportaArquivos`:

```php
use DanielBBarcelos\NotasFiscais\Contracts\ExportaArquivos;

$gw = NotaFiscal::driver('ipm-abrasf')->nfse();

if ($gw instanceof ExportaArquivos) {
    $xml = $gw->xmlNota($nota, $emitida);          // XML nativo do provedor
    $txt = $gw->txtExportacao($nota, $emitida);    // arquivo-texto (NT 65/2020)

    Storage::put("nfse/{$emitida->numero}.xml", $xml);
    Storage::put("nfse/{$emitida->numero}.txt", $txt);
}
```

- **`xmlNota()`** — no **ABRASF**, se `$emitida` trouxer o XML oficial assinado
  pela prefeitura (no `bruto['xml_response']` do retorno de emissão/consulta), ele
  é devolvido; senão monta o `<Rps>`/declaração a partir de `$nota`. No **REST**
  proprietário sempre gera o `<nfse>` do IPM (esse padrão não tem XML assinado).
- **`txtExportacao()`** — gera o layout posicional do IPM (Nota Técnica 65/2020:
  registros **10** documento, **20** por item, **30** tomador). Formato único do
  IPM, idêntico nos dois drivers.

> O **PDF** e a **Impressão** do menu correspondem ao `NotaEmitida::link` (ver
> abaixo). Já **E-mail**, **Anexos** e **Consulta Nota Nacional** são recursos do
> portal sem endpoint no Web Service — fora do escopo do package.

## Comprovante da NFS-e em PDF

O PDF **oficial** do município (link `/ged/r/{hash}` do Atende.Net) é gerado por um
endpoint **protegido por captcha** (Cloudflare Turnstile) na via pública — então não
é automatizável. Para anexar em e-mail / download, o pacote gera um **comprovante
próprio** em PDF a partir dos dados canônicos, sem captcha. O PDF deixa **explícito que
não é o documento fiscal oficial** e aponta, no topo, o **link de autenticidade** onde
a NFS-e oficial pode ser consultada e impressa (e que reflete o status atual):

```php
use DanielBBarcelos\NotasFiscais\Pdf\ComprovanteNfse;

$emitida = NotaFiscal::driver('ipm-abrasf')->nfse()->emitir($nota);

$bytes = ComprovanteNfse::gerar($nota, $emitida);              // bytes do PDF (anexar em e-mail)
ComprovanteNfse::salvar('/tmp/nfse-57.pdf', $nota, $emitida);  // salva em arquivo
```

Se a emissão usou o prestador da config (NotaServico sem prestador), passe-o no 3º
argumento. O 4º argumento (`Emitente`) coloca **logo e nome da empresa** no cabeçalho —
ambos opcionais (sem eles, o comprovante usa placeholders):

```php
use DanielBBarcelos\NotasFiscais\Pdf\Emitente;

ComprovanteNfse::salvar('/tmp/nfse-57.pdf', $nota, $emitida, $prestador, new Emitente(
    nome: 'Minha Empresa LTDA',
    logo: '/caminho/absoluto/logo.png',   // png, jpg, gif ou svg
));
```

Requer **dompdf** (dependência opcional, PHP puro, sem binário):

```bash
composer require dompdf/dompdf
```

## Multi-tenant / SaaS (credenciais em runtime)

O `.env` é só o default opcional (single-tenant). **Não guarde credenciais de
clientes no `.env`/config** num SaaS: a aplicação armazena com segurança (ex.:
coluna criptografada por tenant) e injeta em runtime.

```php
// Config completa do tenant, sem passar pelo .env (recomendado p/ SaaS):
NotaFiscal::build([
    'driver'   => 'ipm',
    'base_url' => $tenant->ipmUrl,
    'cpf_cnpj' => $tenant->cnpj,
    'senha'    => decrypt($tenant->ipmSenha),
    'cidade'   => $tenant->codigoTom,
    'proxy'    => $tenant->proxy,   // opcional; null = sai direto do servidor
])->nfse()->emitir($nota);

// Ou sobrepondo apenas alguns campos sobre a base nomeada:
NotaFiscal::driver('ipm', [
    'cpf_cnpj' => $tenant->cnpj,
    'senha'    => decrypt($tenant->ipmSenha),
])->nfse()->emitir($nota);
```

Instâncias resolvidas em runtime **não são cacheadas pelo nome** (sem vazamento
entre tenants), e a sessão (`PHPSESSID`) é isolada por credencial. Para uso fixo
single-tenant, `NotaFiscal::nfse()` segue lendo a config nomeada normalmente.

## Playground (validação visual)

Para validar campos e retornos numa tela, ligue a demo (mantenha **desligada em
produção**):

```dotenv
NFSE_DEMO=true
```

Isso registra a rota `GET/POST /notas-fiscais/demo` com um formulário que monta o
`NotaServico`, mostra o **XML gerado** e o **NotaEmitida** parseado (ou o erro). Um
toggle alterna entre:

- **Faked** — devolve os XMLs de exemplo dos docs (sucesso/erro/cancelada); valida
  o fluxo inteiro sem credenciais nem prefeitura;
- **IPM real** — usa as credenciais do `.env` (com `<nfse_teste>` por padrão).

Dentro do próprio repositório do pacote, sirva via Testbench:

```bash
NFSE_DEMO=true vendor/bin/testbench serve
# acesse http://127.0.0.1:8000/notas-fiscais/demo
```

> A demo vive em `routes/demo.php` + `resources/views/demo.blade.php` e só é
> carregada quando `notas-fiscais.demo` é `true`.

## Novos provedores

Implemente `Contracts\Provedor` + `Contracts\NfseGateway` e registre no boot:

```php
NotaFiscal::extend('meu-provedor', fn (array $config, string $nome) => new MeuProvedor($config, $nome));
```

## Testes

```bash
composer test
```

## Contribuindo

Issues e pull requests são bem-vindos em
[github.com/danielbbarcelos/laravel-notas-fiscais](https://github.com/danielbbarcelos/laravel-notas-fiscais).

## Licença

Distribuído sob a licença MIT. Veja [LICENSE](LICENSE).
