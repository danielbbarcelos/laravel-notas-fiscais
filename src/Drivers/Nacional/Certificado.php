<?php

declare(strict_types=1);

namespace DanielBBarcelos\NotasFiscais\Drivers\Nacional;

use DanielBBarcelos\NotasFiscais\Exceptions\NotaFiscalException;

/**
 * Certificado digital A1 (ICP-Brasil) do emitente, lido de um PKCS#12 (.pfx/.p12).
 * O padrão nacional usa o mesmo certificado duas vezes: na conexão (mTLS) e na
 * assinatura XMLDSig da DPS e dos pedidos de evento.
 *
 * O conteúdo pode chegar como os bytes do arquivo, em Base64 (o jeito natural de
 * guardá-lo cifrado num banco) ou como caminho de arquivo. A chave privada nunca
 * sai daqui em claro, exceto pelos arquivos temporários que o Guzzle exige para o
 * mTLS, apagados no fim do processo.
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
     * @param  string  $conteudo  bytes do .pfx, o mesmo em Base64, ou o caminho do arquivo
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
     * Caminhos de arquivo do certificado e da chave, no formato que o Guzzle
     * aceita em 'cert' e 'ssl_key'.
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

        return $arquivo;
    }

    private static function bytes(string $conteudo): string
    {
        if ($conteudo === '') {
            throw new NotaFiscalException('Certificado digital não informado.');
        }

        // Um caminho é curto e sem quebra; o conteúdo de um .pfx nunca é.
        if (strlen($conteudo) < 4096 && ! str_contains($conteudo, "\0") && is_file($conteudo)) {
            return (string) file_get_contents($conteudo);
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
