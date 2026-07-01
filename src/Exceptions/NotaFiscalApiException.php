<?php

declare(strict_types=1);

namespace DanielBBarcelos\NotasFiscais\Exceptions;

use Illuminate\Http\Client\Response;

/**
 * Lançada quando o provedor responde com erro. Note que provedores legados como
 * o IPM respondem HTTP 200 mesmo em erro de negócio — nesse caso o erro vem no
 * corpo XML (<mensagem><codigo>), capturado por doRetorno(); daResposta() cobre
 * falhas de transporte (4xx/5xx).
 */
class NotaFiscalApiException extends NotaFiscalException
{
    /** @var array<string, mixed> Retorno do provedor já parseado. */
    public array $corpo = [];

    /** Código de erro do provedor (ex.: "00128"). */
    public ?string $codigo = null;

    public ?int $statusHttp = null;

    public static function daResposta(string $provedor, Response $resposta): self
    {
        $e = new self("[{$provedor}] Erro HTTP {$resposta->status()} ao comunicar com o provedor.");
        $e->statusHttp = $resposta->status();

        return $e;
    }

    /**
     * @param  array<string, mixed>  $corpo
     */
    public static function doRetorno(string $provedor, string $codigo, string $mensagem, array $corpo): self
    {
        $e = new self("[{$provedor}] Erro {$codigo}: {$mensagem}");
        $e->codigo = $codigo;
        $e->corpo = $corpo;

        return $e;
    }
}
