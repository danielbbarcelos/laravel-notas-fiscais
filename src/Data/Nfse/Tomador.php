<?php

declare(strict_types=1);

namespace DanielBBarcelos\NotasFiscais\Data\Nfse;

use DanielBBarcelos\NotasFiscais\Data\Shared\Endereco;
use DanielBBarcelos\NotasFiscais\Data\Shared\Telefone;
use DanielBBarcelos\NotasFiscais\Enums\TipoTomador;

/**
 * Tomador (recebedor) do serviço. Para tipo Física/Jurídica a identificação é o
 * CPF/CNPJ; para Estrangeiro é o passaporte/cartão de identificação, e os campos
 * estado/pais passam a ser exigidos.
 */
final readonly class Tomador
{
    public function __construct(
        public TipoTomador $tipo,
        public string $identificacao,
        public string $nomeRazaoSocial,
        public ?string $nomeFantasia = null,
        public ?string $inscricaoEstadual = null,
        public ?Endereco $endereco = null,
        public ?string $email = null,
        public ?Telefone $telefoneComercial = null,
        public ?Telefone $telefoneResidencial = null,
        public ?string $estado = null,
        public ?string $pais = null,
    ) {
    }

    /** Identificação apenas com dígitos (CPF/CNPJ). */
    public function identificacaoLimpa(): string
    {
        return preg_replace('/\D/', '', $this->identificacao) ?? '';
    }

    public function ehCnpj(): bool
    {
        return strlen($this->identificacaoLimpa()) === 14;
    }
}
