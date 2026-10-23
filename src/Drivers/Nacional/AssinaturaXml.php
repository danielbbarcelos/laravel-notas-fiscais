<?php

declare(strict_types=1);

namespace DanielBBarcelos\NotasFiscais\Drivers\Nacional;

use DanielBBarcelos\NotasFiscais\Exceptions\NotaFiscalException;
use DOMDocument;
use DOMElement;

/**
 * Assinatura XMLDSig envelopada, no perfil que o Sistema Nacional NFS-e aceita
 * (o mesmo da NF-e): C14N inclusiva, digest SHA-1, assinatura RSA-SHA1 e o
 * certificado no <KeyInfo>. A <Signature> entra como irmã do elemento assinado
 * (infDPS, infPedReg), referenciando o atributo Id dele.
 *
 * Implementação nativa (DOM + OpenSSL), para não puxar dependência só por isto.
 */
final class AssinaturaXml
{
    public const DSIG = 'http://www.w3.org/2000/09/xmldsig#';

    private const C14N = 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315';

    /**
     * Assina o elemento $tag (que deve ter o atributo Id) e devolve o documento
     * serializado, sem declaração XML, pronto para comprimir e enviar.
     */
    public static function assinar(DOMDocument $dom, string $tag, Certificado $certificado): string
    {
        $alvo = $dom->getElementsByTagName($tag)->item(0);

        if (! $alvo instanceof DOMElement || ! $alvo->hasAttribute('Id')) {
            throw new NotaFiscalException("Elemento <{$tag}> com atributo Id não encontrado para assinar.");
        }

        $id = $alvo->getAttribute('Id');
        $digest = base64_encode(sha1((string) $alvo->C14N(false, false), true));

        $signature = $dom->createElementNS(self::DSIG, 'Signature');
        $alvo->parentNode->appendChild($signature);

        $signedInfo = self::el($dom, $signature, 'SignedInfo');
        self::el($dom, $signedInfo, 'CanonicalizationMethod')->setAttribute('Algorithm', self::C14N);
        self::el($dom, $signedInfo, 'SignatureMethod')->setAttribute('Algorithm', self::DSIG.'rsa-sha1');

        $reference = self::el($dom, $signedInfo, 'Reference');
        $reference->setAttribute('URI', '#'.$id);
        $transforms = self::el($dom, $reference, 'Transforms');
        self::el($dom, $transforms, 'Transform')->setAttribute('Algorithm', self::DSIG.'enveloped-signature');
        self::el($dom, $transforms, 'Transform')->setAttribute('Algorithm', self::C14N);
        self::el($dom, $reference, 'DigestMethod')->setAttribute('Algorithm', self::DSIG.'sha1');
        self::el($dom, $reference, 'DigestValue', $digest);

        // O SignedInfo é canonicalizado já no lugar, com o namespace herdado da <Signature>.
        $valor = base64_encode($certificado->assinar((string) $signedInfo->C14N(false, false)));
        self::el($dom, $signature, 'SignatureValue', $valor);

        $keyInfo = self::el($dom, $signature, 'KeyInfo');
        $x509 = self::el($dom, $keyInfo, 'X509Data');
        self::el($dom, $x509, 'X509Certificate', $certificado->certificadoBase64());

        return (string) $dom->saveXML($dom->documentElement);
    }

    /**
     * Confere a assinatura de um documento assinado por assinar(). Serve aos
     * testes e a quem quiser validar o XML antes de enviar.
     */
    public static function valida(string $xml, string $tag): bool
    {
        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = false;
        $dom->loadXML($xml);

        $alvo = $dom->getElementsByTagName($tag)->item(0);
        $signature = $dom->getElementsByTagNameNS(self::DSIG, 'Signature')->item(0);

        if (! $alvo instanceof DOMElement || ! $signature instanceof DOMElement) {
            return false;
        }

        $digestValue = $signature->getElementsByTagNameNS(self::DSIG, 'DigestValue')->item(0)?->textContent;
        $signatureValue = $signature->getElementsByTagNameNS(self::DSIG, 'SignatureValue')->item(0)?->textContent;
        $certificado = $signature->getElementsByTagNameNS(self::DSIG, 'X509Certificate')->item(0)?->textContent;
        $signedInfo = $signature->getElementsByTagNameNS(self::DSIG, 'SignedInfo')->item(0);

        if ($digestValue === null || $signatureValue === null || $certificado === null || ! $signedInfo instanceof DOMElement) {
            return false;
        }

        // O digest é do elemento sem a assinatura (transform enveloped-signature):
        // como a <Signature> é irmã do alvo, e não filha, o alvo já sai intacto.
        if (base64_encode(sha1((string) $alvo->C14N(false, false), true)) !== $digestValue) {
            return false;
        }

        $pem = "-----BEGIN CERTIFICATE-----\n".chunk_split(trim($certificado), 64, "\n")."-----END CERTIFICATE-----\n";

        return openssl_verify((string) $signedInfo->C14N(false, false), (string) base64_decode($signatureValue), $pem, OPENSSL_ALGO_SHA1) === 1;
    }

    private static function el(DOMDocument $dom, DOMElement $pai, string $tag, ?string $texto = null): DOMElement
    {
        $el = $dom->createElementNS(self::DSIG, $tag);

        if ($texto !== null) {
            $el->appendChild($dom->createTextNode($texto));
        }

        $pai->appendChild($el);

        return $el;
    }
}
