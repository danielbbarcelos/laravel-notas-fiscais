<?php

declare(strict_types=1);

namespace DanielBBarcelos\NotasFiscais\Data\Nfse;

use DanielBBarcelos\NotasFiscais\Data\Shared\Valor;

/**
 * Requisição canônica de emissão de NFS-e. Independe do provedor — cada driver
 * traduz isto para o payload da sua API.
 *
 * As retenções (IR, INSS, PIS, COFINS, etc.) não afetam a base de cálculo do
 * ISS; são apenas informativas na nota. O ISS por item é definido em ItemServico
 * (alíquota + situação tributária).
 *
 * Reservado para a Reforma Tributária: um bloco opcional de IBS/CBS poderá ser
 * adicionado aqui (e em ItemServico) sem quebrar este contrato.
 */
final readonly class NotaServico
{
    /**
     * @param  list<ItemServico>  $itens
     */
    public function __construct(
        public int $serie,
        /** Data do fato gerador no formato dd/mm/aaaa. */
        public string $dataFatoGerador,
        public Valor $valorTotal,
        public Tomador $tomador,
        public array $itens,
        /** Prestador; se nulo, o driver usa o configurado em notas-fiscais.drivers. */
        public ?Prestador $prestador = null,
        public ?string $observacao = null,
        /** Identificador para idempotência: reenviar o mesmo retorna a NFS-e já gerada. */
        public ?string $identificador = null,
        public ?FormaPagamento $formaPagamento = null,
        public ?Valor $valorDesconto = null,
        public ?Valor $valorIr = null,
        public ?Valor $valorInss = null,
        public ?Valor $valorContribuicaoSocial = null,
        public ?Valor $valorRps = null,
        public ?Valor $valorPis = null,
        public ?Valor $valorCofins = null,
        /** Quando true, adiciona id="nota" à tag <nfse> (municípios com assinatura digital). */
        public bool $assinada = false,
        /** Quando true, valida sem emitir de fato (REST: <nfse_teste>1>; ABRASF: <EnvioTeste>1>). */
        public bool $teste = false,
        // Campos do padrão ABRASF (ignorados pelo driver REST proprietário):
        /** Competência (ABRASF: <Competencia>, formato AAAA-MM-DD). */
        public ?string $competencia = null,
        /** Optante pelo Simples Nacional (ABRASF: true = 1, padrão). */
        public bool $optanteSimplesNacional = true,
        /** Incentivo fiscal (ABRASF: true = 1, false = 2/padrão). */
        public bool $incentivoFiscal = false,
    ) {
    }
}
