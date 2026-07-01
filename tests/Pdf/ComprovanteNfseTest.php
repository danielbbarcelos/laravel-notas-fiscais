<?php

declare(strict_types=1);

use DanielBBarcelos\NotasFiscais\Data\Nfse\ItemServico;
use DanielBBarcelos\NotasFiscais\Data\Nfse\NotaEmitida;
use DanielBBarcelos\NotasFiscais\Data\Nfse\NotaServico;
use DanielBBarcelos\NotasFiscais\Data\Nfse\Prestador;
use DanielBBarcelos\NotasFiscais\Data\Nfse\Tomador;
use DanielBBarcelos\NotasFiscais\Data\Shared\Endereco;
use DanielBBarcelos\NotasFiscais\Data\Shared\Valor;
use DanielBBarcelos\NotasFiscais\Enums\SituacaoNota;
use DanielBBarcelos\NotasFiscais\Enums\SituacaoTributaria;
use DanielBBarcelos\NotasFiscais\Enums\TipoTomador;
use DanielBBarcelos\NotasFiscais\Pdf\ComprovanteNfse;
use DanielBBarcelos\NotasFiscais\Pdf\Emitente;

function notaPdf(): NotaServico
{
    return new NotaServico(
        serie: 1,
        dataFatoGerador: '30/06/2026',
        valorTotal: Valor::reais('1000.00'),
        tomador: new Tomador(
            tipo: TipoTomador::Juridica,
            identificacao: '67.267.696/0001-02',
            nomeRazaoSocial: 'Cliente Tomador LTDA',
            endereco: new Endereco(logradouro: 'Rua X', numero: '10', bairro: 'Centro', codigoMunicipio: '3152501', uf: 'MG', cep: '37550-000'),
            email: 'cliente@exemplo.com.br',
        ),
        itens: [
            new ItemServico(
                codigoItemListaServico: '01.01.01',
                descritivo: 'Análise e desenvolvimento de sistemas',
                aliquota: 2.0,
                situacaoTributaria: SituacaoTributaria::TributadaIntegralmente,
                valorTributavel: Valor::reais('1000.00'),
                codigoLocalPrestacao: '3152501',
                codigoCnae: '6201501',
                valorIss: Valor::reais('20.00'),
            ),
        ],
        competencia: '2026-06-30',
        observacao: 'Referência junho/2026',
    );
}

function emitidaPdf(SituacaoNota $situacao = SituacaoNota::Emitida): NotaEmitida
{
    return new NotaEmitida(
        numero: 57,
        serie: 1,
        data: '30/06/2026',
        hora: '10:39:12',
        situacao: $situacao,
        codigoVerificacao: '5049300626165343820241004992026067397919',
        link: 'https://pousoalegre.atende.net/autoatendimento/servicos/consulta-de-autenticidade-de-nota-fiscal-eletronica-nfse/detalhar/1/identificador/5049300626165343820241004992026067397919',
        bruto: [],
    );
}

it('gera o comprovante em PDF a partir dos dados da nota', function () {
    $pdf = ComprovanteNfse::gerar(notaPdf(), emitidaPdf(), new Prestador('24100499000177', '3152501', '84592'));

    expect($pdf)->toBeString()
        ->and(substr($pdf, 0, 4))->toBe('%PDF')
        ->and(strlen($pdf))->toBeGreaterThan(1000);
});

it('gera comprovante marcado como cancelada', function () {
    $pdf = ComprovanteNfse::gerar(notaPdf(), emitidaPdf(SituacaoNota::Cancelada), new Prestador('24100499000177', '3152501', '84592'));

    expect(substr($pdf, 0, 4))->toBe('%PDF');
});

it('gera com o nome da empresa no cabeçalho', function () {
    $pdf = ComprovanteNfse::gerar(
        notaPdf(),
        emitidaPdf(),
        new Prestador('24100499000177', '3152501', '84592'),
        new Emitente(nome: 'Minha Empresa de Software LTDA'),
    );

    expect(substr($pdf, 0, 4))->toBe('%PDF');
});

it('usa placeholder quando o logo não existe (sem quebrar)', function () {
    $pdf = ComprovanteNfse::gerar(
        notaPdf(),
        emitidaPdf(),
        new Prestador('24100499000177', '3152501', '84592'),
        new Emitente(nome: 'Empresa X', logo: '/caminho/inexistente/logo.png'),
    );

    expect(substr($pdf, 0, 4))->toBe('%PDF');
});

it('salva o PDF em arquivo', function () {
    $caminho = sys_get_temp_dir().'/danfse-'.bin2hex(random_bytes(4)).'.pdf';

    ComprovanteNfse::salvar($caminho, notaPdf(), emitidaPdf());

    expect(file_exists($caminho))->toBeTrue()
        ->and(substr((string) file_get_contents($caminho), 0, 4))->toBe('%PDF');

    @unlink($caminho);
});
