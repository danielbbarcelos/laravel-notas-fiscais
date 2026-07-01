<?php

declare(strict_types=1);

namespace DanielBBarcelos\NotasFiscais\Drivers\Ipm;

use DanielBBarcelos\NotasFiscais\Contracts\NfseGateway;
use DanielBBarcelos\NotasFiscais\Contracts\Provedor;
use DanielBBarcelos\NotasFiscais\Data\Nfse\Prestador;
use DanielBBarcelos\NotasFiscais\Drivers\Ipm\Mappers\CancelamentoMapper;
use DanielBBarcelos\NotasFiscais\Drivers\Ipm\Mappers\ConsultaMapper;
use DanielBBarcelos\NotasFiscais\Drivers\Ipm\Mappers\NotaServicoMapper;
use DanielBBarcelos\NotasFiscais\Enums\Documento;

/**
 * Provedor IPM Atende.Net. Hoje implementa apenas NFS-e (serviços municipais);
 * NF-e/NFC-e ficam reservadas para expansão futura.
 */
class IpmProvedor implements Provedor
{
    protected IpmConnector $http;

    protected ?NfseGateway $nfse = null;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected array $config,
        protected string $nome = 'ipm',
    ) {
        $this->http = new IpmConnector($config, $nome);
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
        return $this->nfse ??= new IpmNfseGateway(
            http: $this->http,
            notas: new NotaServicoMapper(),
            cancelamentos: new CancelamentoMapper(),
            consultas: new ConsultaMapper(),
            prestadorPadrao: $this->prestadorPadrao(),
        );
    }

    /** Prestador padrão a partir da configuração (cpf_cnpj + cidade/TOM). */
    protected function prestadorPadrao(): ?Prestador
    {
        $cpfCnpj = $this->config['cpf_cnpj'] ?? null;
        $cidade = $this->config['cidade'] ?? null;

        if ($cpfCnpj === null || $cidade === null) {
            return null;
        }

        return new Prestador((string) $cpfCnpj, (string) $cidade);
    }
}
