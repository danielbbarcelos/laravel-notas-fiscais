<?php

declare(strict_types=1);

namespace DanielBBarcelos\NotasFiscais\Data\Nfse;

use DanielBBarcelos\NotasFiscais\Enums\FormaPagamentoTipo;

/** Forma de pagamento da NFS-e, com parcelas opcionais. */
final readonly class FormaPagamento
{
    /**
     * @param  list<Parcela>  $parcelas
     */
    public function __construct(
        public FormaPagamentoTipo $tipo,
        public array $parcelas = [],
    ) {
    }
}
