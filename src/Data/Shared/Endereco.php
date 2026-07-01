<?php

declare(strict_types=1);

namespace DanielBBarcelos\NotasFiscais\Data\Shared;

/**
 * Endereço canônico do tomador. O código do município segue o padrão TOM
 * (Receita Federal), o mesmo usado pelo IPM no campo "cidade".
 */
final readonly class Endereco
{
    public function __construct(
        public ?string $logradouro = null,
        public ?string $numero = null,
        public ?string $complemento = null,
        public ?string $pontoReferencia = null,
        public ?string $bairro = null,
        public ?string $codigoMunicipio = null,
        /** UF (ex.: "MG"), exigida pelo ABRASF. */
        public ?string $uf = null,
        public ?string $cep = null,
    ) {
    }

    /** CEP apenas com dígitos. */
    public function cepLimpo(): ?string
    {
        if ($this->cep === null) {
            return null;
        }

        return preg_replace('/\D/', '', $this->cep) ?? '';
    }
}
