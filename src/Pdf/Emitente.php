<?php

declare(strict_types=1);

namespace DanielBBarcelos\NotasFiscais\Pdf;

/**
 * Identidade visual da empresa no cabeçalho do comprovante: nome e caminho
 * absoluto do logo. Ambos opcionais — quando ausentes, o comprovante usa
 * placeholders. O CPF/CNPJ e a inscrição municipal vêm do Prestador.
 */
final readonly class Emitente
{
    public function __construct(
        /** Nome / razão social exibido no cabeçalho. */
        public ?string $nome = null,
        /** Caminho ABSOLUTO do arquivo de logo (png, jpg, gif ou svg). */
        public ?string $logo = null,
    ) {
    }
}
