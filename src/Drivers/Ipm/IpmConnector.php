<?php

declare(strict_types=1);

namespace DanielBBarcelos\NotasFiscais\Drivers\Ipm;

use DanielBBarcelos\NotasFiscais\Exceptions\NotaFiscalApiException;
use DanielBBarcelos\NotasFiscais\Exceptions\NotaFiscalException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Camada HTTP do IPM Atende.Net. O XML vai como upload de arquivo no campo "xml"
 * (multipart/form-data), autenticado por Basic (CPF/CNPJ + senha). O webservice
 * responde HTTP 200 mesmo em erro de negócio, então o sucesso é determinado pelo
 * código em <mensagem><codigo> (00001 = Sucesso). A sessão (PHPSESSID) é
 * reaproveitada entre requisições para reduzir o tempo de emissão.
 */
class IpmConnector
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected array $config,
        protected string $nome,
    ) {
    }

    /**
     * Envia um XML e devolve o retorno já parseado e validado.
     *
     * @return array<string, mixed>
     */
    public function enviar(string $xml): array
    {
        $resposta = $this->cliente()
            ->attach('xml', $xml, 'nota.xml')
            ->post($this->url());

        if ($resposta->failed()) {
            throw NotaFiscalApiException::daResposta($this->nome, $resposta);
        }

        $this->guardarSessao($resposta);

        return $this->lerRetorno($resposta->body());
    }

    protected function url(): string
    {
        $url = $this->config['base_url'] ?? null;

        if (! is_string($url) || $url === '') {
            throw new NotaFiscalException("Provedor [{$this->nome}] sem 'base_url' configurada.");
        }

        return $url;
    }

    protected function cliente(): PendingRequest
    {
        $req = Http::timeout((int) ($this->config['timeout'] ?? 30))
            ->withBasicAuth(
                (string) ($this->config['cpf_cnpj'] ?? ''),
                (string) ($this->config['senha'] ?? ''),
            );

        if (($proxy = $this->proxy()) !== null) {
            $req = $req->withOptions(['proxy' => $proxy]);
        }

        if (($sessao = $this->sessao()) !== null) {
            $req = $req->withHeaders(['Cookie' => "PHPSESSID={$sessao}"]);
        }

        return $req;
    }

    /**
     * Proxy de saída, para municípios cujo webservice só aceita IP nacional.
     * Formato Guzzle: "http://usuario:senha@host:porta" ou "socks5://host:porta".
     */
    protected function proxy(): ?string
    {
        $proxy = $this->config['proxy'] ?? null;

        return is_string($proxy) && $proxy !== '' ? $proxy : null;
    }

    protected function sessao(): ?string
    {
        // O reuso de sessão é uma otimização: nunca deve quebrar a emissão se o
        // cache store do app estiver indisponível.
        try {
            return Cache::get($this->chaveSessao());
        } catch (Throwable) {
            return null;
        }
    }

    protected function guardarSessao(Response $resposta): void
    {
        try {
            $cookie = $resposta->cookies()?->getCookieByName('PHPSESSID');

            if ($cookie !== null && $cookie->getValue() !== '') {
                // Sessão do PHP costuma durar ~24min; renovamos com folga.
                Cache::put($this->chaveSessao(), $cookie->getValue(), 1200);
            }
        } catch (Throwable) {
            // Cache indisponível: seguimos sem reaproveitar a sessão.
        }
    }

    protected function chaveSessao(): string
    {
        // A assinatura isola a sessão por credencial: num SaaS multi-tenant, dois
        // tenants no mesmo driver 'ipm' nunca compartilham o mesmo PHPSESSID.
        $assinatura = sha1(
            ((string) ($this->config['base_url'] ?? '')).'|'.((string) ($this->config['cpf_cnpj'] ?? ''))
        );

        return "notas-fiscais:{$this->nome}:{$assinatura}:phpsessid";
    }

    /**
     * Valida o código de retorno e devolve o corpo parseado.
     *
     * @return array<string, mixed>
     */
    protected function lerRetorno(string $corpo): array
    {
        $dados = Xml::paraArray($corpo);

        $codigos = $dados['mensagem']['codigo'] ?? null;
        $codigos = is_array($codigos) ? array_values($codigos) : ($codigos !== null ? [$codigos] : []);

        $primeiro = trim((string) ($codigos[0] ?? ''));

        // Formato: "00001 - Sucesso" / "00128 - Erro na validação dos dados".
        [$codigo, $mensagem] = array_pad(explode(' - ', $primeiro, 2), 2, '');
        $numero = (int) preg_replace('/\D/', '', $codigo);

        if ($numero !== 1) {
            throw NotaFiscalApiException::doRetorno(
                $this->nome,
                $codigo !== '' ? $codigo : '?',
                $mensagem !== '' ? $mensagem : $primeiro,
                $dados,
            );
        }

        return $dados;
    }
}
