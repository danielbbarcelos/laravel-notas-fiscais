<?php

declare(strict_types=1);

namespace DanielBBarcelos\NotasFiscais\Drivers\Ipm\Mappers;

/** Monta os XMLs de consulta de NFS-e do IPM (por número/série/cadastro ou por autenticidade). */
class ConsultaMapper
{
    use EscreveXml;

    public function porNumero(int $numero, int $serie, string $cadastro): string
    {
        $dom = $this->novoDocumento();

        $nfse = $dom->createElement('nfse');
        $dom->appendChild($nfse);

        $pesquisa = $dom->createElement('pesquisa');
        $nfse->appendChild($pesquisa);
        $this->add($dom, $pesquisa, 'numero', (string) $numero);
        $this->add($dom, $pesquisa, 'serie_nfse', (string) $serie);
        $this->add($dom, $pesquisa, 'cadastro', $cadastro);

        return (string) $dom->saveXML();
    }

    public function porAutenticidade(string $codigo): string
    {
        $dom = $this->novoDocumento();

        $nfse = $dom->createElement('nfse');
        $dom->appendChild($nfse);

        $pesquisa = $dom->createElement('pesquisa');
        $nfse->appendChild($pesquisa);
        $this->add($dom, $pesquisa, 'codigo_autenticidade', $codigo);

        return (string) $dom->saveXML();
    }
}
