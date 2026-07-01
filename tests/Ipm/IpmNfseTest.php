<?php

declare(strict_types=1);

use DanielBBarcelos\NotasFiscais\Data\Nfse\Cancelamento;
use DanielBBarcelos\NotasFiscais\Data\Nfse\ItemServico;
use DanielBBarcelos\NotasFiscais\Data\Nfse\NotaServico;
use DanielBBarcelos\NotasFiscais\Data\Nfse\Tomador;
use DanielBBarcelos\NotasFiscais\Data\Shared\Endereco;
use DanielBBarcelos\NotasFiscais\Data\Shared\Valor;
use DanielBBarcelos\NotasFiscais\Enums\Documento;
use DanielBBarcelos\NotasFiscais\Enums\SituacaoNota;
use DanielBBarcelos\NotasFiscais\Enums\SituacaoTributaria;
use DanielBBarcelos\NotasFiscais\Enums\TipoTomador;
use DanielBBarcelos\NotasFiscais\Exceptions\NotaFiscalApiException;
use DanielBBarcelos\NotasFiscais\Facades\NotaFiscal;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

function notaExemplo(): NotaServico
{
    return new NotaServico(
        serie: 1,
        dataFatoGerador: '15/01/2026',
        valorTotal: Valor::reais('1000.00'),
        tomador: new Tomador(
            tipo: TipoTomador::Juridica,
            identificacao: '12.345.678/0001-95',
            nomeRazaoSocial: 'Empresa Tomadora LTDA',
            endereco: new Endereco(
                logradouro: 'Rua das Flores',
                numero: '123',
                bairro: 'Centro',
                codigoMunicipio: '8055',
                cep: '89160-000',
            ),
            email: 'tomador@exemplo.com.br',
        ),
        itens: [
            new ItemServico(
                codigoItemListaServico: '010700',
                descritivo: 'Desenvolvimento de software sob encomenda',
                aliquota: 3.0,
                situacaoTributaria: SituacaoTributaria::TributadaIntegralmente,
                valorTributavel: Valor::reais('1000.00'),
                codigoLocalPrestacao: '8055',
            ),
        ],
    );
}

function retornoEmissao(): string
{
    return <<<'XML'
    <?xml version="1.0" encoding="UTF-8"?>
    <retorno>
        <mensagem>
            <codigo>00001 - Sucesso</codigo>
        </mensagem>
        <numero_nfse>1293</numero_nfse>
        <serie_nfse>1</serie_nfse>
        <data_nfse>28/10/2026</data_nfse>
        <hora_nfse>10:39:12</hora_nfse>
        <situacao_codigo_nfse>1</situacao_codigo_nfse>
        <situacao_descricao_nfse>Emitida</situacao_descricao_nfse>
        <link_nfse>https://riodosul.atende.net/?pg=autoatendimento&amp;identificador=835</link_nfse>
        <cod_verificador_autenticidade>835773809025825307202210281202023961913</cod_verificador_autenticidade>
    </retorno>
    XML;
}

it('emite NFS-e via POST multipart e mapeia o retorno para o DTO canônico', function () {
    Http::fake(['*' => Http::response(retornoEmissao())]);

    $emitida = NotaFiscal::driver('ipm')->nfse()->emitir(notaExemplo());

    expect($emitida->numero)->toBe(1293)
        ->and($emitida->serie)->toBe(1)
        ->and($emitida->situacao)->toBe(SituacaoNota::Emitida)
        ->and($emitida->emitida())->toBeTrue()
        ->and($emitida->codigoVerificacao)->toBe('835773809025825307202210281202023961913')
        ->and($emitida->link)->toContain('atende.net');

    Http::assertSent(function ($request) {
        $corpo = $request->body();

        return $request->method() === 'POST'
            && str_contains($request->url(), 'atende.net')
            && $request->hasHeader('Authorization')
            && str_contains($corpo, 'name="xml"')
            && str_contains($corpo, '<nfse>')
            && str_contains($corpo, '<valor_total>1000,00</valor_total>')
            && str_contains($corpo, '<prestador><cpfcnpj>12345678000199</cpfcnpj><cidade>8055</cidade></prestador>')
            && str_contains($corpo, '<cpfcnpj>12345678000195</cpfcnpj>')
            && str_contains($corpo, '<situacao_tributaria>0</situacao_tributaria>')
            && str_contains($corpo, '<aliquota_item_lista_servico>3,00</aliquota_item_lista_servico>');
    });
});

it('formata valores monetários com vírgula decimal, como o IPM exige', function () {
    Http::fake(['*' => Http::response(retornoEmissao())]);

    NotaFiscal::nfse()->emitir(new NotaServico(
        serie: 1,
        dataFatoGerador: '15/01/2026',
        valorTotal: Valor::reais('1234.50'),
        tomador: new Tomador(
            tipo: TipoTomador::Fisica,
            identificacao: '000.123.123-12',
            nomeRazaoSocial: 'Fulano de Tal',
        ),
        itens: [
            new ItemServico(
                codigoItemListaServico: '010700',
                descritivo: 'Consultoria',
                aliquota: 2.5,
                situacaoTributaria: SituacaoTributaria::Isenta,
                valorTributavel: Valor::reais('1234.50'),
                codigoLocalPrestacao: '8055',
            ),
        ],
    ));

    Http::assertSent(function ($request) {
        $corpo = $request->body();

        return str_contains($corpo, '<valor_total>1234,50</valor_total>')
            && str_contains($corpo, '<valor_tributavel>1234,50</valor_tributavel>')
            && str_contains($corpo, '<tomador><tipo>F</tipo><cpfcnpj>00012312312</cpfcnpj>');
    });
});

it('cancela NFS-e enviando situacao C e mapeia para Cancelada', function () {
    Http::fake(['*' => Http::response(<<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <retorno>
            <mensagem><codigo>00001 - Sucesso</codigo></mensagem>
            <numero_nfse>1293</numero_nfse>
            <serie_nfse>1</serie_nfse>
            <situacao_codigo_nfse>2</situacao_codigo_nfse>
            <situacao_descricao_nfse>Cancelada</situacao_descricao_nfse>
        </retorno>
        XML)]);

    $resultado = NotaFiscal::driver('ipm')->nfse()->cancelar(
        new Cancelamento(numero: 1293, serie: 1, motivo: 'Erro de digitação'),
    );

    expect($resultado->situacao)->toBe(SituacaoNota::Cancelada)
        ->and($resultado->cancelada())->toBeTrue();

    Http::assertSent(function ($request) {
        $corpo = $request->body();

        return str_contains($corpo, '<numero>1293</numero>')
            && str_contains($corpo, '<serie_nfse>1</serie_nfse>')
            && str_contains($corpo, '<situacao>C</situacao>')
            && str_contains($corpo, '<observacao>Erro de digitação</observacao>');
    });
});

it('consulta por número, série e cadastro', function () {
    Http::fake(['*' => Http::response(retornoEmissao())]);

    $nota = NotaFiscal::driver('ipm')->nfse()->consultar(1293, 1, '8055');

    expect($nota->numero)->toBe(1293);

    Http::assertSent(fn ($request) => str_contains($request->body(), '<pesquisa><numero>1293</numero><serie_nfse>1</serie_nfse><cadastro>8055</cadastro></pesquisa>'));
});

it('consulta pelo código de autenticidade', function () {
    Http::fake(['*' => Http::response(retornoEmissao())]);

    NotaFiscal::driver('ipm')->nfse()->consultarPorAutenticidade('835773809025825307202210281202023961913');

    Http::assertSent(fn ($request) => str_contains($request->body(), '<codigo_autenticidade>835773809025825307202210281202023961913</codigo_autenticidade>'));
});

it('converte erro de negócio do IPM (HTTP 200) em NotaFiscalApiException', function () {
    Http::fake(['*' => Http::response(<<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <retorno>
            <mensagem><codigo>00128 - Erro na validação dos dados</codigo></mensagem>
        </retorno>
        XML)]);

    expect(fn () => NotaFiscal::driver('ipm')->nfse()->emitir(notaExemplo()))
        ->toThrow(function (NotaFiscalApiException $e) {
            expect($e->codigo)->toBe('00128')
                ->and($e->getMessage())->toContain('Erro na validação dos dados');
        });
});

it('resolve provedor com credenciais em runtime via build (multi-tenant)', function () {
    Http::fake(['*' => Http::response(retornoEmissao())]);

    NotaFiscal::build([
        'driver' => 'ipm',
        'base_url' => 'https://tenant-a.atende.net/atende.php?pg=rest&service=WNERestServiceNFSe',
        'cpf_cnpj' => '99999999000199',
        'senha' => 'segredo-do-tenant-a',
        'cidade' => '7107',
    ])->nfse()->emitir(notaExemplo());

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'tenant-a.atende.net')
            // prestador padrão vem da config do tenant, não do .env do app
            && str_contains($request->body(), '<prestador><cpfcnpj>99999999000199</cpfcnpj><cidade>7107</cidade></prestador>')
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('99999999000199:segredo-do-tenant-a'));
    });
});

it('sobrepõe credenciais sobre a config nomeada via driver($nome, $overrides)', function () {
    Http::fake(['*' => Http::response(retornoEmissao())]);

    NotaFiscal::driver('ipm', [
        'base_url' => 'https://tenant-b.atende.net/ws',
        'cpf_cnpj' => '88888888000188',
        'senha' => 'segredo-do-tenant-b',
        'cidade' => '1234',
    ])->nfse()->emitir(notaExemplo());

    Http::assertSent(fn ($request) => str_contains($request->url(), 'tenant-b.atende.net')
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('88888888000188:segredo-do-tenant-b')));
});

it('não cacheia (nem compartilha) instâncias resolvidas em runtime entre tenants', function () {
    $configA = ['driver' => 'ipm', 'base_url' => 'https://a.atende.net', 'cpf_cnpj' => '1', 'senha' => 'a', 'cidade' => '1'];
    $configB = ['driver' => 'ipm', 'base_url' => 'https://b.atende.net', 'cpf_cnpj' => '2', 'senha' => 'b', 'cidade' => '2'];

    expect(NotaFiscal::build($configA))->not->toBe(NotaFiscal::build($configA))
        ->and(NotaFiscal::build($configA))->not->toBe(NotaFiscal::build($configB));
});

it('build exige a chave driver na configuração', function () {
    expect(fn () => NotaFiscal::build(['base_url' => 'https://x.atende.net']))
        ->toThrow(\DanielBBarcelos\NotasFiscais\Exceptions\NotaFiscalException::class, "não define a chave 'driver'");
});

it('expõe o suporte por tipo de documento', function () {
    $provedor = NotaFiscal::driver('ipm');

    expect($provedor->nome())->toBe('ipm')
        ->and($provedor->suporta(Documento::Nfse))->toBeTrue()
        ->and($provedor->suporta(Documento::Nfe))->toBeFalse();
});
