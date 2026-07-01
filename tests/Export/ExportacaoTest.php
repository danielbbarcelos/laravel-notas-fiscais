<?php

declare(strict_types=1);

use DanielBBarcelos\NotasFiscais\Contracts\ExportaArquivos;
use DanielBBarcelos\NotasFiscais\Data\Nfse\ItemServico;
use DanielBBarcelos\NotasFiscais\Data\Nfse\NotaEmitida;
use DanielBBarcelos\NotasFiscais\Data\Nfse\NotaServico;
use DanielBBarcelos\NotasFiscais\Data\Nfse\Tomador;
use DanielBBarcelos\NotasFiscais\Data\Shared\Endereco;
use DanielBBarcelos\NotasFiscais\Data\Shared\Valor;
use DanielBBarcelos\NotasFiscais\Enums\SituacaoNota;
use DanielBBarcelos\NotasFiscais\Enums\SituacaoTributaria;
use DanielBBarcelos\NotasFiscais\Enums\TipoTomador;
use DanielBBarcelos\NotasFiscais\Facades\NotaFiscal;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

function notaParaExport(): NotaServico
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

function emitidaParaExport(): NotaEmitida
{
    return new NotaEmitida(
        numero: 1293,
        serie: 1,
        data: '15/01/2026',
        hora: '10:30:00',
        situacao: SituacaoNota::Emitida,
        codigoVerificacao: 'AUT123',
        link: null,
        bruto: [],
    );
}

it('expõe os gateways como ExportaArquivos', function () {
    expect(NotaFiscal::driver('ipm')->nfse())->toBeInstanceOf(ExportaArquivos::class);
    expect(NotaFiscal::driver('ipm-abrasf')->nfse())->toBeInstanceOf(ExportaArquivos::class);
});

it('gera o XML <nfse> do IPM no driver REST', function () {
    $xml = NotaFiscal::driver('ipm')->nfse()->xmlNota(notaParaExport());

    expect($xml)
        ->toContain('<nfse>')
        ->toContain('<cpfcnpj>12345678000199</cpfcnpj>')
        ->toContain('<nome_razao_social>Empresa Tomadora LTDA</nome_razao_social>')
        ->toContain('<valor_total>1000,00</valor_total>');
});

it('gera o TXT de exportação com os registros 10/20/30 posicionais', function () {
    $txt = NotaFiscal::driver('ipm')->nfse()->txtExportacao(notaParaExport(), emitidaParaExport());

    $linhas = explode("\r\n", rtrim($txt, "\r\n"));
    expect($linhas)->toHaveCount(3);

    [$r10, $r20, $r30] = $linhas;

    // Registro 10 — identificação do documento (largura fixa 787).
    expect(strlen($r10))->toBe(787);
    expect(substr($r10, 0, 2))->toBe('10');
    expect($r10[3])->toBe('J');                                 // tipo prestador (CNPJ)
    expect(substr($r10, 5, 14))->toBe('12345678000199');        // CPF/CNPJ prestador
    expect($r10[20])->toBe('1');                                // série
    expect(substr($r10, 22, 18))->toBe('000000000000001293');   // número
    expect(rtrim(substr($r10, 41, 40)))->toBe('AUT123');        // código autenticação
    expect(substr($r10, 82, 10))->toBe('15/01/2026');           // data emissão
    expect(substr($r10, 93, 8))->toBe('10:30:00');              // hora emissão
    expect($r10[102])->toBe('J');                               // tipo tomador
    expect(substr($r10, 104, 14))->toBe('12345678000195');      // CPF/CNPJ tomador
    expect(substr($r10, 119, 18))->toBe('000000000001000.00');  // valor total
    expect($r10[214])->toBe('E');                               // situação = Emitido
    expect($r10[786])->toBe('S');                               // Simples Nacional

    // Registro 20 — item de serviço.
    expect(substr($r20, 0, 2))->toBe('20');
    expect(substr($r20, 41, 7))->toBe('0010700');               // item lista serviço
    expect(substr($r20, 49, 6))->toBe('003.00');               // alíquota
    expect(substr($r20, 307, 2))->toBe('00');                   // situação tributária
    expect(substr($r20, 310, 18))->toBe('000000000001000.00');  // valor tributável
    expect(substr($r20, 367, 8))->toBe('00008055');             // local da prestação
    expect($r20[376])->toBe('S');                               // tributa no município

    // Registro 30 — tomador.
    expect(substr($r30, 0, 2))->toBe('30');
    expect($r30[3])->toBe('J');
    expect(substr($r30, 5, 14))->toBe('12345678000195');
    expect(rtrim(substr($r30, 20, 100)))->toBe('Empresa Tomadora LTDA');
    expect(rtrim(substr($r30, 121, 40)))->toBe('Rua das Flores');
    expect(substr($r30, 247, 8))->toBe('89160000');             // CEP
});

it('gera o RPS ABRASF local quando não há XML oficial', function () {
    $nota = new NotaServico(
        serie: 1,
        dataFatoGerador: '30/06/2026',
        valorTotal: Valor::reais('1000.00'),
        tomador: new Tomador(TipoTomador::Juridica, '11.222.333/0001-44', 'Cliente ABRASF LTDA'),
        itens: [new ItemServico('10.01.01', 'Serviço', 2.0, SituacaoTributaria::TributadaIntegralmente, Valor::reais('1000.00'), '3152501')],
        competencia: '2026-06-01',
    );

    $xml = NotaFiscal::driver('ipm-abrasf')->nfse()->xmlNota($nota);

    expect($xml)
        ->toContain('<Rps>')
        ->toContain('<InfDeclaracaoPrestacaoServico')
        ->toContain('<ValorServicos>1000.00</ValorServicos>')
        ->toContain('<ItemListaServico>10.01.01</ItemListaServico>')
        ->not->toContain('soapenv:Envelope');           // sem envelope SOAP
});

it('devolve o XML oficial assinado do ABRASF quando presente na resposta', function () {
    $respostaOficial = <<<'XML'
    <?xml version="1.0" encoding="UTF-8"?>
    <soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/">
      <soapenv:Body>
        <GerarNfseResposta xmlns="http://www.abrasf.org.br/nfse.xsd">
          <CompNfse>
            <Nfse versao="2.04"><InfNfse Id="NFSe_1293"><Numero>1293</Numero></InfNfse></Nfse>
          </CompNfse>
        </GerarNfseResposta>
      </soapenv:Body>
    </soapenv:Envelope>
    XML;

    $emitida = new NotaEmitida(
        numero: 1293, serie: null, data: null, hora: null,
        situacao: SituacaoNota::Emitida, codigoVerificacao: 'ABC', link: null,
        bruto: ['xml_response' => $respostaOficial],
    );

    $nota = new NotaServico(
        serie: 1,
        dataFatoGerador: '30/06/2026',
        valorTotal: Valor::reais('1000.00'),
        tomador: new Tomador(TipoTomador::Juridica, '11.222.333/0001-44', 'Cliente ABRASF LTDA'),
        itens: [new ItemServico('10.01.01', 'Serviço', 2.0, SituacaoTributaria::TributadaIntegralmente, Valor::reais('1000.00'), '3152501')],
    );

    $xml = NotaFiscal::driver('ipm-abrasf')->nfse()->xmlNota($nota, $emitida);

    expect($xml)
        ->toContain('<CompNfse>')
        ->toContain('<Numero>1293</Numero>')
        ->not->toContain('<InfDeclaracaoPrestacaoServico');   // não é o RPS local
});
