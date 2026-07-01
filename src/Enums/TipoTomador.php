<?php

declare(strict_types=1);

namespace DanielBBarcelos\NotasFiscais\Enums;

/** Tipo de pessoa do tomador do serviço. */
enum TipoTomador: string
{
    case Fisica = 'F';
    case Juridica = 'J';
    case Estrangeiro = 'E';
}
