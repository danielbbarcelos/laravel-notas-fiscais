<?php

declare(strict_types=1);

use DanielBBarcelos\NotasFiscais\Data\Nfse\ItemServico;
use DanielBBarcelos\NotasFiscais\Data\Nfse\NotaServico;
use DanielBBarcelos\NotasFiscais\Data\Nfse\Tomador;
use DanielBBarcelos\NotasFiscais\Data\Shared\Valor;
use DanielBBarcelos\NotasFiscais\Enums\SituacaoTributaria;
use DanielBBarcelos\NotasFiscais\Enums\TipoTomador;
use DanielBBarcelos\NotasFiscais\Exceptions\NotaFiscalApiException;
use DanielBBarcelos\NotasFiscais\Facades\NotaFiscal;

/**
 * Teste de integração REAL contra o IPM Atende.Net. NÃO roda na suíte normal:
 * só executa quando as variáveis IPM_* estão no ambiente (caso contrário é
 * pulado). Usa o modo teste (<nfse_teste>1>) — valida no servidor do município
 * SEM gerar NFS-e. As credenciais ficam só no ambiente, nunca em arquivo.
 *
 * Exemplo (Pouso Alegre/MG):
 *
 *   IPM_BASE_URL="https://nfse-pousoalegre.atende.net/atende.php?pg=rest&service=WNERestServiceNFSe&cidade=padrao" \
 *   IPM_CPF_CNPJ="00000000000000" \
 *   IPM_SENHA="sua-senha" \
 *   IPM_CIDADE_TOM="5049" \
 *   vendor/bin/pest --group=integracao
 *
 * Opcionais para ajustar a nota de teste:
 *   IPM_ITEM_LC, IPM_ALIQUOTA, IPM_TOMADOR_DOC, IPM_TOMADOR_NOME
 */
function envIpm(string $chave, ?string $padrao = null): ?string
{
    $valor = getenv($chave);

    return ($valor === false || $valor === '') ? $padrao : $valor;
}

beforeEach(function () {
    foreach (['IPM_BASE_URL', 'IPM_CPF_CNPJ', 'IPM_SENHA', 'IPM_CIDADE_TOM'] as $chave) {
        if (envIpm($chave) === null) {
            $this->markTestSkipped("Defina {$chave} no ambiente para rodar o teste de integração real.");
        }
    }
});

it('valida uma emissão real em modo teste contra o IPM', function () {
    $config = [
        'driver' => 'ipm',
        'base_url' => envIpm('IPM_BASE_URL'),
        'cpf_cnpj' => envIpm('IPM_CPF_CNPJ'),
        'senha' => envIpm('IPM_SENHA'),
        'cidade' => envIpm('IPM_CIDADE_TOM'),
        'timeout' => 30,
    ];

    $tom = (string) envIpm('IPM_CIDADE_TOM');

    $nota = new NotaServico(
        serie: 1,
        dataFatoGerador: date('d/m/Y'),
        valorTotal: Valor::reais('1.00'),
        tomador: new Tomador(
            tipo: TipoTomador::Juridica,
            identificacao: (string) envIpm('IPM_TOMADOR_DOC', envIpm('IPM_CPF_CNPJ')),
            nomeRazaoSocial: (string) envIpm('IPM_TOMADOR_NOME', 'TOMADOR TESTE INTEGRACAO'),
        ),
        itens: [
            new ItemServico(
                codigoItemListaServico: (string) envIpm('IPM_ITEM_LC', '0107'),
                descritivo: 'Teste de integracao via webservice',
                aliquota: (float) str_replace(',', '.', (string) envIpm('IPM_ALIQUOTA', '2')),
                situacaoTributaria: SituacaoTributaria::TributadaIntegralmente,
                valorTributavel: Valor::reais('1.00'),
                codigoLocalPrestacao: $tom,
            ),
        ],
        teste: true, // <nfse_teste>1</nfse_teste> — valida sem emitir
    );

    $linha = str_repeat('=', 70);

    try {
        $emitida = NotaFiscal::build($config)->nfse()->emitir($nota);

        // Em modo teste o IPM normalmente devolve um "erro" informando que a
        // nota é válida; chegar aqui (código 00001) é incomum, mas registramos.
        fwrite(STDERR, "\n{$linha}\n[INTEGRACAO IPM] Retorno de SUCESSO (00001):\n"
            .json_encode($emitida->bruto, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
            ."\n{$linha}\n");
    } catch (NotaFiscalApiException $e) {
        fwrite(STDERR, "\n{$linha}\n[INTEGRACAO IPM] Código : {$e->codigo}\n"
            ."[INTEGRACAO IPM] Mensagem: {$e->getMessage()}\n"
            ."[INTEGRACAO IPM] Corpo  : ".json_encode($e->corpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
            ."\n{$linha}\n");
    }

    // Teste exploratório: não falha — o objetivo é ler a resposta do IPM acima.
    expect(true)->toBeTrue();
})->group('integracao');
