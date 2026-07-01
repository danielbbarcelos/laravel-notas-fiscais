<?php

declare(strict_types=1);

namespace DanielBBarcelos\NotasFiscais\Facades;

use DanielBBarcelos\NotasFiscais\Contracts\NfseGateway;
use DanielBBarcelos\NotasFiscais\Contracts\Provedor;
use DanielBBarcelos\NotasFiscais\NotaFiscalManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static Provedor          provedor(?string $nome = null, array $overrides = [])
 * @method static Provedor          driver(?string $nome = null, array $overrides = [])
 * @method static Provedor          build(array $config, ?string $nome = null)
 * @method static NfseGateway       nfse()
 * @method static NotaFiscalManager extend(string $driver, \Closure $factory)
 * @method static string            provedorPadrao()
 *
 * @see \DanielBBarcelos\NotasFiscais\NotaFiscalManager
 */
class NotaFiscal extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return NotaFiscalManager::class;
    }
}
