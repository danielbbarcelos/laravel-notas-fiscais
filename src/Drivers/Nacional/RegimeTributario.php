<?php

declare(strict_types=1);

namespace DanielBBarcelos\NotasFiscais\Drivers\Nacional;

use InvalidArgumentException;

/**
 * Regime tributário do emitente, como o padrão nacional o exige na DPS
 * (prest/regTrib) e para decidir alíquota e total de tributos. É do emitente,
 * não da nota, por isso vem da config do driver e não do NotaServico.
 */
final readonly class RegimeTributario
{
    public const NAO_OPTANTE = 1;

    public const SIMPLES_MEI = 2;

    public const SIMPLES_ME_EPP = 3;

    /**
     * @param  array{0: float, 1: float, 2: float}|null  $percentuaisTributos  federal, estadual e municipal (Lei 12.741)
     */
    public function __construct(
        /** opSimpNac: 1 não optante, 2 MEI, 3 ME/EPP. */
        public int $opcaoSimples = self::NAO_OPTANTE,
        /** regApTribSN (só ME/EPP): 1 tributos pelo SN, 2 federais pelo SN e ISS fora, 3 tudo fora do SN. */
        public int $apuracaoSimples = 1,
        /** regEspTrib: 0 nenhum, 1 cooperativa, 2 estimativa, 3 ME municipal, 4 notário, 5 autônomo, 6 sociedade de profissionais, 9 outros. */
        public int $regimeEspecial = 0,
        /** Município de incidência conveniado ao Sistema Nacional (decide se a alíquota vai na DPS). */
        public bool $municipioConveniado = true,
        public ?array $percentuaisTributos = null,
        /** pTotTribSN: percentual do Simples, para ME/EPP. */
        public ?float $percentualTributosSimples = null,
        /** CST do PIS/COFINS quando há retenção. */
        public string $cstPisCofins = '01',
    ) {
        if (! in_array($opcaoSimples, [self::NAO_OPTANTE, self::SIMPLES_MEI, self::SIMPLES_ME_EPP], true)) {
            throw new InvalidArgumentException("Situação no Simples Nacional inválida: {$opcaoSimples} (use 1, 2 ou 3).");
        }

        if (! in_array($apuracaoSimples, [1, 2, 3], true)) {
            throw new InvalidArgumentException("Regime de apuração do Simples inválido: {$apuracaoSimples} (use 1, 2 ou 3).");
        }

        if (! in_array($regimeEspecial, [0, 1, 2, 3, 4, 5, 6, 9], true)) {
            throw new InvalidArgumentException("Regime especial de tributação inválido: {$regimeEspecial}.");
        }
    }

    /**
     * A partir da config do driver. Sem "simples_nacional" na config, usa o
     * optanteSimplesNacional do NotaServico (true = ME/EPP, false = não optante).
     *
     * @param  array<string, mixed>  $config
     */
    public static function daConfig(array $config, ?bool $optanteSimples = null): self
    {
        $opcao = isset($config['simples_nacional']) && $config['simples_nacional'] !== ''
            ? (int) $config['simples_nacional']
            : ($optanteSimples === true ? self::SIMPLES_ME_EPP : self::NAO_OPTANTE);

        $percentuais = null;
        if (isset($config['tributos_federais']) || isset($config['tributos_estaduais']) || isset($config['tributos_municipais'])) {
            $percentuais = [
                (float) ($config['tributos_federais'] ?? 0),
                (float) ($config['tributos_estaduais'] ?? 0),
                (float) ($config['tributos_municipais'] ?? 0),
            ];
        }

        return new self(
            opcaoSimples: $opcao,
            apuracaoSimples: (int) ($config['regime_apuracao_simples'] ?? 1),
            regimeEspecial: (int) ($config['regime_especial'] ?? 0),
            municipioConveniado: (bool) ($config['municipio_conveniado'] ?? true),
            percentuaisTributos: $percentuais,
            percentualTributosSimples: isset($config['tributos_simples']) && $config['tributos_simples'] !== ''
                ? (float) $config['tributos_simples']
                : null,
            cstPisCofins: (string) ($config['cst_pis_cofins'] ?? '01'),
        );
    }
}
