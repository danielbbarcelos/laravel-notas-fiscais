<?php

declare(strict_types=1);

namespace DanielBBarcelos\NotasFiscais\Enums;

/**
 * Situação tributária do item de serviço (campo "situacao_tributaria" do IPM,
 * Tabela 13 da NTE 35/2021). Determina a forma de cobrança do ISS. Os códigos
 * 11, 12 e 13 não existem na tabela — por isso o enum não é contíguo.
 */
enum SituacaoTributaria: int
{
    /** Tributada Integralmente (TI). */
    case TributadaIntegralmente = 0;

    /** Tributada Integralmente com ISSRF (TIRF). */
    case TributadaIntegralmenteIssrf = 1;

    /** Tributada Integralmente e sujeita à Substituição Tributária (TIST). */
    case TributadaIntegralmenteSubstituicao = 2;

    /** Tributada com redução da base de cálculo (TRBC). */
    case TributadaReducaoBase = 3;

    /** Tributada com redução da base de cálculo com ISSRF (TRBCRF). */
    case TributadaReducaoBaseIssrf = 4;

    /** Tributada com redução da base e sujeita à Substituição Tributária (TRBCST). */
    case TributadaReducaoBaseSubstituicao = 5;

    /** Isenta (ISE). */
    case Isenta = 6;

    /** Imune (IMU). */
    case Imune = 7;

    /** Não Tributada - ISS regime Fixo (NTIFix). */
    case NaoTributadaIssFixo = 8;

    /** Não Tributada - ISS regime Estimativa (NTIEs). */
    case NaoTributadaIssEstimativa = 9;

    /** Não Tributada - ISS Construção Civil recolhido antecipadamente (NTICc). */
    case NaoTributadaConstrucaoCivil = 10;

    /** Não tributada (NTRIB). */
    case NaoTributada = 14;

    /** Não Tributada - Ato Cooperado (NTAC). */
    case NaoTributadaAtoCooperado = 15;
}
