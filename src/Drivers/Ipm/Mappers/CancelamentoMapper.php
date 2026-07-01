<?php

declare(strict_types=1);

namespace DanielBBarcelos\NotasFiscais\Drivers\Ipm\Mappers;

use DanielBBarcelos\NotasFiscais\Data\Nfse\Cancelamento;
use DanielBBarcelos\NotasFiscais\Data\Nfse\Prestador;
use InvalidArgumentException;

/** De-para do DTO de cancelamento para o XML <nfse> do IPM (CancelarNfseEnvio). */
class CancelamentoMapper
{
    use EscreveXml;

    public function paraApi(Cancelamento $dados, ?Prestador $prestadorPadrao = null): string
    {
        $prestador = $dados->prestador ?? $prestadorPadrao;

        if ($prestador === null) {
            throw new InvalidArgumentException(
                'Prestador não informado: defina-o no Cancelamento ou em notas-fiscais.drivers.{provedor}.'
            );
        }

        $dom = $this->novoDocumento();

        $nfse = $dom->createElement('nfse');
        $dom->appendChild($nfse);

        $nf = $dom->createElement('nf');
        $nfse->appendChild($nf);
        $this->add($dom, $nf, 'numero', (string) $dados->numero);
        $this->add($dom, $nf, 'serie_nfse', (string) $dados->serie);
        $this->add($dom, $nf, 'situacao', 'C');
        $this->add($dom, $nf, 'observacao', $dados->motivo);

        $p = $dom->createElement('prestador');
        $nfse->appendChild($p);
        $this->add($dom, $p, 'cpfcnpj', $prestador->cpfCnpjLimpo());
        $this->add($dom, $p, 'cidade', $prestador->codigoMunicipio);

        return (string) $dom->saveXML();
    }
}
