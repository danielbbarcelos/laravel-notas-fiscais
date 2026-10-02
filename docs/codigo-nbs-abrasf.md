# Código NBS no ABRASF (erro E351)

> Vale para o driver `ipm-abrasf`. O driver REST proprietário (`ipm`, NTE 35/2021) não tem
> esse campo no layout e ignora o valor.

## O que aconteceu

Emissões que sempre funcionaram passaram a voltar com:

```
[ipm-abrasf] Erro E351: Código NBS não informado
Correção: Informe o código NBS conforme padrão da Receita Federal do Brasil
```

O campo `<CodigoNbs>` existe no `tcDadosServico` do ABRASF com ocorrência **1-1**
(obrigatório) desde a **versão 2.03** do layout, e o pacote nunca o enviava. Não é
regressão: é um campo que faltava desde o início e que o município só agora passou a
validar. A referência está em `docs/ipm-atende.net/Web Service para Testes de Nota Fiscal
Eletrônica de Serviço - Padrão ABRASF.pdf` (tabela de erros e Tabela 13), e o mesmo campo
aparece como `<codigo_nbs>` no layout da reforma tributária.

Erros correlatos da mesma família:

| Código | Significado |
|---|---|
| `E351` | NBS não informado |
| `E352` | NBS inválido (precisa ter nove caracteres) |
| `L1091` | Lista de Serviço × NBS × Indicador de Operação incompatíveis |
| `L1148` | Lista de serviço e NBS que não se correspondem |

## O que é o código NBS

Nomenclatura Brasileira de Serviços, Intangíveis e Outras Operações que Produzam Variações
no Patrimônio, criada pela Receita Federal. São **nove dígitos**, lidos da esquerda para a
direita:

| Posição | Significado |
|---|---|
| 1º | sempre `1`, indicando que o código é de serviço/intangível |
| 2º e 3º | capítulo da NBS |
| 4º e 5º | posição dentro do capítulo |
| 6º e 7º | subposições de primeiro e de segundo nível |
| 8º | item |
| 9º | subitem (`0` quando o item não é desdobrado) |

Exemplos: `1.0119.10.00` (construção de estruturas de prédios), `1.0905.91.00` (consultoria
financeira), `1.1403.21.10` (engenharia de projetos de construção residencial).

A lista oficial é o anexo `Anexo_B-NBS2-Lista_Serviço_Nacional-SNNFSe`. O NBS tem que ser
**coerente com o `codigoItemListaServico`** da LC 116/2003, senão a recusa vem como `L1091`
ou `L1148` em vez de `E351`.

## Como usar

### Por item

```php
use DanielBBarcelos\NotasFiscais\Data\Nfse\ItemServico;

new ItemServico(
    codigoItemListaServico: '01.01.01',
    descritivo: 'Desenvolvimento de software sob encomenda',
    aliquota: 2.0,
    situacaoTributaria: SituacaoTributaria::TributadaIntegralmente,
    valorTributavel: Valor::reais('1000.00'),
    codigoLocalPrestacao: '3152501',
    codigoCnae: '6201501',
    codigoNbs: '1.1403.21.10',   // com ou sem pontuação
);
```

A pontuação é opcional: o mapper normaliza para os nove dígitos que o XSD espera
(`1.1403.21.10` sai no XML como `<CodigoNbs>114032110</CodigoNbs>`).

### Default do emissor (o caso comum)

Na prática cada empresa usa um ou dois NBS fixos, então não vale repetir o código em toda
chamada. Configure o padrão no driver:

```dotenv
IPM_ABRASF_CODIGO_NBS=1.0105.10.00
```

```php
// config/notas-fiscais.php
'ipm-abrasf' => [
    // ...
    'codigo_nbs' => env('IPM_ABRASF_CODIGO_NBS'),
],
```

Em multi-tenant, o mesmo vale na credencial injetada em runtime:

```php
NotaFiscal::build([
    'driver'      => 'ipm-abrasf',
    'base_url'    => $tenant->abrasfUrl,
    'cpf_cnpj'    => $tenant->cnpj,
    'senha'       => decrypt($tenant->abrasfSenha),
    'codigo_ibge' => $tenant->codigoIbge,
    'codigo_nbs'  => $tenant->codigoNbs,
])->nfse()->emitir($nota);
```

### Precedência

1. `ItemServico::codigoNbs`, quando informado;
2. `notas-fiscais.drivers.ipm-abrasf.codigo_nbs`;
3. nenhum dos dois: a tag é **omitida** do XML.

A omissão é deliberada, para não quebrar municípios que ainda rodam um layout anterior ao
2.03 e não esperam o campo. Se o seu município exige o NBS, a consequência de deixar os dois
vazios é justamente o `E351` voltando do servidor.

Código com quantidade de dígitos diferente de nove é recusado localmente, antes da
requisição, com `InvalidArgumentException` ("Código NBS inválido"), em vez de gastar uma ida
ao servidor para receber `E352`.

## Onde isso está no código

| Arquivo | Papel |
|---|---|
| `src/Data/Nfse/ItemServico.php` | campo `codigoNbs` no DTO canônico |
| `src/Drivers/IpmAbrasf/Mappers/DeclaracaoMapper.php` | `nbs()` resolve e normaliza; a tag sai entre `CodigoCnae` e `Discriminacao`, que é a ordem do `sequence` do XSD |
| `src/Drivers/IpmAbrasf/IpmAbrasfProvedor.php` | lê `codigo_nbs` da config e injeta no mapper |
| `config/notas-fiscais.php` | chave `codigo_nbs` do driver `ipm-abrasf` |
| `tests/Abrasf/IpmAbrasfTest.php` | cobre item explícito, default da config, omissão e código inválido |

A ordem das tags não é detalhe de estilo: `tcDadosServico` é um `sequence`, então inverter
`CodigoNbs` e `Discriminacao` derruba a validação do XSD antes de qualquer regra fiscal.
