<?php

declare(strict_types=1);

namespace DanielBBarcelos\NotasFiscais\Exceptions;

use DanielBBarcelos\NotasFiscais\Enums\Documento;

/** Lançada quando o provedor não emite o tipo de documento solicitado. */
class OperacaoNaoSuportadaException extends NotaFiscalException
{
    public static function para(string $provedor, Documento $documento): self
    {
        return new self("O provedor [{$provedor}] não suporta o documento [{$documento->value}].");
    }
}
