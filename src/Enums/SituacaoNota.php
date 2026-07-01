<?php

declare(strict_types=1);

namespace DanielBBarcelos\NotasFiscais\Enums;

/**
 * Situação canônica de uma NFS-e. Os valores espelham o campo
 * "situacao_codigo_nfse" do IPM; o mapper de cada driver traduz qualquer
 * divergência para um destes casos.
 */
enum SituacaoNota: int
{
    case Emitida = 1;
    case Cancelada = 2;

    public function emitida(): bool
    {
        return $this === self::Emitida;
    }

    public function cancelada(): bool
    {
        return $this === self::Cancelada;
    }
}
