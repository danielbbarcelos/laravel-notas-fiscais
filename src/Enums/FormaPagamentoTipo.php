<?php

declare(strict_types=1);

namespace DanielBBarcelos\NotasFiscais\Enums;

/** Forma de pagamento da NFS-e (campo "tipo_pagamento" do IPM). */
enum FormaPagamentoTipo: int
{
    case AVista = 1;
    case APrazo = 2;
    case Deposito = 3;
    case NaApresentacao = 4;
    case CartaoDebito = 5;
    case CartaoCredito = 6;
    case Cheque = 7;
    case Pix = 8;
}
