<?php

declare(strict_types=1);

use DanielBBarcelos\NotasFiscais\Drivers\Ipm\IpmConnector;
use DanielBBarcelos\NotasFiscais\Drivers\IpmAbrasf\AbrasfSoapConnector;
use Illuminate\Http\Client\PendingRequest;

/** Expõe o PendingRequest montado pelo conector, sem passar pela rede. */
function opcoesDoCliente(object $connector): array
{
    $req = (fn (): PendingRequest => $this->cliente())->call($connector);

    return $req->getOptions();
}

it('repassa o proxy configurado ao cliente HTTP do driver IPM', function () {
    $opcoes = opcoesDoCliente(new IpmConnector(
        ['base_url' => 'https://exemplo.atende.net', 'proxy' => 'http://user:senha@10.0.0.1:3128'],
        'ipm',
    ));

    expect($opcoes['proxy'])->toBe('http://user:senha@10.0.0.1:3128');
});

it('repassa o proxy configurado ao cliente HTTP do driver IPM ABRASF', function () {
    $opcoes = opcoesDoCliente(new AbrasfSoapConnector(
        ['base_url' => 'https://exemplo.atende.net', 'proxy' => 'socks5://10.0.0.1:1080'],
        'ipm-abrasf',
    ));

    expect($opcoes['proxy'])->toBe('socks5://10.0.0.1:1080');
});

it('não define proxy quando a configuração está ausente ou vazia', function (mixed $valor) {
    $config = ['base_url' => 'https://exemplo.atende.net'];

    if ($valor !== 'ausente') {
        $config['proxy'] = $valor;
    }

    expect(opcoesDoCliente(new IpmConnector($config, 'ipm')))->not->toHaveKey('proxy')
        ->and(opcoesDoCliente(new AbrasfSoapConnector($config, 'ipm-abrasf')))->not->toHaveKey('proxy');
})->with(['ausente', null, '']);
