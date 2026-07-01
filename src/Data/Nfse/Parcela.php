<?php

declare(strict_types=1);

namespace DanielBBarcelos\NotasFiscais\Data\Nfse;

use DanielBBarcelos\NotasFiscais\Data\Shared\Valor;

/** Parcela de pagamento da NFS-e (numero entre 1 e 24 no IPM). */
final readonly class Parcela
{
    public function __construct(
        public int $numero,
        public Valor $valor,
        /** Data de vencimento no formato dd/mm/aaaa. */
        public string $dataVencimento,
    ) {
    }
}
