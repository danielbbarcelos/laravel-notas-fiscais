<?php

declare(strict_types=1);

namespace DanielBBarcelos\NotasFiscais\Tests;

use DanielBBarcelos\NotasFiscais\NotasFiscaisServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [NotasFiscaisServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('notas-fiscais.default', 'ipm');
        $app['config']->set('notas-fiscais.drivers.ipm', [
            'driver' => 'ipm',
            'base_url' => 'https://riodosul.atende.net/atende.php?pg=rest&service=WNERestServiceNFSe&cidade=padrao',
            'cpf_cnpj' => '12345678000199',
            'senha' => 'segredo-teste',
            'cidade' => '8055',
            'timeout' => 10,
        ]);

        $app['config']->set('notas-fiscais.drivers.ipm-abrasf', [
            'driver' => 'ipm-abrasf',
            'base_url' => 'https://pousoalegre.atende.net/?pg=services&service=WNENotaFiscalEletronicaNfe',
            'cpf_cnpj' => '24100499000177',
            'senha' => 'segredo-teste',
            'inscricao_municipal' => '84592',
            'codigo_ibge' => '3152501',
            'timeout' => 10,
        ]);
    }
}
