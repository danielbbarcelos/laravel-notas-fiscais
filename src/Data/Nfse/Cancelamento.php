<?php

declare(strict_types=1);

namespace DanielBBarcelos\NotasFiscais\Data\Nfse;

/**
 * Requisição canônica de cancelamento de NFS-e. O cancelamento é definitivo;
 * para correção, prefira a substituição (operação futura).
 */
final readonly class Cancelamento
{
    public function __construct(
        public int $numero,
        public int $serie,
        public string $motivo,
        /** Prestador; se nulo, o driver usa o configurado em notas-fiscais.drivers. */
        public ?Prestador $prestador = null,
    ) {
    }
}
