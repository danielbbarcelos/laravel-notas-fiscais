<?php

declare(strict_types=1);

namespace DanielBBarcelos\NotasFiscais\Drivers\IpmAbrasf;

use DanielBBarcelos\NotasFiscais\Exceptions\NotaFiscalException;
use DOMDocument;
use DOMElement;

/**
 * Helpers para o transporte SOAP/ABRASF do IPM. Observações importantes,
 * extraídas de uma integração funcional em produção (Pouso Alegre/MG):
 *
 *  - O envelope declara apenas o namespace soapenv; os elementos ABRASF vão
 *    SEM xmlns próprio (o servidor IPM rejeita xmlns duplicado).
 *  - A leitura da resposta é por nome local da tag (ignorando namespace), pois
 *    a resposta vem com o namespace padrão da ABRASF.
 */
final class AbrasfXml
{
    public const SOAP = 'http://schemas.xmlsoap.org/soap/envelope/';

    /**
     * Cria um documento com o envelope SOAP e devolve [documento, corpo] para
     * o chamador anexar o conteúdo ABRASF dentro do <soapenv:Body>.
     *
     * @return array{0: DOMDocument, 1: DOMElement}
     */
    public static function envelope(): array
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = false;

        $env = $dom->createElementNS(self::SOAP, 'soapenv:Envelope');
        $dom->appendChild($env);
        $env->appendChild($dom->createElementNS(self::SOAP, 'soapenv:Header'));

        $body = $dom->createElementNS(self::SOAP, 'soapenv:Body');
        $env->appendChild($body);

        return [$dom, $body];
    }

    public static function parse(string $xml): DOMDocument
    {
        $limpo = trim($xml);

        if ($limpo === '') {
            throw new NotaFiscalException('Resposta SOAP vazia.');
        }

        $dom = new DOMDocument;
        $anterior = libxml_use_internal_errors(true);
        $ok = $dom->loadXML($limpo);
        libxml_use_internal_errors($anterior);

        if (! $ok) {
            throw new NotaFiscalException('Resposta SOAP não é um XML válido: '.mb_substr($limpo, 0, 200));
        }

        return $dom;
    }

    /** Texto da primeira tag com o nome local informado (qualquer namespace). */
    public static function texto(DOMDocument $dom, string $localName): ?string
    {
        $nodes = $dom->getElementsByTagNameNS('*', $localName);

        if ($nodes->length === 0) {
            $nodes = $dom->getElementsByTagName($localName);
        }

        if ($nodes->length === 0) {
            return null;
        }

        $texto = trim($nodes->item(0)->textContent);

        return $texto === '' ? null : $texto;
    }

    /**
     * Todos os elementos com o nome local informado (qualquer namespace).
     *
     * @return list<DOMElement>
     */
    public static function elementos(DOMDocument $dom, string $localName): array
    {
        $nodes = $dom->getElementsByTagNameNS('*', $localName);

        if ($nodes->length === 0) {
            $nodes = $dom->getElementsByTagName($localName);
        }

        $out = [];
        foreach ($nodes as $node) {
            if ($node instanceof DOMElement) {
                $out[] = $node;
            }
        }

        return $out;
    }

    /**
     * Monta o link da NFS-e a partir de um template configurado, quando a
     * resposta não traz o link. Substitui {codigo} e {numero}.
     */
    public static function montarLink(?string $template, ?string $codigo, ?string $numero): ?string
    {
        if ($template === null || $template === '' || $codigo === null) {
            return null;
        }

        return strtr($template, ['{codigo}' => $codigo, '{numero}' => $numero ?? '']);
    }

    /** Texto de um filho (por nome local) de um elemento. */
    public static function filho(DOMElement $pai, string $localName): ?string
    {
        foreach ($pai->childNodes as $filho) {
            if ($filho instanceof DOMElement && $filho->localName === $localName) {
                $texto = trim($filho->textContent);

                return $texto === '' ? null : $texto;
            }
        }

        return null;
    }
}
