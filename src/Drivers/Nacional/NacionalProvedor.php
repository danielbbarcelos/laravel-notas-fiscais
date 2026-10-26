<?php

declare(strict_types=1);

namespace DanielBBarcelos\NotasFiscais\Drivers\Nacional;

use DanielBBarcelos\NotasFiscais\Contracts\NfseGateway;
use DanielBBarcelos\NotasFiscais\Contracts\Provedor;
use DanielBBarcelos\NotasFiscais\Data\Nfse\Prestador;
use DanielBBarcelos\NotasFiscais\Drivers\Nacional\Mappers\CancelamentoNacionalMapper;
use DanielBBarcelos\NotasFiscais\Drivers\Nacional\Mappers\DpsMapper;
use DanielBBarcelos\NotasFiscais\Enums\Documento;
use DanielBBarcelos\NotasFiscais\Exceptions\NotaFiscalException;

/**
 * Provedor do Sistema Nacional NFS-e (Emissor Nacional, API da Sefin Nacional).
 * Autentica por mTLS com o certificado A1 do emitente, que também assina a DPS e
 * os eventos. Não há login nem senha de prefeitura.
 */
class NacionalProvedor implements Provedor
{
    protected ?NfseGateway $nfse = null;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected array $config,
        protected string $nome = 'nacional',
    ) {
    }

    public function nome(): string
    {
        return $this->nome;
    }

    public function suporta(Documento $documento): bool
    {
        return $documento === Documento::Nfse;
    }

    public function nfse(): NfseGateway
    {
        if ($this->nfse !== null) {
            return $this->nfse;
        }

        $certificado = $this->certificado();
        $versao = (string) ($this->config['ver_aplic'] ?? 'laravel-nf 1.3');

        return $this->nfse = new NacionalNfseGateway(
            http: new NacionalConnector($this->config, $this->nome, $certificado),
            certificado: $certificado,
            dps: new DpsMapper(
                regime: RegimeTributario::daConfig($this->config, $this->optanteSimples()),
                codigoNbsPadrao: isset($this->config['codigo_nbs']) ? (string) $this->config['codigo_nbs'] : null,
                versaoAplicativo: $versao,
                informarInscricaoMunicipal: (bool) ($this->config['informar_inscricao_municipal'] ?? false),
                leiautes: [
                    1 => self::leiaute($this->config['leiaute_producao'] ?? null, DpsMapper::LEIAUTE_20260209),
                    2 => self::leiaute($this->config['leiaute_homologacao'] ?? null, DpsMapper::LEIAUTE_20261020),
                ],
            ),
            cancelamentos: new CancelamentoNacionalMapper($versao),
            ambiente: self::ambiente($this->config['ambiente'] ?? 1),
            prestadorPadrao: $this->prestadorPadrao(),
        );
    }

    /**
     * Leiaute de um ambiente na config: "20260209" ou "20261020" ("nt009" também
     * vale para o segundo). Vazio ou desconhecido fica no padrão do ambiente.
     */
    public static function leiaute(mixed $valor, string $padrao): string
    {
        return match (strtolower(trim((string) $valor))) {
            DpsMapper::LEIAUTE_20261020, 'nt009' => DpsMapper::LEIAUTE_20261020,
            DpsMapper::LEIAUTE_20260209 => DpsMapper::LEIAUTE_20260209,
            default => $padrao,
        };
    }

    /** "producao"/1 -> 1; "homologacao"/"producao_restrita"/2 -> 2. */
    public static function ambiente(mixed $valor): int
    {
        return match (strtolower(trim((string) $valor))) {
            '2', 'homologacao', 'homologação', 'producao_restrita', 'producao-restrita', 'restrita' => 2,
            default => 1,
        };
    }

    /**
     * 'certificado' é o conteúdo (bytes ou Base64), o que uma aplicação multi-tenant
     * guarda por cliente. 'certificado_arquivo' é um caminho do servidor, para o
     * cenário single-tenant do .env: chaves separadas para que um conteúdo vindo de
     * formulário nunca seja lido como caminho.
     */
    protected function certificado(): Certificado
    {
        $senha = (string) ($this->config['certificado_senha'] ?? '');
        $pfx = $this->config['certificado'] ?? null;

        if (is_string($pfx) && $pfx !== '') {
            return Certificado::doPfx($pfx, $senha);
        }

        $arquivo = $this->config['certificado_arquivo'] ?? null;

        if (is_string($arquivo) && $arquivo !== '') {
            return Certificado::doArquivo($arquivo, $senha);
        }

        throw new NotaFiscalException(
            "Provedor [{$this->nome}] sem 'certificado': o padrão nacional exige o certificado A1 (.pfx) do emitente."
        );
    }

    protected function optanteSimples(): ?bool
    {
        return isset($this->config['optante_simples_nacional']) ? (bool) $this->config['optante_simples_nacional'] : null;
    }

    /** Prestador padrão a partir da config (cpf_cnpj + código IBGE + inscrição municipal). */
    protected function prestadorPadrao(): ?Prestador
    {
        $cpfCnpj = $this->config['cpf_cnpj'] ?? null;
        $codigoIbge = $this->config['codigo_ibge'] ?? null;

        if ($cpfCnpj === null || $codigoIbge === null) {
            return null;
        }

        return new Prestador(
            cpfCnpj: (string) $cpfCnpj,
            codigoMunicipio: (string) $codigoIbge,
            inscricaoMunicipal: isset($this->config['inscricao_municipal'])
                ? (string) $this->config['inscricao_municipal']
                : null,
        );
    }
}
