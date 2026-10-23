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
        /** Chave de acesso de 50 dígitos (padrão nacional, onde o número não identifica a nota). */
        public ?string $chaveAcesso = null,
        /** Justificativa do padrão nacional: 1 = erro na emissão (padrão), 2 = serviço não prestado, 9 = outros. */
        public ?int $codigoMotivo = null,
    ) {
    }
}
