<?php

declare(strict_types=1);

namespace DanielBBarcelos\NotasFiscais\Drivers\Nacional;

use DanielBBarcelos\NotasFiscais\Exceptions\NotaFiscalException;

/**
 * Certificado digital A1 (ICP-Brasil) do emitente, lido de um PKCS#12 (.pfx/.p12).
 * O padrão nacional usa o mesmo certificado duas vezes: na conexão (mTLS) e na
 * assinatura XMLDSig da DPS e dos pedidos de evento.
 *
 * O conteúdo chega como os bytes do arquivo ou em Base64 (o jeito natural de
 * guardá-lo cifrado num banco). Ler de um caminho é outra porta, doArquivo(), de
 * propósito: o conteúdo costuma vir de um formulário, e aceitar caminho ali deixaria
 * quem configura apontar para qualquer .pfx do servidor.
 *
 * A chave privada não vai para o disco: o mTLS recebe certificado e chave em
 * memória (CURLOPT_SSLCERT_BLOB / CURLOPT_SSLKEY_BLOB). Só sem esse suporte no
 * curl ela cai num arquivo temporário 0600, apagado no destrutor e também no
 * shutdown do processo, para não sobrar em /tmp se a requisição morrer no meio.
 */
final class Certificado
{
    /** @var list<string> Arquivos temporários criados para o mTLS. */
    private array $temporarios = [];

    private ?string $arquivoCertificado = null;

    private ?string $arquivoChave = null;

    private function __construct(
        public readonly string $certificadoPem,
        private readonly string $chavePrivadaPem,
    ) {
    }

    /**
     * @param  string  $conteudo  bytes do .pfx ou o mesmo em Base64 (nunca um caminho)
     */
    public static function doPfx(string $conteudo, string $senha): self
    {
        $bytes = self::bytes($conteudo);
        $lido = [];

        if (! openssl_pkcs12_read($bytes, $lido, $senha)) {
            throw new NotaFiscalException(
                'Não foi possível abrir o certificado digital: senha incorreta ou arquivo que não é um PKCS#12 (.pfx).'
            );
        }

        return new self((string) $lido['cert'], (string) $lido['pkey']);
    }

    /** Lê o .pfx de um caminho do servidor (config da aplicação, testes), nunca de dado do usuário. */
    public static function doArquivo(string $caminho, string $senha): self
    {
        if (! is_file($caminho) || ! is_readable($caminho)) {
            throw new NotaFiscalException("Arquivo de certificado não encontrado: {$caminho}");
        }

        $bytes = (string) file_get_contents($caminho);
        $lido = [];

        if (! openssl_pkcs12_read($bytes, $lido, $senha)) {
            throw new NotaFiscalException(
                'Não foi possível abrir o certificado digital: senha incorreta ou arquivo que não é um PKCS#12 (.pfx).'
            );
        }

        return new self((string) $lido['cert'], (string) $lido['pkey']);
    }

    /** O certificado em Base64 puro (DER), como vai no <X509Certificate> da assinatura. */
    public function certificadoBase64(): string
    {
        return preg_replace('/-----(BEGIN|END) CERTIFICATE-----|\s/', '', $this->certificadoPem) ?? '';
    }

    /** Assina com RSA-SHA1, o algoritmo do leiaute nacional. */
    public function assinar(string $dados): string
    {
        $chave = openssl_pkey_get_private($this->chavePrivadaPem);

        if ($chave === false || ! openssl_sign($dados, $assinatura, $chave, OPENSSL_ALGO_SHA1)) {
            throw new NotaFiscalException(
                'Falha ao assinar com o certificado digital (RSA-SHA1). Em OpenSSL 3, verifique se a política do sistema permite SHA-1 em assinatura.'
            );
        }

        return $assinatura;
    }

    /** Data de validade do certificado (fim). */
    public function validoAte(): ?\DateTimeImmutable
    {
        $info = openssl_x509_parse($this->certificadoPem);

        return isset($info['validTo_time_t'])
            ? (new \DateTimeImmutable())->setTimestamp((int) $info['validTo_time_t'])
            : null;
    }

    /**
     * Opções do Guzzle para o mTLS: certificado e chave em memória quando o curl
     * suporta (libcurl 7.71+), senão em arquivos temporários.
     *
     * @return array<string, mixed>
     */
    public function opcoesMtls(): array
    {
        if (defined('CURLOPT_SSLCERT_BLOB') && defined('CURLOPT_SSLKEY_BLOB')) {
            return ['curl' => [
                CURLOPT_SSLCERT_BLOB => $this->certificadoPem,
                CURLOPT_SSLCERTTYPE => 'PEM',
                CURLOPT_SSLKEY_BLOB => $this->chavePrivadaPem,
                CURLOPT_SSLKEYTYPE => 'PEM',
            ]];
        }

        return $this->arquivosMtls();
    }

    /**
     * Caminhos de arquivo do certificado e da chave, no formato que o Guzzle
     * aceita em 'cert' e 'ssl_key'. Fallback de opcoesMtls().
     *
     * @return array{cert: string, ssl_key: string}
     */
    public function arquivosMtls(): array
    {
        $this->arquivoCertificado ??= $this->temporario($this->certificadoPem);
        $this->arquivoChave ??= $this->temporario($this->chavePrivadaPem);

        return ['cert' => $this->arquivoCertificado, 'ssl_key' => $this->arquivoChave];
    }

    public function __destruct()
    {
        foreach ($this->temporarios as $arquivo) {
            @unlink($arquivo);
        }
    }

    private function temporario(string $conteudo): string
    {
        $arquivo = tempnam(sys_get_temp_dir(), 'nfse_nac_');

        if ($arquivo === false) {
            throw new NotaFiscalException('Não foi possível criar o arquivo temporário do certificado para o mTLS.');
        }

        chmod($arquivo, 0600);
        file_put_contents($arquivo, $conteudo);
        $this->temporarios[] = $arquivo;

        // O destrutor não roda num fatal error ou num timeout de execução: o shutdown roda.
        register_shutdown_function(static function () use ($arquivo): void {
            @unlink($arquivo);
        });

        return $arquivo;
    }

    private static function bytes(string $conteudo): string
    {
        if ($conteudo === '') {
            throw new NotaFiscalException('Certificado digital não informado.');
        }

        // PKCS#12 em DER começa por SEQUENCE (0x30); Base64 é só ASCII.
        if ($conteudo[0] !== "\x30") {
            $decodificado = base64_decode(preg_replace('/\s/', '', $conteudo) ?? '', true);

            if ($decodificado !== false && $decodificado !== '') {
                return $decodificado;
            }
        }

        return $conteudo;
    }
}
