<?php

declare(strict_types=1);

namespace DanielBBarcelos\NotasFiscais\Drivers\Nacional;

use DanielBBarcelos\NotasFiscais\Exceptions\NotaFiscalException;
use DOMDocument;
use DOMElement;

/**
 * Helpers do padrão nacional: o namespace do leiaute, o envelope GZip+Base64 que
 * a API usa nos campos *XmlGZipB64 e a leitura por nome local de tag.
 */
final class NacionalXml
{
    public const NS = 'http://www.sped.fazenda.gov.br/nfse';

    /** XML -> GZip -> Base64, o formato dos campos dpsXmlGZipB64 e afins. */
    public static function compactar(string $xml): string
    {
        $gzip = gzencode($xml, 9);

        if ($gzip === false) {
            throw new NotaFiscalException('Falha ao comprimir o XML (GZip).');
        }

        return base64_encode($gzip);
    }

    /** Base64 -> GZip -> XML. */
    public static function descompactar(string $base64): string
    {
        $bytes = base64_decode($base64, true);
        $xml = $bytes !== false ? @gzdecode($bytes) : false;

        if ($xml === false) {
            throw new NotaFiscalException('Resposta do Sistema Nacional com XML em GZip/Base64 inválido.');
        }

        return $xml;
    }

    public static function parse(string $xml): DOMDocument
    {
        $dom = new DOMDocument();
        $anterior = libxml_use_internal_errors(true);
        $ok = $dom->loadXML(trim($xml));
        libxml_use_internal_errors($anterior);

        if (! $ok) {
            throw new NotaFiscalException('XML do Sistema Nacional inválido: '.mb_substr(trim($xml), 0, 200));
        }

        return $dom;
    }

    /** Texto da primeira tag com o nome local informado (qualquer namespace). */
    public static function texto(DOMDocument|DOMElement $raiz, string $localName): ?string
    {
        $nodes = $raiz->getElementsByTagNameNS('*', $localName);

        if ($nodes->length === 0) {
            return null;
        }

        $texto = trim($nodes->item(0)->textContent);

        return $texto === '' ? null : $texto;
    }

    /** Atributo de um elemento por nome local (qualquer namespace). */
    public static function atributo(DOMDocument $dom, string $localName, string $atributo): ?string
    {
        $node = $dom->getElementsByTagNameNS('*', $localName)->item(0);

        return $node instanceof DOMElement && $node->hasAttribute($atributo) ? $node->getAttribute($atributo) : null;
    }

    /** Apenas dígitos. */
    public static function digitos(?string $valor): string
    {
        return preg_replace('/\D/', '', (string) $valor) ?? '';
    }

    /** Remove o que o XSD não aceita em TSString (controle e espaços nas pontas). */
    public static function texto2000(string $texto, int $limite = 2000): string
    {
        $limpo = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $texto) ?? '');

        return mb_substr($limpo, 0, $limite);
    }
}
