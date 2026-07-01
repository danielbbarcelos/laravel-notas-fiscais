<?php

declare(strict_types=1);

namespace DanielBBarcelos\NotasFiscais\Enums;

/**
 * Tipos de documento fiscal que um provedor pode (ou não) emitir. Use
 * Provedor::suporta() para checar antes de chamar um gateway que possa não
 * existir no driver. Hoje só NFS-e está implementada; NF-e/NFC-e estão
 * reservadas para expansão.
 */
enum Documento: string
{
    case Nfse = 'nfse';
    case Nfe = 'nfe';
    case Nfce = 'nfce';
}
