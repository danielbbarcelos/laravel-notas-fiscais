<?php

declare(strict_types=1);

use DanielBBarcelos\NotasFiscais\Data\Nfse\Cancelamento;
use DanielBBarcelos\NotasFiscais\Data\Nfse\ItemServico;
use DanielBBarcelos\NotasFiscais\Data\Nfse\NotaServico;
use DanielBBarcelos\NotasFiscais\Data\Nfse\Tomador;
use DanielBBarcelos\NotasFiscais\Data\Shared\Endereco;
use DanielBBarcelos\NotasFiscais\Data\Shared\Valor;
use DanielBBarcelos\NotasFiscais\Enums\SituacaoNota;
use DanielBBarcelos\NotasFiscais\Enums\SituacaoTributaria;
use DanielBBarcelos\NotasFiscais\Enums\TipoTomador;
use DanielBBarcelos\NotasFiscais\Exceptions\NotaFiscalApiException;
use DanielBBarcelos\NotasFiscais\Facades\NotaFiscal;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

function notaAbrasf(): NotaServico
{
    return new NotaServico(
        serie: 1,
        dataFatoGerador: '30/06/2026',
        valorTotal: Valor::reais('1000.00'),
        tomador: new Tomador(
            tipo: TipoTomador::Juridica,
            identificacao: '11.222.333/0001-44',
            nomeRazaoSocial: 'Cliente ABRASF LTDA',
            endereco: new Endereco(
                logradouro: 'Rua das Acácias',
                numero: '10',
                bairro: 'Centro',
                codigoMunicipio: '3152501',
                uf: 'MG',
                cep: '37550-000',
            ),
            email: 'cliente@exemplo.com.br',
        ),
        itens: [
            new ItemServico(
                codigoItemListaServico: '10.01.01',
                descritivo: 'Desenvolvimento de software sob encomenda',
                aliquota: 2.0,
                situacaoTributaria: SituacaoTributaria::TributadaIntegralmente,
                valorTributavel: Valor::reais('1000.00'),
                codigoLocalPrestacao: '3152501',
                codigoCnae: '6201501',
                valorIss: Valor::reais('20.00'),
            ),
        ],
        competencia: '2026-06-01',
        observacao: 'Pagamento referência junho/2026',
    );
}

function respostaGerarNfse(): string
{
    return <<<'XML'
    <?xml version="1.0" encoding="UTF-8"?>
    <soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/">
      <soapenv:Body>
        <GerarNfseResposta xmlns="http://www.abrasf.org.br/nfse.xsd">
          <CompNfse>
            <Nfse versao="2.04">
              <InfNfse Id="NFSe_1293">
                <Numero>1293</Numero>
                <CodigoVerificacao>ABC123XYZ</CodigoVerificacao>
                <DataEmissao>2026-06-30T10:39:12</DataEmissao>
                <LinkNfse>https://pousoalegre.atende.net/nfse/1293</LinkNfse>
              </InfNfse>
            </Nfse>
          </CompNfse>
        </GerarNfseResposta>
      </soapenv:Body>
    </soapenv:Envelope>
    XML;
}

it('emite NFS-e via SOAP ABRASF e mapeia o retorno', function () {
    Http::fake(['*' => Http::response(respostaGerarNfse())]);

    $emitida = NotaFiscal::driver('ipm-abrasf')->nfse()->emitir(notaAbrasf());

    expect($emitida->numero)->toBe(1293)
        ->and($emitida->situacao)->toBe(SituacaoNota::Emitida)
        ->and($emitida->emitida())->toBeTrue()
        ->and($emitida->codigoVerificacao)->toBe('ABC123XYZ')
        ->and($emitida->link)->toContain('/nfse/1293');

    Http::assertSent(function ($request) {
        $corpo = $request->body();

        return $request->method() === 'POST'
            && str_contains($request->url(), 'WNENotaFiscalEletronicaNfe')
            && $request->hasHeader('Authorization')
            && str_contains($corpo, 'soapenv:Envelope')
            && str_contains($corpo, '<GerarNfseEnvio><Rps><InfDeclaracaoPrestacaoServico Id="RPS_')
            // valores ABRASF usam PONTO decimal
            && str_contains($corpo, '<ValorServicos>1000.00</ValorServicos>')
            && str_contains($corpo, '<Aliquota>2.00</Aliquota>')
            && str_contains($corpo, '<ValorIss>20.00</ValorIss>')
            && str_contains($corpo, '<ItemListaServico>10.01.01</ItemListaServico>')
            && str_contains($corpo, '<CodigoCnae>6201501</CodigoCnae>')
            // prestador da config (IBGE + inscrição municipal)
            && str_contains($corpo, '<Prestador><CpfCnpj><Cnpj>24100499000177</Cnpj></CpfCnpj><InscricaoMunicipal>84592</InscricaoMunicipal></Prestador>')
            && str_contains($corpo, '<TomadorServico><IdentificacaoTomador><CpfCnpj><Cnpj>11222333000144</Cnpj>')
            && str_contains($corpo, '<CodigoMunicipio>3152501</CodigoMunicipio>');
    });
});

it('usa EnviarLoteRpsSincrono com EnvioTeste no modo teste (dry-run)', function () {
    Http::fake(['*' => Http::response(respostaGerarNfse())]);

    $nota = new NotaServico(
        serie: 1,
        dataFatoGerador: '30/06/2026',
        valorTotal: Valor::reais('10.00'),
        tomador: new Tomador(TipoTomador::Juridica, '11.222.333/0001-44', 'Cliente LTDA'),
        itens: [new ItemServico('10.01.01', 'Serviço', 2.0, SituacaoTributaria::TributadaIntegralmente, Valor::reais('10.00'), '3152501')],
        teste: true,
    );

    NotaFiscal::driver('ipm-abrasf')->nfse()->emitir($nota);

    Http::assertSent(function ($request) {
        $corpo = $request->body();

        return str_contains($corpo, '<EnviarLoteRpsSincronoEnvio><EnvioTeste>1</EnvioTeste><LoteRps')
            && str_contains($corpo, '<QuantidadeRps>1</QuantidadeRps>')
            && str_contains($corpo, '<ListaRps><Rps><InfDeclaracaoPrestacaoServico')
            // não deve mais colocar EnvioTeste dentro do Rps
            && ! str_contains($corpo, '</InfDeclaracaoPrestacaoServico><EnvioTeste>');
    });
});

it('cancela NFS-e via CancelarNfseEnvio', function () {
    Http::fake(['*' => Http::response(<<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/">
          <soapenv:Body>
            <CancelarNfseResposta xmlns="http://www.abrasf.org.br/nfse.xsd">
              <RetCancelamento><NfseCancelamento><Confirmacao><Pedido><InfPedidoCancelamento><IdentificacaoNfse><Numero>1293</Numero></IdentificacaoNfse></InfPedidoCancelamento></Pedido></Confirmacao></NfseCancelamento></RetCancelamento>
            </CancelarNfseResposta>
          </soapenv:Body>
        </soapenv:Envelope>
        XML)]);

    $resultado = NotaFiscal::driver('ipm-abrasf')->nfse()->cancelar(
        new Cancelamento(numero: 1293, serie: 1, motivo: 'Erro de digitação'),
    );

    expect($resultado->cancelada())->toBeTrue()
        ->and($resultado->numero)->toBe(1293);

    Http::assertSent(function ($request) {
        $corpo = $request->body();

        return str_contains($corpo, '<CancelarNfseEnvio><Pedido><InfPedidoCancelamento Id="CANC_1293">')
            && str_contains($corpo, '<Numero>1293</Numero>')
            && str_contains($corpo, '<CpfCnpj><Cnpj>24100499000177</Cnpj></CpfCnpj>')
            && str_contains($corpo, '<CodigoCancelamento>1</CodigoCancelamento>');
    });
});

it('consulta por número via ConsultarNfseFaixaEnvio', function () {
    Http::fake(['*' => Http::response(<<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/">
          <soapenv:Body>
            <ConsultarNfseFaixaResposta xmlns="http://www.abrasf.org.br/nfse.xsd">
              <CompNfse><Nfse><InfNfse><Numero>1293</Numero><CodigoVerificacao>ABC123XYZ</CodigoVerificacao></InfNfse></Nfse></CompNfse>
            </ConsultarNfseFaixaResposta>
          </soapenv:Body>
        </soapenv:Envelope>
        XML)]);

    $nota = NotaFiscal::driver('ipm-abrasf')->nfse()->consultar(1293, 1, '84592');

    expect($nota->numero)->toBe(1293)
        ->and($nota->codigoVerificacao)->toBe('ABC123XYZ');

    Http::assertSent(fn ($request) => str_contains($request->body(), '<Faixa><NumeroNfseInicial>1293</NumeroNfseInicial><NumeroNfseFinal>1293</NumeroNfseFinal></Faixa>'));
});

it('converte erro ABRASF (MensagemRetorno) em NotaFiscalApiException', function () {
    Http::fake(['*' => Http::response(<<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/">
          <soapenv:Body>
            <GerarNfseResposta xmlns="http://www.abrasf.org.br/nfse.xsd">
              <ListaMensagemRetorno>
                <MensagemRetorno>
                  <Codigo>E163</Codigo>
                  <Mensagem>CNPJ do prestador inválido</Mensagem>
                  <Correcao>Verifique o CNPJ informado</Correcao>
                </MensagemRetorno>
              </ListaMensagemRetorno>
            </GerarNfseResposta>
          </soapenv:Body>
        </soapenv:Envelope>
        XML)]);

    expect(fn () => NotaFiscal::driver('ipm-abrasf')->nfse()->emitir(notaAbrasf()))
        ->toThrow(function (NotaFiscalApiException $e) {
            expect($e->codigo)->toBe('E163')
                ->and($e->getMessage())->toContain('CNPJ do prestador inválido')
                ->and($e->getMessage())->toContain('Correção');
        });
});

it('converte erro de plataforma IPM (Acesso Negado) em NotaFiscalApiException', function () {
    Http::fake(['*' => Http::response(
        '<?xml version="1.0" encoding="ISO-8859-1"?><retorno><msg>Acesso Negado!</msg><sis>EST</sis><code>401</code></retorno>',
        401,
    )]);

    expect(fn () => NotaFiscal::driver('ipm-abrasf')->nfse()->emitir(notaAbrasf()))
        ->toThrow(function (NotaFiscalApiException $e) {
            expect($e->codigo)->toBe('401')
                ->and($e->getMessage())->toContain('Acesso Negado');
        });
});

it('resolve o driver ABRASF com credenciais em runtime (multi-tenant)', function () {
    Http::fake(['*' => Http::response(respostaGerarNfse())]);

    NotaFiscal::build([
        'driver' => 'ipm-abrasf',
        'base_url' => 'https://outracidade.atende.net/?pg=services&service=WNENotaFiscalEletronicaNfe',
        'cpf_cnpj' => '99999999000199',
        'senha' => 'segredo-tenant',
        'inscricao_municipal' => '55555',
        'codigo_ibge' => '3550308',
    ])->nfse()->emitir(notaAbrasf());

    Http::assertSent(function ($request) {
        $corpo = $request->body();

        return str_contains($request->url(), 'outracidade.atende.net')
            && str_contains($corpo, '<Cnpj>99999999000199</Cnpj>')
            && str_contains($corpo, '<InscricaoMunicipal>55555</InscricaoMunicipal>')
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('99999999000199:segredo-tenant'));
    });
});

it('constrói o link a partir do template quando a resposta não traz link', function () {
    Http::fake(['*' => Http::response(<<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/">
          <soapenv:Body>
            <GerarNfseResposta xmlns="http://www.abrasf.org.br/nfse.xsd">
              <CompNfse><Nfse><InfNfse>
                <Numero>57</Numero>
                <CodigoVerificacao>5049ABC</CodigoVerificacao>
              </InfNfse></Nfse></CompNfse>
            </GerarNfseResposta>
          </soapenv:Body>
        </soapenv:Envelope>
        XML)]);

    $emitida = NotaFiscal::build([
        'driver' => 'ipm-abrasf',
        'base_url' => 'https://pousoalegre.atende.net/?pg=services&service=WNENotaFiscalEletronicaNfe',
        'cpf_cnpj' => '24100499000177',
        'senha' => 'x',
        'inscricao_municipal' => '84592',
        'codigo_ibge' => '3152501',
        'link_template' => 'https://pousoalegre.atende.net/?pg=autoatendimento#!/identificador/{codigo}',
    ])->nfse()->emitir(notaAbrasf());

    expect($emitida->numero)->toBe(57)
        ->and($emitida->link)->toBe('https://pousoalegre.atende.net/?pg=autoatendimento#!/identificador/5049ABC');
});
