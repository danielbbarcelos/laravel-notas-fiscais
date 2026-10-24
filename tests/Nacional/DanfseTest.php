<?php

declare(strict_types=1);

use DanielBBarcelos\NotasFiscais\Exceptions\NotaFiscalException;
use DanielBBarcelos\NotasFiscais\Pdf\Danfse;

function nfseFixture(): string
{
    return (string) file_get_contents(__DIR__.'/fixtures/nfse-me-epp.xml');
}

/** Quantas páginas um PDF do dompdf tem (cada página é um objeto /Type /Page). */
function paginasPdf(string $pdf): int
{
    return preg_match_all('#/Type\s*/Page[^s]#', $pdf);
}

/** O HTML do DANFSe, pelo mesmo caminho que o PDF usa. */
function danfseHtml(string $xml, array $opcoes = []): string
{
    $construtor = (new ReflectionClass(Danfse::class))->getConstructor();
    $construtor->setAccessible(true);
    $danfse = (new ReflectionClass(Danfse::class))->newInstanceWithoutConstructor();
    $construtor->invoke($danfse, $xml, (bool) ($opcoes['cancelada'] ?? false), (bool) ($opcoes['substituida'] ?? false), (bool) ($opcoes['canhoto'] ?? false), $opcoes['municipio'] ?? null);

    return $danfse->html();
}

it('gera o DANFSe em uma única página A4', function () {
    $pdf = Danfse::gerar(nfseFixture(), ['canhoto' => true]);

    expect(substr($pdf, 0, 5))->toBe('%PDF-')
        ->and(paginasPdf($pdf))->toBe(1);
});

it('mostra os campos de identificação, prestador, tomador e valores lidos do XML', function () {
    $html = danfseHtml(nfseFixture(), ['municipio' => fn ($c) => $c === '3152501' ? 'Pouso Alegre' : null]);

    expect($html)->toContain('DANFSe v2.0')
        ->toContain('31525012224100499000177000000000000126104123456789')
        ->toContain('24.100.499/0001-77')
        ->toContain('DBMV TECNOLOGIA LTDA')
        ->toContain('11.222.333/0001-81')
        ->toContain('Cliente Exemplo Comércio de Serviços LTDA')
        ->toContain('01.07.01')
        ->toContain('1.0105.10.00')
        ->toContain('R$ 1.500,00')
        ->toContain('R$ 1.407,75')
        ->toContain('Optante - Microempresa ou Empresa de Pequeno Porte (ME/EPP)')
        ->toContain('6,00% (Simples Nacional)')
        ->toContain('data:image/svg+xml;base64,');
});

it('marca a nota de homologação como sem validade jurídica', function () {
    expect(danfseHtml(nfseFixture()))->toContain('NFS-e SEM VALIDADE JURÍDICA');

    $producao = str_replace('<tpAmb>2</tpAmb>', '<tpAmb>1</tpAmb>', nfseFixture());

    expect(danfseHtml($producao))->not->toContain('SEM VALIDADE JURÍDICA');
});

it('aplica a marca d\'água de cancelada e de substituída', function () {
    expect(danfseHtml(nfseFixture(), ['cancelada' => true]))->toContain('<div class="marca">CANCELADA</div>')
        ->and(danfseHtml(nfseFixture(), ['substituida' => true]))->toContain('<div class="marca">SUBSTITUÍDA</div>')
        ->and(danfseHtml(nfseFixture()))->not->toContain('class="marca"');
});

it('reduz a uma linha os blocos sem dados, como a NT permite', function () {
    $html = danfseHtml(nfseFixture());

    expect($html)->toContain('DESTINATÁRIO DA OPERAÇÃO NÃO IDENTIFICADO NA NFS-e')
        ->toContain('INTERMEDIÁRIO DA OPERAÇÃO NÃO IDENTIFICADO NA NFS-e');
});

it('corta texto longo com reticências para não passar de uma página', function () {
    $longo = str_repeat('Descrição extensa do serviço prestado. ', 200);
    $xml = preg_replace('#<xDescServ>.*?</xDescServ>#s', '<xDescServ>'.$longo.'</xDescServ>', nfseFixture());

    expect(danfseHtml($xml))->toContain('(...)')
        ->and(paginasPdf(Danfse::gerar($xml, ['canhoto' => true])))->toBe(1);
});

it('recusa um XML que não é de NFS-e nacional', function () {
    expect(fn () => Danfse::gerar('<CompNfse><Nfse/></CompNfse>'))
        ->toThrow(NotaFiscalException::class, 'infNFSe');
});
