<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;

beforeEach(function () {
    $this->withoutMiddleware(VerifyCsrfToken::class);
});

it('renderiza o playground quando habilitado', function () {
    $this->get('/notas-fiscais/demo')
        ->assertOk()
        ->assertSee('Playground');
});

it('emite no modo faked (cenário sucesso) e mostra o XML gerado e o número', function () {
    $this->post('/notas-fiscais/demo/emitir', [
        'backend' => 'faked',
        'cenario' => 'sucesso',
        'serie' => '1',
        'data_fato_gerador' => '15/01/2026',
        'valor' => '1000.00',
        'tomador_tipo' => 'J',
        'tomador_doc' => '12.345.678/0001-95',
        'tomador_nome' => 'Empresa Tomadora LTDA',
        'item_codigo' => '010700',
        'item_descritivo' => 'Software',
        'item_aliquota' => '3',
        'item_situacao' => '0',
        'end_cidade' => '8055',
    ])
        ->assertOk()
        ->assertSee('1293')
        ->assertSee('Emitida')
        ->assertSee('valor_total'); // XML gerado aparece na tela
});

it('emite no padrão ABRASF (faked) e mostra o envelope SOAP gerado', function () {
    $this->post('/notas-fiscais/demo/emitir', [
        'padrao' => 'ipm-abrasf',
        'backend' => 'faked',
        'cenario' => 'sucesso',
        'serie' => '1',
        'data_fato_gerador' => '30/06/2026',
        'competencia' => '2026-06-01',
        'valor' => '1000.00',
        'tomador_tipo' => 'J',
        'tomador_doc' => '11.222.333/0001-44',
        'tomador_nome' => 'Cliente ABRASF',
        'end_cidade' => '3152501',
        'end_uf' => 'MG',
        'item_codigo' => '10.01.01',
        'item_descritivo' => 'Software',
        'item_aliquota' => '2',
        'item_situacao' => '0',
        'item_cnae' => '6201501',
    ])
        ->assertOk()
        ->assertSee('1293')
        ->assertSee('GerarNfseEnvio')
        ->assertSee('InfDeclaracaoPrestacaoServico');
});

it('mostra o erro de negócio no modo faked (cenário erro)', function () {
    $this->post('/notas-fiscais/demo/emitir', [
        'backend' => 'faked',
        'cenario' => 'erro',
        'valor' => '1000.00',
        'tomador_tipo' => 'J',
        'tomador_doc' => '12345678000195',
        'tomador_nome' => 'X',
        'item_codigo' => '010700',
        'item_descritivo' => 'Y',
        'item_aliquota' => '3',
        'item_situacao' => '0',
        'end_cidade' => '8055',
    ])
        ->assertOk()
        ->assertSee('00128');
});
