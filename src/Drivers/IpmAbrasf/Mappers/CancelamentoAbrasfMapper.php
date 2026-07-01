<?php

declare(strict_types=1);

namespace DanielBBarcelos\NotasFiscais\Drivers\IpmAbrasf\Mappers;

use DanielBBarcelos\NotasFiscais\Data\Nfse\Cancelamento;
use DanielBBarcelos\NotasFiscais\Data\Nfse\Prestador;
use DanielBBarcelos\NotasFiscais\Drivers\IpmAbrasf\AbrasfXml;
use DOMDocument;
use DOMElement;
use InvalidArgumentException;

/** De-para do cancelamento para o SOAP CancelarNfseEnvio (ABRASF). */
class CancelamentoAbrasfMapper
{
    public function paraApi(Cancelamento $dados, ?Prestador $prestador): string
    {
        if ($prestador === null) {
            throw new InvalidArgumentException(
                'Prestador não informado: defina-o no Cancelamento ou em notas-fiscais.drivers.{provedor}.'
            );
        }

        [$dom, $body] = AbrasfXml::envelope();

        $cancelar = $this->el($dom, $body, 'CancelarNfseEnvio');
        $pedido = $this->el($dom, $cancelar, 'Pedido');
        $inf = $this->el($dom, $pedido, 'InfPedidoCancelamento');
        $inf->setAttribute('Id', 'CANC_'.$dados->numero);

        $idNfse = $this->el($dom, $inf, 'IdentificacaoNfse');
        $this->add($dom, $idNfse, 'Numero', (string) $dados->numero);
        $cpfcnpj = $this->el($dom, $idNfse, 'CpfCnpj');
        $this->add($dom, $cpfcnpj, 'Cnpj', $prestador->cpfCnpjLimpo());
        if ($prestador->inscricaoMunicipal !== null) {
            $this->add($dom, $idNfse, 'InscricaoMunicipal', $prestador->inscricaoMunicipal);
        }
        $this->add($dom, $idNfse, 'CodigoMunicipio', $prestador->codigoMunicipio);

        $this->add($dom, $inf, 'CodigoCancelamento', '1');

        return (string) $dom->saveXML();
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
