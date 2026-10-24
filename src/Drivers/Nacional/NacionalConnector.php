<?php

declare(strict_types=1);

namespace DanielBBarcelos\NotasFiscais\Drivers\Nacional;

use DanielBBarcelos\NotasFiscais\Exceptions\NotaFiscalApiException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Camada HTTP da Sefin Nacional NFS-e: REST com JSON, autenticada por mTLS com o
 * certificado A1 do emitente (não há login nem token). Os XML viajam em campos
 * *XmlGZipB64; os erros de negócio voltam como lista "erros" com Codigo/Descricao.
 *
 * O ambiente decide a URL: tpAmb 1 (produção) vai para sefin.nfse.gov.br e tpAmb
 * 2 (produção restrita, a homologação do padrão) para sefin.producaorestrita.
 */
class NacionalConnector
{
    public const URL_PRODUCAO = 'https://sefin.nfse.gov.br/SefinNacional';

    public const URL_PRODUCAO_RESTRITA = 'https://sefin.producaorestrita.nfse.gov.br/SefinNacional';

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected array $config,
        protected string $nome,
        protected Certificado $certificado,
    ) {
    }

    /**
     * POST de um XML compactado no campo informado; devolve o JSON da resposta.
     *
     * @return array<string, mixed>
     */
    public function enviar(string $caminho, string $campo, string $xml, int $ambiente): array
    {
        return $this->json(
            $this->cliente()->post($this->url($ambiente).$caminho, [$campo => NacionalXml::compactar($xml)])
        );
    }

    /**
     * GET; devolve o JSON da resposta.
     *
     * @return array<string, mixed>
     */
    public function consultar(string $caminho, int $ambiente): array
    {
        return $this->json($this->cliente()->get($this->url($ambiente).$caminho));
    }

    public function url(int $ambiente): string
    {
        $chave = $ambiente === 1 ? 'base_url' : 'base_url_homologacao';
        $url = $this->config[$chave] ?? null;

        if (! is_string($url) || $url === '') {
            $url = $ambiente === 1 ? self::URL_PRODUCAO : self::URL_PRODUCAO_RESTRITA;
        }

        return rtrim($url, '/');
    }

    protected function cliente(): PendingRequest
    {
        $opcoes = $this->certificado->opcoesMtls();

        if (($proxy = $this->proxy()) !== null) {
            $opcoes['proxy'] = $proxy;
        }

        return Http::timeout((int) ($this->config['timeout'] ?? 60))
            ->acceptJson()
            ->asJson()
            ->withOptions($opcoes);
    }

    /** Proxy de saída (mesmo formato dos drivers IPM). */
    protected function proxy(): ?string
    {
        $proxy = $this->config['proxy'] ?? null;

        return is_string($proxy) && $proxy !== '' ? $proxy : null;
    }

    /**
     * Lança NotaFiscalApiException quando a resposta traz erro; caso contrário,
     * devolve o JSON decodificado.
     *
     * @return array<string, mixed>
     */
    protected function json(Response $resposta): array
    {
        $json = $resposta->json();
        $json = is_array($json) ? $json : [];

        if (($erro = $this->primeiroErro($json)) !== null) {
            $e = NotaFiscalApiException::doRetorno($this->nome, $erro['codigo'], $erro['mensagem'], $json);
            $e->statusHttp = $resposta->status();

            throw $e;
        }

        if ($resposta->failed()) {
            $e = NotaFiscalApiException::daResposta($this->nome, $resposta);
            $e->corpo = $json !== [] ? $json : ['corpo' => mb_substr($resposta->body(), 0, 2000)];

            throw $e;
        }

        return $json;
    }

    /**
     * O primeiro erro estruturado, juntando todos na mensagem: a Sefin costuma
     * apontar várias regras numa só rejeição e a primeira nem sempre é a causa.
     *
     * @param  array<string, mixed>  $json
     * @return array{codigo: string, mensagem: string}|null
     */
    protected function primeiroErro(array $json): ?array
    {
        $lista = $json['erros'] ?? $json['Erros'] ?? $json['erro'] ?? $json['Erro'] ?? null;

        if (! is_array($lista) || $lista === []) {
            return null;
        }

        $lista = array_is_list($lista) ? $lista : [$lista];
        $codigos = [];
        $mensagens = [];

        foreach ($lista as $item) {
            if (! is_array($item)) {
                continue;
            }

            $codigo = (string) ($item['Codigo'] ?? $item['codigo'] ?? '?');
            $descricao = (string) ($item['Descricao'] ?? $item['descricao'] ?? '');
            $complemento = (string) ($item['Complemento'] ?? $item['complemento'] ?? '');

            $texto = trim($descricao.($complemento !== '' ? " ({$complemento})" : ''));

            // O primeiro já vai prefixado pela exceção ("Erro E0312: ..."); os demais levam o código.
            $mensagens[] = $codigos === [] ? $texto : "{$codigo}: {$texto}";
            $codigos[] = $codigo;
        }

        if ($codigos === []) {
            return null;
        }

        return ['codigo' => $codigos[0], 'mensagem' => implode(' | ', $mensagens)];
    }

}
