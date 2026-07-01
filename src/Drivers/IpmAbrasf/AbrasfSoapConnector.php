<?php

declare(strict_types=1);

namespace DanielBBarcelos\NotasFiscais\Drivers\IpmAbrasf;

use DanielBBarcelos\NotasFiscais\Exceptions\NotaFiscalApiException;
use DanielBBarcelos\NotasFiscais\Exceptions\NotaFiscalException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Camada HTTP do IPM Atende.Net no padrão ABRASF (SOAP). O envelope SOAP vai no
 * corpo cru (text/xml), autenticado por Basic (CNPJ + senha) — sem certificado.
 * O sucesso é determinado pela ausência de erro estruturado na resposta (SOAP
 * Fault, <retorno><msg> do IPM, ou <MensagemRetorno> da ABRASF).
 */
class AbrasfSoapConnector
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected array $config,
        protected string $nome,
    ) {
    }

    /** Envia um envelope SOAP e devolve o corpo da resposta (validado). */
    public function enviar(string $soapXml): string
    {
        $resposta = Http::timeout((int) ($this->config['timeout'] ?? 60))
            ->withBasicAuth(
                (string) ($this->config['cpf_cnpj'] ?? ''),
                (string) ($this->config['senha'] ?? ''),
            )
            ->withBody($soapXml, 'text/xml; charset=utf-8')
            ->post($this->url());

        $corpo = $resposta->body();

        $this->validar($resposta, $corpo);

        return $corpo;
    }

    protected function url(): string
    {
        $url = $this->config['base_url'] ?? null;

        if (! is_string($url) || $url === '') {
            throw new NotaFiscalException("Provedor [{$this->nome}] sem 'base_url' configurada.");
        }

        return $url;
    }

    /** Lança NotaFiscalApiException se a resposta contém erro; caso contrário, retorna. */
    protected function validar(Response $resposta, string $corpo): void
    {
        try {
            $dom = AbrasfXml::parse($corpo);
        } catch (NotaFiscalException $e) {
            if ($resposta->failed()) {
                throw NotaFiscalApiException::daResposta($this->nome, $resposta);
            }

            throw $e;
        }

        // 1) SOAP Fault
        if (($fault = AbrasfXml::texto($dom, 'faultstring')) !== null) {
            throw NotaFiscalApiException::doRetorno(
                $this->nome,
                AbrasfXml::texto($dom, 'faultcode') ?? 'SOAP-FAULT',
                $fault,
                ['xml' => $corpo],
            );
        }

        // 2) Erro de plataforma IPM: <retorno><msg>Acesso Negado!</msg><code>401</code>
        if (($msg = AbrasfXml::texto($dom, 'msg')) !== null) {
            throw NotaFiscalApiException::doRetorno(
                $this->nome,
                AbrasfXml::texto($dom, 'code') ?? '?',
                $msg,
                ['xml' => $corpo],
            );
        }

        // 3) Erro de negócio ABRASF: <MensagemRetorno><Codigo><Mensagem><Correcao>
        foreach (AbrasfXml::elementos($dom, 'MensagemRetorno') as $mr) {
            $mensagem = AbrasfXml::filho($mr, 'Mensagem');

            if ($mensagem !== null) {
                $correcao = AbrasfXml::filho($mr, 'Correcao');

                throw NotaFiscalApiException::doRetorno(
                    $this->nome,
                    AbrasfXml::filho($mr, 'Codigo') ?? '?',
                    $correcao !== null ? "{$mensagem} — Correção: {$correcao}" : $mensagem,
                    ['xml' => $corpo],
                );
            }
        }

        // 4) Falha HTTP sem corpo estruturado
        if ($resposta->failed()) {
            throw NotaFiscalApiException::daResposta($this->nome, $resposta);
        }
    }
}
