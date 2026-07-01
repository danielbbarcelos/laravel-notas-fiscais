<?php

declare(strict_types=1);

namespace DanielBBarcelos\NotasFiscais\Drivers\IpmAbrasf;

use DanielBBarcelos\NotasFiscais\Contracts\NfseGateway;
use DanielBBarcelos\NotasFiscais\Contracts\Provedor;
use DanielBBarcelos\NotasFiscais\Data\Nfse\Prestador;
use DanielBBarcelos\NotasFiscais\Drivers\IpmAbrasf\Mappers\CancelamentoAbrasfMapper;
use DanielBBarcelos\NotasFiscais\Drivers\IpmAbrasf\Mappers\ConsultaAbrasfMapper;
use DanielBBarcelos\NotasFiscais\Drivers\IpmAbrasf\Mappers\DeclaracaoMapper;
use DanielBBarcelos\NotasFiscais\Enums\Documento;

/**
 * Provedor IPM Atende.Net no padrão ABRASF 2.04 (SOAP). Usado por municípios que
 * expõem o serviço WNENotaFiscalEletronicaNfe (ex.: Pouso Alegre/MG). Autentica
 * por login/senha (Basic), sem certificado/assinatura.
 */
class IpmAbrasfProvedor implements Provedor
{
    protected AbrasfSoapConnector $http;

    protected ?NfseGateway $nfse = null;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected array $config,
        protected string $nome = 'ipm-abrasf',
    ) {
        $this->http = new AbrasfSoapConnector($config, $nome);
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
        return $this->nfse ??= new IpmAbrasfNfseGateway(
            http: $this->http,
            declaracoes: new DeclaracaoMapper(),
            cancelamentos: new CancelamentoAbrasfMapper(),
            consultas: new ConsultaAbrasfMapper(),
            prestadorPadrao: $this->prestadorPadrao(),
            linkTemplate: isset($this->config['link_template']) ? (string) $this->config['link_template'] : null,
        );
    }

    /** Prestador padrão a partir da config (cpf_cnpj + codigo IBGE + inscrição municipal). */
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
