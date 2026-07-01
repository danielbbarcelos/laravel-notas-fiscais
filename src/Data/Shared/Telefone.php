<?php

declare(strict_types=1);

namespace DanielBBarcelos\NotasFiscais\Data\Shared;

/** Telefone com DDD separado, como o IPM exige (ddd_fone_* + fone_*). */
final readonly class Telefone
{
    public function __construct(
        public string $ddd,
        public string $numero,
    ) {
    }

    public function dddLimpo(): string
    {
        return preg_replace('/\D/', '', $this->ddd) ?? '';
    }

    public function numeroLimpo(): string
    {
        return preg_replace('/\D/', '', $this->numero) ?? '';
    }
}
