<?php

declare(strict_types=1);

namespace DanielBBarcelos\NotasFiscais\Data\Nfse;

/**
 * Prestador (emissor) da NFS-e. Quando não informado na NotaServico, o driver
 * usa o prestador padrão montado a partir da configuração (cpf_cnpj + cidade).
 */
final readonly class Prestador
{
    public function __construct(
        public string $cpfCnpj,
        /** Código do município de inscrição: TOM no driver REST proprietário, IBGE no ABRASF. */
        public string $codigoMunicipio,
        /** Inscrição Municipal do prestador (exigida pelo ABRASF). */
        public ?string $inscricaoMunicipal = null,
    ) {
    }

    public function cpfCnpjLimpo(): string
    {
        return preg_replace('/\D/', '', $this->cpfCnpj) ?? '';
    }
}
