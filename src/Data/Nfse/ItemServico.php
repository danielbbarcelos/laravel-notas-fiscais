<?php

declare(strict_types=1);

namespace DanielBBarcelos\NotasFiscais\Data\Nfse;

use DanielBBarcelos\NotasFiscais\Data\Shared\Valor;
use DanielBBarcelos\NotasFiscais\Enums\SituacaoTributaria;

/**
 * Item de serviço da NFS-e (uma tag <lista> no IPM). A alíquota e a quantidade
 * são percentuais/quantidades (não valores monetários), por isso aceitam
 * float|string; o mapper formata no padrão do provedor.
 */
final readonly class ItemServico
{
    public function __construct(
        /** Código do subitem da lista de serviços (LC 116/2003). */
        public string $codigoItemListaServico,
        public string $descritivo,
        public float|string $aliquota,
        public SituacaoTributaria $situacaoTributaria,
        public Valor $valorTributavel,
        /** Código TOM/IBGE do município onde o serviço foi prestado. */
        public string $codigoLocalPrestacao,
        /** "S" quando a tributação ocorre no município do prestador; "N" no local da prestação. */
        public bool $tributaMunicipioPrestador = true,
        public ?string $codigoAtividade = null,
        public ?Valor $deducao = null,
        public ?Valor $issRetidoFonte = null,
        public ?string $unidadeCodigo = null,
        public float|string|null $unidadeQuantidade = null,
        public ?Valor $unidadeValorUnitario = null,
        // Campos do padrão ABRASF (ignorados pelo driver REST proprietário):
        /** Código CNAE do serviço. */
        public ?string $codigoCnae = null,
        /**
         * Código NBS do serviço (9 dígitos, com ou sem pontuação). Obrigatório
         * no ABRASF a partir da versão 2.03 do layout; sem ele o município
         * recusa a emissão com o erro E351. Quando null, o driver usa o
         * "codigo_nbs" da config do provedor.
         */
        public ?string $codigoNbs = null,
        /** Exigibilidade do ISS (ABRASF: "1" = exigível, padrão). */
        public ?string $exigibilidadeIss = null,
        /** ISS retido pelo tomador (ABRASF: true = 1/retido, false = 2/não). */
        public ?bool $issRetido = null,
        /** Valor do ISS calculado (ABRASF: <ValorIss>). */
        public ?Valor $valorIss = null,
    ) {
    }
}
