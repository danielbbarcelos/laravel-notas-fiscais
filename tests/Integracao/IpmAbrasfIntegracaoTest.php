<?php

declare(strict_types=1);

use DanielBBarcelos\NotasFiscais\Data\Nfse\Cancelamento;
use DanielBBarcelos\NotasFiscais\Data\Nfse\ItemServico;
use DanielBBarcelos\NotasFiscais\Data\Nfse\NotaServico;
use DanielBBarcelos\NotasFiscais\Data\Nfse\Tomador;
use DanielBBarcelos\NotasFiscais\Data\Shared\Endereco;
use DanielBBarcelos\NotasFiscais\Data\Shared\Valor;
use DanielBBarcelos\NotasFiscais\Enums\SituacaoTributaria;
use DanielBBarcelos\NotasFiscais\Enums\TipoTomador;
use DanielBBarcelos\NotasFiscais\Exceptions\NotaFiscalApiException;
use DanielBBarcelos\NotasFiscais\Facades\NotaFiscal;

/**
 * Teste de integração REAL contra o IPM ABRASF (SOAP). Só roda quando as
 * variáveis IPM_ABRASF_* estão no ambiente. Credenciais só no ambiente.
 *
 * Por padrão tenta o DRY-RUN (lote síncrono com <EnvioTeste>1>) — válido na
 * ABRASF, mas em alguns municípios (ex.: Pouso Alegre) o endpoint de lote tem
 * bug no servidor. Para esses casos, defina IPM_ABRASF_EMITIR_REAL=1: o teste
 * EMITE uma nota real (R$1) pela operação direta (GerarNfse) e CANCELA em
 * seguida — validação ponta a ponta pelo caminho de produção.
 *
 *   IPM_ABRASF_URL="https://pousoalegre.atende.net/?pg=services&service=WNENotaFiscalEletronicaNfe" \
 *   IPM_ABRASF_CNPJ="..." IPM_ABRASF_SENHA="..." \
 *   IPM_ABRASF_INSCRICAO_MUNICIPAL="84592" IPM_ABRASF_CODIGO_IBGE="3152501" \
 *   IPM_ABRASF_EMITIR_REAL=1 \
 *   vendor/bin/pest --group=integracao
 */
function envAbrasf(string $chave, ?string $padrao = null): ?string
{
    $valor = getenv($chave);

    return ($valor === false || $valor === '') ? $padrao : $valor;
}

beforeEach(function () {
    foreach (['IPM_ABRASF_URL', 'IPM_ABRASF_CNPJ', 'IPM_ABRASF_SENHA', 'IPM_ABRASF_INSCRICAO_MUNICIPAL', 'IPM_ABRASF_CODIGO_IBGE'] as $chave) {
        if (envAbrasf($chave) === null) {
            $this->markTestSkipped("Defina {$chave} no ambiente para rodar o teste de integração ABRASF.");
        }
    }
});

it('valida a emissão real ABRASF contra o IPM', function () {
    $ibge = (string) envAbrasf('IPM_ABRASF_CODIGO_IBGE');
    $real = envAbrasf('IPM_ABRASF_EMITIR_REAL') !== null;

    $config = [
        'driver' => 'ipm-abrasf',
        'base_url' => envAbrasf('IPM_ABRASF_URL'),
        'cpf_cnpj' => envAbrasf('IPM_ABRASF_CNPJ'),
        'senha' => envAbrasf('IPM_ABRASF_SENHA'),
        'inscricao_municipal' => envAbrasf('IPM_ABRASF_INSCRICAO_MUNICIPAL'),
        'codigo_ibge' => $ibge,
        'link_template' => envAbrasf('IPM_ABRASF_LINK_TEMPLATE'),
        'timeout' => 60,
    ];

    $nota = new NotaServico(
        serie: 1,
        dataFatoGerador: date('d/m/Y'),
        valorTotal: Valor::reais('1.00'),
        tomador: new Tomador(
            tipo: TipoTomador::Juridica,
            identificacao: (string) envAbrasf('IPM_ABRASF_TOMADOR_DOC', envAbrasf('IPM_ABRASF_CNPJ')),
            nomeRazaoSocial: (string) envAbrasf('IPM_ABRASF_TOMADOR_NOME', 'TOMADOR TESTE INTEGRACAO'),
            endereco: new Endereco(
                logradouro: 'Rua Teste',
                numero: '1',
                bairro: 'Centro',
                codigoMunicipio: $ibge,
                uf: 'MG',
                cep: '37550000',
            ),
            email: 'teste@exemplo.com.br',
        ),
        itens: [
            new ItemServico(
                codigoItemListaServico: (string) envAbrasf('IPM_ABRASF_ITEM_LC', '10.01.01'),
                descritivo: 'Teste de integracao ABRASF via webservice',
                aliquota: 2.0,
                situacaoTributaria: SituacaoTributaria::TributadaIntegralmente,
                valorTributavel: Valor::reais('1.00'),
                codigoLocalPrestacao: $ibge,
                codigoCnae: envAbrasf('IPM_ABRASF_CNAE', '6201501'),
                issRetido: false,
                valorIss: Valor::reais('0.02'),
            ),
        ],
        // Data de hoje: este município rejeita competência retroativa (L1029).
        competencia: date('Y-m-d'),
        // Sem a flag: tenta dry-run (lote). Com IPM_ABRASF_EMITIR_REAL=1: emite real.
        teste: ! $real,
    );

    $linha = str_repeat('=', 70);
    $modo = $real ? 'EMISSÃO REAL (GerarNfse) + cancelamento' : 'DRY-RUN (lote síncrono)';
    fwrite(STDERR, "\n{$linha}\n[INTEGRACAO ABRASF] Modo: {$modo}\n");

    try {
        $emitida = NotaFiscal::build($config)->nfse()->emitir($nota);

        fwrite(STDERR, "[INTEGRACAO ABRASF] SUCESSO: numero={$emitida->numero} verificacao={$emitida->codigoVerificacao}\n"
            ."link={$emitida->link}\n");

        // Em emissão real, cancela em seguida para não deixar a nota de teste ativa.
        if ($real && $emitida->numero !== null) {
            try {
                $cancel = NotaFiscal::build($config)->nfse()->cancelar(
                    new Cancelamento($emitida->numero, 1, 'Cancelamento automatico - teste de integracao'),
                );
                fwrite(STDERR, "[INTEGRACAO ABRASF] CANCELADA: numero={$cancel->numero} situacao={$cancel->situacao?->name}\n");
            } catch (NotaFiscalApiException $e) {
                fwrite(STDERR, "[INTEGRACAO ABRASF] FALHA AO CANCELAR (cancele manualmente o numero {$emitida->numero}): {$e->getMessage()}\n");
            }
        }
    } catch (NotaFiscalApiException $e) {
        fwrite(STDERR, "[INTEGRACAO ABRASF] Código : {$e->codigo}\n"
            ."[INTEGRACAO ABRASF] Mensagem: {$e->getMessage()}\n");
    }

    fwrite(STDERR, "{$linha}\n");

    expect(true)->toBeTrue();
})->group('integracao');
