<?php

declare(strict_types=1);

namespace DanielBBarcelos\NotasFiscais\Contracts;

use DanielBBarcelos\NotasFiscais\Enums\Documento;

/**
 * Ponto de entrada de um provedor de nota fiscal. Expõe os gateways por tipo de
 * documento (ISP): nem todo provedor emite todo documento, então consulte
 * suporta() antes de chamar um gateway que possa lançar
 * OperacaoNaoSuportadaException.
 */
interface Provedor
{
    /** Identificador do driver (ex.: "ipm"). */
    public function nome(): string;

    public function suporta(Documento $documento): bool;

    public function nfse(): NfseGateway;

    // Reservado para expansão futura (NF-e de produto, NFC-e):
    // public function nfe(): NfeGateway;
}
