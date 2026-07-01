<?php

declare(strict_types=1);

namespace DanielBBarcelos\NotasFiscais\Drivers\IpmAbrasf\Mappers;

use DanielBBarcelos\NotasFiscais\Data\Nfse\NotaEmitida;
use DanielBBarcelos\NotasFiscais\Data\Nfse\Prestador;
use DanielBBarcelos\NotasFiscais\Drivers\IpmAbrasf\AbrasfXml;
use DanielBBarcelos\NotasFiscais\Enums\SituacaoNota;
use DOMDocument;
use DOMElement;
use InvalidArgumentException;

/** Monta o SOAP ConsultarNfseFaixaEnvio (ABRASF) e faz o parse do retorno. */
class ConsultaAbrasfMapper
{
    public function porNumero(int $numero, ?Prestador $prestador): string
    {
        if ($prestador === null) {
            throw new InvalidArgumentException(
                'Prestador não informado: defina-o em notas-fiscais.drivers.{provedor} para consultar.'
            );
        }

        [$dom, $body] = AbrasfXml::envelope();

        $consultar = $this->el($dom, $body, 'ConsultarNfseFaixaEnvio');

        $prest = $this->el($dom, $consultar, 'Prestador');
        $cpfcnpj = $this->el($dom, $prest, 'CpfCnpj');
        $this->add($dom, $cpfcnpj, 'Cnpj', $prestador->cpfCnpjLimpo());
        if ($prestador->inscricaoMunicipal !== null) {
            $this->add($dom, $prest, 'InscricaoMunicipal', $prestador->inscricaoMunicipal);
        }

        $faixa = $this->el($dom, $consultar, 'Faixa');
        $this->add($dom, $faixa, 'NumeroNfseInicial', (string) $numero);
        $this->add($dom, $faixa, 'NumeroNfseFinal', (string) $numero);

        $this->add($dom, $consultar, 'Pagina', '1');

        return (string) $dom->saveXML();
    }

    public function paraDominio(string $xmlResposta, ?string $linkTemplate = null): NotaEmitida
    {
        $dom = AbrasfXml::parse($xmlResposta);

        $numero = AbrasfXml::texto($dom, 'Numero');
        $codigo = AbrasfXml::texto($dom, 'CodigoVerificacao');
        $situacaoTexto = AbrasfXml::texto($dom, 'SituacaoNfse') ?? AbrasfXml::texto($dom, 'Situacao');

        $situacao = null;
        if ($situacaoTexto !== null) {
            $cancelada = $situacaoTexto === '2' || stripos($situacaoTexto, 'cancel') !== false;
            $situacao = $cancelada ? SituacaoNota::Cancelada : SituacaoNota::Emitida;
        } elseif ($numero !== null) {
            $situacao = SituacaoNota::Emitida;
        }

        return new NotaEmitida(
            numero: $numero !== null ? (int) $numero : null,
            serie: null,
            data: AbrasfXml::texto($dom, 'DataEmissao'),
            hora: null,
            situacao: $situacao,
            codigoVerificacao: $codigo,
            link: AbrasfXml::texto($dom, 'link_nfse') ?? AbrasfXml::texto($dom, 'LinkNfse')
                ?? AbrasfXml::montarLink($linkTemplate, $codigo, $numero),
            bruto: ['xml_response' => $xmlResposta],
        );
    }

    protected function el(DOMDocument $dom, DOMElement $pai, string $tag): DOMElement
    {
        $el = $dom->createElement($tag);
        $pai->appendChild($el);

        return $el;
    }

    protected function add(DOMDocument $dom, DOMElement $pai, string $tag, string $valor): void
    {
        $el = $dom->createElement($tag);
        $el->appendChild($dom->createTextNode($valor));
        $pai->appendChild($el);
    }
}
