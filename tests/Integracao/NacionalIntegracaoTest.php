<?php

declare(strict_types=1);

use DanielBBarcelos\NotasFiscais\Data\Nfse\Cancelamento;
use DanielBBarcelos\NotasFiscais\Data\Nfse\ItemServico;
use DanielBBarcelos\NotasFiscais\Data\Nfse\NotaServico;
use DanielBBarcelos\NotasFiscais\Data\Nfse\Tomador;
use DanielBBarcelos\NotasFiscais\Data\Shared\Valor;
use DanielBBarcelos\NotasFiscais\Enums\SituacaoTributaria;
use DanielBBarcelos\NotasFiscais\Enums\TipoTomador;
use DanielBBarcelos\NotasFiscais\Exceptions\NotaFiscalApiException;
use DanielBBarcelos\NotasFiscais\Facades\NotaFiscal;

/**
 * Teste de integração REAL contra a PRODUÇÃO RESTRITA do Sistema Nacional NFS-e
 * (a homologação do padrão: gera NFS-e de verdade, sem valor fiscal). Só roda
 * com as variáveis NFSE_NACIONAL_* no ambiente; o certificado nunca entra no repo.
 *
 * Emite uma nota de R$ 1,00 e a cancela em seguida (evento e101101).
 *
 *   NFSE_NACIONAL_CERTIFICADO="/caminho/para/certificado-a1.pfx" \
 *   NFSE_NACIONAL_CERTIFICADO_SENHA="..." \
 *   NFSE_NACIONAL_CNPJ="..." NFSE_NACIONAL_CODIGO_IBGE="3152501" \
 *   NFSE_NACIONAL_SIMPLES=1 NFSE_NACIONAL_COD_TRIB="010701" \
 *   NFSE_NACIONAL_NUMERO_DPS="$(date +%s)" \
 *   vendor/bin/pest --group=integracao --filter=nacional
 *
 * NFSE_NACIONAL_NUMERO_DPS precisa ser inédito para o CNPJ e a série
 * (NFSE_NACIONAL_SERIE, padrão 1): repetir devolve a nota já gerada (E0014).
 */
function envNacional(string $chave, ?string $padrao = null): ?string
{
    $valor = getenv($chave);

    return ($valor === false || $valor === '') ? $padrao : $valor;
}

beforeEach(function () {
    foreach (['NFSE_NACIONAL_CERTIFICADO', 'NFSE_NACIONAL_CERTIFICADO_SENHA', 'NFSE_NACIONAL_CNPJ', 'NFSE_NACIONAL_CODIGO_IBGE'] as $chave) {
        if (envNacional($chave) === null) {
            $this->markTestSkipped("Defina {$chave} no ambiente para rodar o teste de integração do padrão nacional.");
        }
    }
});

it('emite e cancela na produção restrita do padrão nacional', function () {
    $ibge = (string) envNacional('NFSE_NACIONAL_CODIGO_IBGE');

    $config = [
        'driver' => 'nacional',
        'ambiente' => 'homologacao',
        'certificado' => envNacional('NFSE_NACIONAL_CERTIFICADO'),
        'certificado_senha' => envNacional('NFSE_NACIONAL_CERTIFICADO_SENHA'),
        'cpf_cnpj' => envNacional('NFSE_NACIONAL_CNPJ'),
        'codigo_ibge' => $ibge,
        'simples_nacional' => envNacional('NFSE_NACIONAL_SIMPLES', '1'),
        'regime_apuracao_simples' => envNacional('NFSE_NACIONAL_APURACAO_SN', '1'),
        'tributos_simples' => envNacional('NFSE_NACIONAL_TRIBUTOS_SIMPLES'),
        'codigo_nbs' => envNacional('NFSE_NACIONAL_CODIGO_NBS'),
        'proxy' => envNacional('NFSE_NACIONAL_PROXY'),
        'timeout' => 60,
    ];

    $nota = new NotaServico(
        serie: (int) envNacional('NFSE_NACIONAL_SERIE', '1'),
        dataFatoGerador: date('d/m/Y'),
        valorTotal: Valor::reais('1.00'),
        tomador: new Tomador(
            tipo: TipoTomador::Juridica,
            identificacao: (string) envNacional('NFSE_NACIONAL_TOMADOR_DOC', envNacional('NFSE_NACIONAL_CNPJ')),
            nomeRazaoSocial: (string) envNacional('NFSE_NACIONAL_TOMADOR_NOME', 'TOMADOR TESTE INTEGRACAO'),
        ),
        itens: [
            new ItemServico(
                codigoItemListaServico: (string) envNacional('NFSE_NACIONAL_COD_TRIB', '010701'),
                descritivo: 'Teste de integracao do padrao nacional',
                aliquota: (float) envNacional('NFSE_NACIONAL_ALIQUOTA', '2'),
                situacaoTributaria: SituacaoTributaria::TributadaIntegralmente,
                valorTributavel: Valor::reais('1.00'),
                codigoLocalPrestacao: $ibge,
            ),
        ],
        competencia: date('Y-m-d'),
        teste: true,
        numeroDps: (int) envNacional('NFSE_NACIONAL_NUMERO_DPS', (string) time()),
    );

    $linha = str_repeat('=', 70);
    fwrite(STDERR, "\n{$linha}\n[INTEGRACAO NACIONAL] Produção restrita, DPS {$nota->numeroDps}\n");

    try {
        $emitida = NotaFiscal::build($config)->nfse()->emitir($nota);
    } catch (NotaFiscalApiException $e) {
        fwrite(STDERR, "[INTEGRACAO NACIONAL] REJEITADA {$e->codigo}: {$e->getMessage()}\n{$linha}\n");

        throw $e;
    }

    fwrite(STDERR, "[INTEGRACAO NACIONAL] EMITIDA: numero={$emitida->numero} chave={$emitida->codigoVerificacao}\nlink={$emitida->link}\n");

    expect($emitida->numero)->not->toBeNull()
        ->and($emitida->codigoVerificacao)->toHaveLength(50);

    $cancelada = NotaFiscal::build($config)->nfse()->cancelar(new Cancelamento(
        numero: (int) $emitida->numero,
        serie: $nota->serie,
        motivo: 'Cancelamento automatico de teste de integracao',
        chaveAcesso: $emitida->codigoVerificacao,
    ));

    fwrite(STDERR, "[INTEGRACAO NACIONAL] CANCELADA: situacao={$cancelada->situacao?->name}\n{$linha}\n");

    expect($cancelada->cancelada())->toBeTrue();
})->group('integracao');
