<?php

declare(strict_types=1);

namespace DanielBBarcelos\NotasFiscais\Drivers\Ipm;

use DanielBBarcelos\NotasFiscais\Exceptions\NotaFiscalException;

/** Conversão da resposta XML do IPM para array associativo. */
final class Xml
{
    /**
     * @return array<string, mixed>
     */
    public static function paraArray(string $xml): array
    {
        $limpo = trim($xml);

        if ($limpo === '') {
            return [];
        }

        $anterior = libxml_use_internal_errors(true);
        $elemento = simplexml_load_string($limpo);
        libxml_use_internal_errors($anterior);

        if ($elemento === false) {
            throw new NotaFiscalException('Resposta do provedor não é um XML válido: '.mb_substr($limpo, 0, 200));
        }

        /** @var array<string, mixed> $dados */
        $dados = json_decode((string) json_encode($elemento), true) ?? [];

        return $dados;
    }
}
