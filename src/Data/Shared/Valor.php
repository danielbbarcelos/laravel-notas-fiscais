<?php

declare(strict_types=1);

namespace DanielBBarcelos\NotasFiscais\Data\Shared;

use InvalidArgumentException;

/**
 * Valor monetário canônico. Internamente guardamos centavos (int) para evitar
 * imprecisão de ponto flutuante; cada mapper formata para o padrão exigido pela
 * API do provedor (o IPM, por exemplo, usa vírgula como separador decimal).
 */
final readonly class Valor
{
    private function __construct(
        public int $centavos,
    ) {
        if ($centavos < 0) {
            throw new InvalidArgumentException('Valor não pode ser negativo.');
        }
    }

    /** Ex.: Valor::reais('150.00'), Valor::reais('150,00') ou Valor::reais(150.0) */
    public static function reais(string|float|int $reais): self
    {
        $normalizado = is_string($reais) ? str_replace(',', '.', $reais) : (string) $reais;

        return new self((int) round(((float) $normalizado) * 100));
    }

    public static function centavos(int $centavos): self
    {
        return new self($centavos);
    }

    /** Formato com ponto decimal: "150.00". */
    public function paraApi(): string
    {
        return number_format($this->centavos / 100, 2, '.', '');
    }

    /** Formato com vírgula decimal, exigido pelo IPM: "150,00". */
    public function paraReal(int $casas = 2): string
    {
        return number_format($this->centavos / 100, $casas, ',', '');
    }

    public function __toString(): string
    {
        return $this->paraApi();
    }
}
