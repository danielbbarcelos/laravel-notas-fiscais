<?php

declare(strict_types=1);

namespace DanielBBarcelos\NotasFiscais\Exceptions;

use RuntimeException;

/** Exceção base do pacote. Capture esta para tratar qualquer falha de nota fiscal. */
class NotaFiscalException extends RuntimeException
{
}
