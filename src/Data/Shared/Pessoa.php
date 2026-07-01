<?php

declare(strict_types=1);

namespace DanielBBarcelos\NotasFiscais\Data\Shared;

/**
 * Pessoa canônica (nome + documento). O documento é guardado como informado;
 * use documentoLimpo() para obter apenas os dígitos, como as APIs exigem.
 */
final readonly class Pessoa
{
    public function __construct(
        public string $nome,
        public string $documento,
    ) {
    }

    /** Apenas dígitos. */
    public function documentoLimpo(): string
    {
        return preg_replace('/\D/', '', $this->documento) ?? '';
    }

    public function ehCnpj(): bool
    {
        return strlen($this->documentoLimpo()) === 14;
    }
}
