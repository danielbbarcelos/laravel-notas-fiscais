<?php

declare(strict_types=1);

use DanielBBarcelos\NotasFiscais\Data\Nfse\Cancelamento;
use DanielBBarcelos\NotasFiscais\Data\Nfse\ItemServico;
use DanielBBarcelos\NotasFiscais\Data\Nfse\NotaServico;
use DanielBBarcelos\NotasFiscais\Data\Nfse\Tomador;
use DanielBBarcelos\NotasFiscais\Data\Shared\Endereco;
use DanielBBarcelos\NotasFiscais\Data\Shared\Valor;
use DanielBBarcelos\NotasFiscais\Drivers\Nacional\AssinaturaXml;
use DanielBBarcelos\NotasFiscais\Drivers\Nacional\Mappers\DpsMapper;
use DanielBBarcelos\NotasFiscais\Drivers\Nacional\NacionalConnector;
use DanielBBarcelos\NotasFiscais\Drivers\Nacional\NacionalXml;
use DanielBBarcelos\NotasFiscais\Drivers\Nacional\RegimeTributario;
use DanielBBarcelos\NotasFiscais\Enums\SituacaoNota;
use DanielBBarcelos\NotasFiscais\Enums\SituacaoTributaria;
use DanielBBarcelos\NotasFiscais\Enums\TipoTomador;
use DanielBBarcelos\NotasFiscais\Exceptions\NotaFiscalApiException;
use DanielBBarcelos\NotasFiscais\Exceptions\NotaFiscalException;
use DanielBBarcelos\NotasFiscais\Facades\NotaFiscal;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const CHAVE_NACIONAL = '31525012224100499000177000000000000126104123456789';

beforeEach(function () {
    Http::preventStrayRequests();
});

/** Certificado autoassinado em PKCS#12 (Base64), no lugar do A1 de verdade. */
function pfxDeTeste(string $senha = 'senha-teste'): string
{
    static $cache = [];

    if (isset($cache[$senha])) {
        return $cache[$senha];
    }

    $chave = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['commonName' => 'EMPRESA TESTE LTDA:24100499000177'], $chave);
    $cert = openssl_csr_sign($csr, null, $chave, 30);
    openssl_pkcs12_export($cert, $pfx, $chave, $senha);

    return $cache[$senha] = base64_encode($pfx);
}

/** @param array<string, mixed> $extra */
function configNacional(array $extra = []): array
{
    return array_merge([
        'driver' => 'nacional',
        'ambiente' => 'producao',
        'certificado' => pfxDeTeste(),
        'certificado_senha' => 'senha-teste',
        'cpf_cnpj' => '24.100.499/0001-77',
        'codigo_ibge' => '3152501',
        'inscricao_municipal' => '84592',
        'simples_nacional' => 1,
        'codigo_nbs' => '1.0105.10.00',
        'timeout' => 10,
    ], $extra);
}

function notaNacional(array $sobrescrever = []): NotaServico
{
    $args = array_merge([
        'serie' => 1,
        'dataFatoGerador' => '30/06/2026',
        'valorTotal' => Valor::reais('1000.00'),
        'tomador' => new Tomador(
            tipo: TipoTomador::Juridica,
            identificacao: '11.222.333/0001-44',
            nomeRazaoSocial: 'Cliente Nacional LTDA',
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
        'itens' => [
            new ItemServico(
                codigoItemListaServico: '01.07.01',
                descritivo: 'Suporte técnico em informática',
                aliquota: 2.0,
                situacaoTributaria: SituacaoTributaria::TributadaIntegralmente,
                valorTributavel: Valor::reais('1000.00'),
                codigoLocalPrestacao: '3152501',
            ),
        ],
        'competencia' => '2026-06-01',
        'observacao' => 'Referência junho/2026',
        'numeroDps' => 42,
    ], $sobrescrever);

    return new NotaServico(...$args);
}

/** NFS-e devolvida pela Sefin (só as tags que o mapper lê). */
function nfseNacionalXml(string $numero = '126', string $vServ = '1000.00', string $tomador = '11222333000144', string $cTribNac = '010701'): string
{
    $chave = CHAVE_NACIONAL;

    return <<<XML
    <?xml version="1.0" encoding="UTF-8"?>
    <NFSe xmlns="http://www.sped.fazenda.gov.br/nfse" versao="1.01"><infNFSe Id="NFS{$chave}"><nNFSe>{$numero}</nNFSe><dhProc>2026-06-30T14:05:09-03:00</dhProc><DPS versao="1.01"><infDPS Id="DPS315250122410049900017700001000000000000042"><serie>1</serie><toma><CNPJ>{$tomador}</CNPJ></toma><serv><cServ><cTribNac>{$cTribNac}</cTribNac><xDescServ>Suporte técnico em informática</xDescServ></cServ></serv><valores><vServPrest><vServ>{$vServ}</vServ></vServPrest></valores></infDPS></DPS></infNFSe></NFSe>
    XML;
}

/** @return array<string, mixed> Http::fake de uma rejeição E0014 seguida da recuperação. */
function fakeDpsDuplicada(string $xmlNfse): array
{
    return [
        NacionalConnector::URL_PRODUCAO.'/nfse' => Http::response([
            'erros' => [['Codigo' => 'E0014', 'Descricao' => 'Conjunto de Série, Número, Código do Município Emissor e CNPJ/CPF informado nesta DPS já existe em uma NFS-e gerada a partir de uma DPS enviada anteriormente.']],
        ], 400),
        NacionalConnector::URL_PRODUCAO.'/dps/*' => Http::response(['chaveAcesso' => CHAVE_NACIONAL], 200),
        NacionalConnector::URL_PRODUCAO.'/nfse/*' => Http::response([
            'chaveAcesso' => CHAVE_NACIONAL,
            'nfseXmlGZipB64' => NacionalXml::compactar($xmlNfse),
        ], 200),
    ];
}

function respostaEmissaoNacional(): array
{
    return [
        'tipoAmbiente' => 1,
        'versaoAplicativo' => 'SefinNac_1.0',
        'dataHoraProcessamento' => '2026-06-30T14:05:09-03:00',
        'idDps' => 'DPS315250122410049900017700001000000000000042',
        'chaveAcesso' => CHAVE_NACIONAL,
        'nfseXmlGZipB64' => NacionalXml::compactar(nfseNacionalXml()),
    ];
}

/** O XML enviado no campo *XmlGZipB64 de uma requisição. */
function xmlEnviado(Request $request, string $campo): string
{
    return NacionalXml::descompactar($request->data()[$campo]);
}

/**
 * Cópia dos XSD oficiais com uma correção: o TSSerieDPS usa "^...$" no pattern,
 * e em regex de XSD essas âncoras são caracteres literais (o libxml segue a
 * norma; o validador .NET da Sefin as trata como âncora). Sem a correção, nenhuma
 * série passaria aqui, nem "1" nem "00001".
 */
function schemasNacionais(): string
{
    static $dir = null;

    if ($dir !== null) {
        return $dir;
    }

    $dir = sys_get_temp_dir().'/nfse-nacional-xsd-'.getmypid();
    @mkdir($dir);

    foreach (glob(__DIR__.'/../../docs/nfse-nacional/schemas/*.xsd') as $arquivo) {
        file_put_contents($dir.'/'.basename($arquivo), str_replace('value="^0{0,4}\d{1,5}$"', 'value="0{0,4}\d{1,5}"', file_get_contents($arquivo)));
    }

    return $dir;
}

function validaContraXsd(string $xml, string $xsd): array
{
    $dom = new DOMDocument();
    $dom->loadXML($xml);
    $anterior = libxml_use_internal_errors(true);
    $ok = $dom->schemaValidate(schemasNacionais().'/'.$xsd, LIBXML_NONET);
    $erros = array_map(fn ($e) => trim($e->message), libxml_get_errors());
    libxml_clear_errors();
    libxml_use_internal_errors($anterior);

    return $ok ? [] : $erros;
}

it('emite: envia a DPS assinada e compactada para a Sefin de produção', function () {
    Http::fake([NacionalConnector::URL_PRODUCAO.'/nfse' => Http::response(respostaEmissaoNacional(), 201)]);

    $emitida = NotaFiscal::build(configNacional())->nfse()->emitir(notaNacional());

    expect($emitida->numero)->toBe(126)
        ->and($emitida->serie)->toBe(1)
        ->and($emitida->situacao)->toBe(SituacaoNota::Emitida)
        ->and($emitida->codigoVerificacao)->toBe(CHAVE_NACIONAL)
        ->and($emitida->link)->toBe('https://www.nfse.gov.br/ConsultaPublica/?tpc=1&chave='.CHAVE_NACIONAL)
        ->and($emitida->data)->toBe('30/06/2026')
        ->and($emitida->hora)->toBe('14:05:09')
        ->and($emitida->bruto['xml_nfse'])->toContain('<nNFSe>126</nNFSe>');

    Http::assertSent(function (Request $request) {
        $xml = xmlEnviado($request, 'dpsXmlGZipB64');

        return $request->method() === 'POST'
            && str_contains($xml, 'Id="DPS315250122410049900017700001000000000000042"')
            && str_contains($xml, '<tpAmb>1</tpAmb>')
            // E1229: a Sefin recusa o XML que não declara UTF-8.
            && str_starts_with($xml, '<?xml version="1.0" encoding="UTF-8"?>')
            && AssinaturaXml::valida($xml, 'infDPS');
    });
});

it('gera uma DPS válida no XSD oficial v1.01, com a assinatura', function () {
    Http::fake([NacionalConnector::URL_PRODUCAO.'/nfse' => Http::response(respostaEmissaoNacional(), 201)]);

    NotaFiscal::build(configNacional())->nfse()->emitir(notaNacional([
        'valorIr' => Valor::reais('15.00'),
        'valorPis' => Valor::reais('6.50'),
        'valorCofins' => Valor::reais('30.00'),
        'valorContribuicaoSocial' => Valor::reais('10.00'),
        'valorDesconto' => Valor::reais('5.00'),
    ]));

    Http::assertSent(function (Request $request) {
        expect(validaContraXsd(xmlEnviado($request, 'dpsXmlGZipB64'), 'DPS_v1.01.xsd'))->toBe([]);

        return true;
    });
});

it('monta a DPS conforme o leiaute: código nacional, NBS, regime e sem nome nem IM do prestador', function () {
    Http::fake([NacionalConnector::URL_PRODUCAO.'/nfse' => Http::response(respostaEmissaoNacional(), 201)]);

    NotaFiscal::build(configNacional())->nfse()->emitir(notaNacional());

    Http::assertSent(function (Request $request) {
        $dom = NacionalXml::parse(xmlEnviado($request, 'dpsXmlGZipB64'));
        $prest = $dom->getElementsByTagName('prest')->item(0);

        expect(NacionalXml::texto($dom, 'cTribNac'))->toBe('010701')
            ->and(NacionalXml::texto($dom, 'cNBS'))->toBe('101051000')
            ->and(NacionalXml::texto($prest, 'CNPJ'))->toBe('24100499000177')
            ->and(NacionalXml::texto($prest, 'IM'))->toBeNull()
            ->and(NacionalXml::texto($prest, 'xNome'))->toBeNull()
            ->and(NacionalXml::texto($dom, 'opSimpNac'))->toBe('1')
            ->and(NacionalXml::texto($dom, 'regApTribSN'))->toBeNull()
            ->and(NacionalXml::texto($dom, 'dCompet'))->toBe('2026-06-01')
            ->and(NacionalXml::texto($dom, 'cLocEmi'))->toBe('3152501')
            ->and(NacionalXml::texto($dom, 'cLocPrestacao'))->toBe('3152501')
            ->and(NacionalXml::texto($dom, 'vServ'))->toBe('1000.00')
            ->and(NacionalXml::texto($dom, 'tpRetISSQN'))->toBe('1')
            // Não optante com município conveniado: a Sefin aplica a alíquota dela.
            ->and(NacionalXml::texto($dom, 'pAliq'))->toBeNull()
            ->and(NacionalXml::texto($dom, 'pTotTribFed'))->toBe('0.00')
            ->and(NacionalXml::texto($dom, 'xInfComp'))->toBe('Referência junho/2026')
            ->and(NacionalXml::texto($dom, 'CEP'))->toBe('37550000');

        return true;
    });
});

it('teste (homologação) vai para a produção restrita com tpAmb 2', function () {
    Http::fake([NacionalConnector::URL_PRODUCAO_RESTRITA.'/nfse' => Http::response(respostaEmissaoNacional(), 201)]);

    NotaFiscal::build(configNacional())->nfse()->emitir(notaNacional(['teste' => true]));

    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), NacionalConnector::URL_PRODUCAO_RESTRITA)
        && str_contains(xmlEnviado($request, 'dpsXmlGZipB64'), '<tpAmb>2</tpAmb>'));
});

it('usa o certificado no mTLS em memória, sem gravar a chave em disco', function () {
    $opcoes = null;

    Http::fake(function (Request $request, array $options) use (&$opcoes) {
        $opcoes = $options;

        return Http::response(respostaEmissaoNacional(), 201);
    });

    NotaFiscal::build(configNacional())->nfse()->emitir(notaNacional());

    expect($opcoes['curl'][CURLOPT_SSLCERT_BLOB] ?? null)->toContain('BEGIN CERTIFICATE')
        ->and($opcoes['curl'][CURLOPT_SSLKEY_BLOB] ?? null)->toContain('PRIVATE KEY')
        ->and($opcoes)->not->toHaveKey('cert')
        ->and($opcoes)->not->toHaveKey('ssl_key');
});

it('não lê como caminho de arquivo um certificado que veio no conteúdo', function () {
    $arquivo = tempnam(sys_get_temp_dir(), 'pfx_');
    file_put_contents($arquivo, base64_decode(pfxDeTeste()));

    expect(fn () => NotaFiscal::build(configNacional(['certificado' => $arquivo]))->nfse())
        ->toThrow(NotaFiscalException::class, 'senha incorreta ou arquivo');

    // O caminho só vale pela chave própria, do cenário single-tenant.
    expect(NotaFiscal::build(configNacional(['certificado' => null, 'certificado_arquivo' => $arquivo]))->nfse())
        ->toBeInstanceOf(\DanielBBarcelos\NotasFiscais\Drivers\Nacional\NacionalNfseGateway::class);

    @unlink($arquivo);
});

it('reenvio da mesma DPS (E0014) recupera a NFS-e já gerada em vez de falhar', function () {
    Http::fake(fakeDpsDuplicada(nfseNacionalXml()));

    $emitida = NotaFiscal::build(configNacional())->nfse()->emitir(notaNacional());

    expect($emitida->numero)->toBe(126)->and($emitida->codigoVerificacao)->toBe(CHAVE_NACIONAL);

    Http::assertSent(fn (Request $r) => $r->url() === NacionalConnector::URL_PRODUCAO.'/dps/DPS315250122410049900017700001000000000000042');
});

it('E0014 com outra nota no mesmo número (valor ou tomador diferente) falha em vez de devolver a nota alheia', function () {
    Http::fake(fakeDpsDuplicada(nfseNacionalXml(vServ: '250.00')));

    expect(fn () => NotaFiscal::build(configNacional())->nfse()->emitir(notaNacional()))
        ->toThrow(NotaFiscalException::class, 'já gerou uma NFS-e com outros dados (chave '.CHAVE_NACIONAL.')');
});

it('E0014 de uma primeira tentativa com outro código de serviço falha em vez de aceitar a nota antiga', function () {
    Http::fake(fakeDpsDuplicada(nfseNacionalXml(cTribNac: '010501')));

    expect(fn () => NotaFiscal::build(configNacional())->nfse()->emitir(notaNacional()))
        ->toThrow(NotaFiscalException::class, 'já gerou uma NFS-e com outros dados');
});

it('rejeição vira NotaFiscalApiException com todos os códigos na mensagem', function () {
    Http::fake([NacionalConnector::URL_PRODUCAO.'/nfse' => Http::response([
        'erros' => [
            ['Codigo' => 'E0312', 'Descricao' => 'Código de tributação nacional inexistente.'],
            ['Codigo' => 'E0625', 'Descricao' => 'Alíquota não deve ser informada.', 'Complemento' => 'convênio ativo'],
        ],
    ], 400)]);

    try {
        NotaFiscal::build(configNacional())->nfse()->emitir(notaNacional());
        $this->fail('Deveria lançar.');
    } catch (NotaFiscalApiException $e) {
        expect($e->codigo)->toBe('E0312')
            ->and($e->statusHttp)->toBe(400)
            ->and($e->getMessage())->toBe('[nacional] Erro E0312: Código de tributação nacional inexistente. | E0625: Alíquota não deve ser informada. (convênio ativo)');
    }
});

it('falha HTTP sem corpo estruturado (ex.: certificado recusado) também lança', function () {
    Http::fake([NacionalConnector::URL_PRODUCAO.'/nfse' => Http::response('Forbidden', 403)]);

    expect(fn () => NotaFiscal::build(configNacional())->nfse()->emitir(notaNacional()))
        ->toThrow(NotaFiscalApiException::class, 'Erro HTTP 403');
});

it('exige o número da DPS e o código nacional de 6 dígitos', function () {
    $gateway = NotaFiscal::build(configNacional())->nfse();

    expect(fn () => $gateway->emitir(notaNacional(['numeroDps' => null])))
        ->toThrow(InvalidArgumentException::class, 'numeroDps');

    $item = new ItemServico(
        codigoItemListaServico: '01.07',
        descritivo: 'x',
        aliquota: 2,
        situacaoTributaria: SituacaoTributaria::TributadaIntegralmente,
        valorTributavel: Valor::reais('1'),
        codigoLocalPrestacao: '3152501',
    );

    expect(fn () => $gateway->emitir(notaNacional(['itens' => [$item]])))
        ->toThrow(InvalidArgumentException::class, '6 dígitos');
});

it('recusa certificado sem a senha certa', function () {
    expect(fn () => NotaFiscal::build(configNacional(['certificado_senha' => 'errada']))->nfse())
        ->toThrow(NotaFiscalException::class, 'senha incorreta');
});

it('cancela por evento e101101 assinado, válido no XSD do pedido', function () {
    Http::fake([NacionalConnector::URL_PRODUCAO.'/nfse/'.CHAVE_NACIONAL.'/eventos' => Http::response([
        'eventoXmlGZipB64' => NacionalXml::compactar('<evento xmlns="http://www.sped.fazenda.gov.br/nfse"/>'),
    ], 201)]);

    $cancelada = NotaFiscal::build(configNacional())->nfse()->cancelar(new Cancelamento(
        numero: 126,
        serie: 1,
        motivo: 'Valor do serviço informado errado',
        chaveAcesso: CHAVE_NACIONAL,
    ));

    expect($cancelada->situacao)->toBe(SituacaoNota::Cancelada)
        ->and($cancelada->codigoVerificacao)->toBe(CHAVE_NACIONAL);

    Http::assertSent(function (Request $request) {
        $xml = xmlEnviado($request, 'pedidoRegistroEventoXmlGZipB64');

        expect(validaContraXsd($xml, 'pedRegEvento_v1.01.xsd'))->toBe([])
            ->and($xml)->toContain('Id="PRE'.CHAVE_NACIONAL.'101101"')
            ->and($xml)->toContain('<cMotivo>1</cMotivo>')
            ->and($xml)->toStartWith('<?xml version="1.0" encoding="UTF-8"?>')
            ->and(AssinaturaXml::valida($xml, 'infPedReg'))->toBeTrue();

        return true;
    });
});

it('cancelamento exige a chave de acesso e um motivo de ao menos 15 caracteres', function () {
    $gateway = NotaFiscal::build(configNacional())->nfse();

    expect(fn () => $gateway->cancelar(new Cancelamento(numero: 1, serie: 1, motivo: 'Motivo longo o bastante')))
        ->toThrow(InvalidArgumentException::class, 'chaveAcesso');

    expect(fn () => $gateway->cancelar(new Cancelamento(numero: 1, serie: 1, motivo: 'curto', chaveAcesso: CHAVE_NACIONAL)))
        ->toThrow(InvalidArgumentException::class, '15 e 255');
});

it('consulta pela chave de acesso e devolve o XML oficial em xmlNota', function () {
    Http::fake([NacionalConnector::URL_PRODUCAO.'/nfse/'.CHAVE_NACIONAL => Http::response([
        'chaveAcesso' => CHAVE_NACIONAL,
        'nfseXmlGZipB64' => NacionalXml::compactar(nfseNacionalXml()),
    ], 200)]);

    $gateway = NotaFiscal::build(configNacional())->nfse();
    $consultada = $gateway->consultarPorAutenticidade(CHAVE_NACIONAL);

    expect($consultada->numero)->toBe(126)
        ->and($consultada->serie)->toBe(1)
        ->and($gateway->xmlNota(notaNacional(), $consultada))->toContain('<nNFSe>126</nNFSe>');
});

it('consulta por número não existe no padrão nacional', function () {
    expect(fn () => NotaFiscal::build(configNacional())->nfse()->consultar(126, 1, ''))
        ->toThrow(NotaFiscalException::class, 'chave de acesso');
});

it('sem a NFS-e oficial, xmlNota devolve a DPS de origem', function () {
    $xml = NotaFiscal::build(configNacional())->nfse()->xmlNota(notaNacional());

    expect($xml)->toContain('<DPS xmlns="http://www.sped.fazenda.gov.br/nfse" versao="1.01">')
        ->and($xml)->not->toContain('Signature');
});

describe('alíquota (pAliq) por regime', function () {
    $mapper = fn (RegimeTributario $regime) => new DpsMapper($regime);

    it('não optante: só informa sem convênio', function () use ($mapper) {
        expect($mapper(new RegimeTributario())->aliquotaInformada(false))->toBeFalse()
            ->and($mapper(new RegimeTributario(municipioConveniado: false))->aliquotaInformada(false))->toBeTrue();
    });

    it('ME/EPP apurando pelo Simples: só informa quando há retenção', function () use ($mapper) {
        $regime = new RegimeTributario(opcaoSimples: RegimeTributario::SIMPLES_ME_EPP, apuracaoSimples: 1);

        expect($mapper($regime)->aliquotaInformada(true))->toBeTrue()
            ->and($mapper($regime)->aliquotaInformada(false))->toBeFalse();
    });

    it('ME/EPP com ISS fora do Simples: segue o convênio', function () use ($mapper) {
        $conveniado = new RegimeTributario(opcaoSimples: RegimeTributario::SIMPLES_ME_EPP, apuracaoSimples: 2);
        $semConvenio = new RegimeTributario(opcaoSimples: RegimeTributario::SIMPLES_ME_EPP, apuracaoSimples: 2, municipioConveniado: false);

        expect($mapper($conveniado)->aliquotaInformada(true))->toBeFalse()
            ->and($mapper($semConvenio)->aliquotaInformada(false))->toBeTrue();
    });

    it('MEI nunca informa', function () use ($mapper) {
        expect($mapper(new RegimeTributario(opcaoSimples: RegimeTributario::SIMPLES_MEI, municipioConveniado: false))->aliquotaInformada(true))->toBeFalse();
    });
});

it('ME/EPP vai com regApTribSN e pTotTribSN, válido no XSD', function () {
    Http::fake([NacionalConnector::URL_PRODUCAO.'/nfse' => Http::response(respostaEmissaoNacional(), 201)]);

    NotaFiscal::build(configNacional(['simples_nacional' => 3, 'tributos_simples' => '6.00']))->nfse()->emitir(notaNacional());

    Http::assertSent(function (Request $request) {
        $xml = xmlEnviado($request, 'dpsXmlGZipB64');
        $dom = NacionalXml::parse($xml);

        expect(validaContraXsd($xml, 'DPS_v1.01.xsd'))->toBe([])
            ->and(NacionalXml::texto($dom, 'opSimpNac'))->toBe('3')
            ->and(NacionalXml::texto($dom, 'regApTribSN'))->toBe('1')
            ->and(NacionalXml::texto($dom, 'pTotTribSN'))->toBe('6.00');

        return true;
    });
});

it('consulta o convênio do município no ADN e lê a adesão em formatos diferentes', function () {
    Http::fake([NacionalConnector::URL_PARAMETROS_PRODUCAO.'/3152501/convenio' => Http::response([
        'mensagem' => 'OK',
        'parametrosConvenio' => ['aderenteAmbienteNacional' => 1, 'aderenteEmissorNacional' => 0],
    ], 200)]);

    $resultado = NotaFiscal::build(configNacional())->nfse()->consultarConvenio('3152501');

    expect($resultado['conveniado'])->toBeTrue()
        ->and($resultado['bruto']['mensagem'])->toBe('OK');

    $gateway = \DanielBBarcelos\NotasFiscais\Drivers\Nacional\NacionalNfseGateway::class;
    expect($gateway::lerConveniado(['dados' => ['aderenteAmbienteNacional' => 0]]))->toBeFalse()
        ->and($gateway::lerConveniado(['parametrosConvenio' => ['aderenteAmbienteNacional' => -1]]))->toBeNull()
        ->and($gateway::lerConveniado(['outro' => 'x']))->toBeNull();
});

it('consulta a alíquota no ADN com o código pontuado e a competência', function () {
    Http::fake([NacionalConnector::URL_PARAMETROS_PRODUCAO.'/3152501/01.07.01.000/2026-11-03/aliquota' => Http::response([
        'mensagem' => null,
        'aliquotas' => ['01.07.01.000' => [
            ['Incidencia' => 'Municipal', 'Aliq' => 3.0, 'DtIni' => '2025-01-01T00:00:00', 'DtFim' => '2025-12-31T00:00:00'],
            ['Incidencia' => 'Municipal', 'Aliq' => 2.5, 'DtIni' => '2026-01-01T00:00:00', 'DtFim' => null],
        ]],
    ], 200)]);

    $resultado = NotaFiscal::build(configNacional())->nfse()->consultarAliquota('3152501', '01.07.01', '2026-11-03');

    // A faixa vigente na competência, não a primeira do histórico.
    expect($resultado['aliquota'])->toBe(2.5);
});

it('não chama mais o caminho antigo de parâmetros da Sefin', function () {
    Http::fake([
        NacionalConnector::URL_PARAMETROS_PRODUCAO.'/*' => Http::response(['parametrosConvenio' => ['aderenteAmbienteNacional' => 1]], 200),
        '*' => Http::response([], 404),
    ]);

    NotaFiscal::build(configNacional())->nfse()->consultarConvenio('3152501');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'parametros_municipais'));
    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://adn.nfse.gov.br/parametrizacao/3152501/convenio'));
});
