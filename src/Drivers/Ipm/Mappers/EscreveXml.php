<?php

declare(strict_types=1);

namespace DanielBBarcelos\NotasFiscais\Drivers\Ipm\Mappers;

use DOMDocument;
use DOMElement;

/**
 * Helpers para montar o XML do IPM via DOMDocument, que cuida do escape de
 * caracteres especiais (&, <, >) automaticamente.
 */
trait EscreveXml
{
    protected function add(DOMDocument $dom, DOMElement $pai, string $tag, string $valor): void
    {
        $el = $dom->createElement($tag);
        $el->appendChild($dom->createTextNode($valor));
        $pai->appendChild($el);
    }

    protected function novoDocumento(): DOMDocument
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = false;

        return $dom;
    }
}
