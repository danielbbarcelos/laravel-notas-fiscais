<?php

declare(strict_types=1);

namespace DanielBBarcelos\NotasFiscais\Data\Nfse;

use DanielBBarcelos\NotasFiscais\Enums\SituacaoNota;

/**
 * Resposta canônica de uma NFS-e (emissão, cancelamento ou consulta). Forma
 * idêntica para qualquer provedor; $bruto guarda o retorno original como escape
 * hatch para campos não mapeados.
 */
final readonly class NotaEmitida
{
    /**
     * @param  array<string, mixed>  $bruto
     */
    public function __construct(
        public ?int $numero,
        public ?int $serie,
        /** Data de emissão (dd/mm/aaaa). */
        public ?string $data,
        /** Hora de emissão (HH:MM:SS). */
        public ?string $hora,
        public ?SituacaoNota $situacao,
        /** Código verificador de autenticidade (40 caracteres no IPM). */
        public ?string $codigoVerificacao,
        /** URL para o PDF/visualização da NFS-e. */
        public ?string $link,
        public array $bruto = [],
    ) {
    }

    public function emitida(): bool
    {
        return $this->situacao?->emitida() ?? false;
    }

    public function cancelada(): bool
    {
        return $this->situacao?->cancelada() ?? false;
    }
}
