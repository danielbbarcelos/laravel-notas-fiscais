<?php

declare(strict_types=1);

namespace DanielBBarcelos\NotasFiscais\Http\Controllers;

use DanielBBarcelos\NotasFiscais\Data\Nfse\ItemServico;
use DanielBBarcelos\NotasFiscais\Data\Nfse\NotaServico;
use DanielBBarcelos\NotasFiscais\Data\Nfse\Prestador;
use DanielBBarcelos\NotasFiscais\Data\Nfse\Tomador;
use DanielBBarcelos\NotasFiscais\Data\Shared\Endereco;
use DanielBBarcelos\NotasFiscais\Data\Shared\Valor;
use DanielBBarcelos\NotasFiscais\Drivers\Ipm\Mappers\NotaServicoMapper;
use DanielBBarcelos\NotasFiscais\Drivers\IpmAbrasf\Mappers\DeclaracaoMapper;
use DanielBBarcelos\NotasFiscais\Enums\SituacaoTributaria;
use DanielBBarcelos\NotasFiscais\Enums\TipoTomador;
use DanielBBarcelos\NotasFiscais\Exceptions\NotaFiscalException;
use DanielBBarcelos\NotasFiscais\Facades\NotaFiscal;
use DOMDocument;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Playground de validação visual da NFS-e. Só é registrado quando
 * config('notas-fiscais.demo') === true. NÃO é parte da API pública do pacote.
 * Suporta os dois padrões do IPM: REST proprietário ('ipm') e ABRASF ('ipm-abrasf').
 */
class DemoController
{
    public function index(): View
    {
        return view('notas-fiscais::demo', $this->dadosDaView());
    }

    public function emitir(Request $request): View
    {
        $padrao = $this->padrao($request);
        $backend = $request->input('backend', 'faked');
        $cenario = $request->input('cenario', 'sucesso');

        $nota = $this->montarNota($request);

        // Pré-visualização do XML que seria enviado.
        $prestadorXml = $nota->prestador ?? $this->prestadorDaConfig($padrao);
        $xmlEnviado = $prestadorXml !== null
            ? $this->formatar($this->construirXml($padrao, $nota, $prestadorXml))
            : "(defina o prestador no formulário ou em notas-fiscais.drivers.{$padrao})";

        // No modo faked, interceptamos a chamada HTTP devolvendo o XML do cenário.
        if ($backend === 'faked') {
            Http::fake(['*' => Http::response($this->xmlCenario($padrao, $cenario))]);
        }

        $resultado = null;
        $erro = null;

        try {
            $resultado = NotaFiscal::driver($padrao)->nfse()->emitir($nota);
        } catch (NotaFiscalException $e) {
            $erro = $e;
        } catch (Throwable $e) {
            $erro = $e;
        }

        return view('notas-fiscais::demo', array_merge($this->dadosDaView($request), [
            'xmlEnviado' => $xmlEnviado,
            'resultado' => $resultado,
            'erro' => $erro,
        ]));
    }

    protected function padrao(?Request $request): string
    {
        $valor = $request?->input('padrao', 'ipm') ?? 'ipm';

        return $valor === 'ipm-abrasf' ? 'ipm-abrasf' : 'ipm';
    }

    protected function construirXml(string $padrao, NotaServico $nota, Prestador $prestador): string
    {
        if ($padrao === 'ipm-abrasf') {
            return (new DeclaracaoMapper)->paraApi($nota, $prestador, $nota->teste);
        }

        return (new NotaServicoMapper)->paraApi($nota, $prestador);
    }

    protected function montarNota(Request $request): NotaServico
    {
        $padrao = $this->padrao($request);
        $cidade = $request->input('end_cidade') ?: (string) $this->configValor($padrao, $padrao === 'ipm-abrasf' ? 'codigo_ibge' : 'cidade');

        $valorTotal = Valor::reais((string) $request->input('valor', '0'));
        $aliquota = (float) str_replace(',', '.', (string) $request->input('item_aliquota', '0'));
        $valorIss = Valor::centavos((int) round($valorTotal->centavos * $aliquota / 100));

        $tomador = new Tomador(
            tipo: TipoTomador::from((string) $request->input('tomador_tipo', 'J')),
            identificacao: (string) $request->input('tomador_doc', ''),
            nomeRazaoSocial: (string) $request->input('tomador_nome', ''),
            email: $request->input('tomador_email') ?: null,
            endereco: new Endereco(
                logradouro: $request->input('end_logradouro') ?: null,
                numero: $request->input('end_numero') ?: null,
                bairro: $request->input('end_bairro') ?: null,
                codigoMunicipio: $request->input('end_cidade') ?: null,
                uf: $request->input('end_uf') ?: null,
                cep: $request->input('end_cep') ?: null,
            ),
        );

        $item = new ItemServico(
            codigoItemListaServico: (string) $request->input('item_codigo', ''),
            descritivo: (string) $request->input('item_descritivo', ''),
            aliquota: $aliquota,
            situacaoTributaria: SituacaoTributaria::from((int) $request->input('item_situacao', 0)),
            valorTributavel: $valorTotal,
            codigoLocalPrestacao: $cidade !== '' ? $cidade : '0',
            codigoCnae: $request->input('item_cnae') ?: null,
            issRetido: false,
            valorIss: $valorIss,
        );

        $prestador = null;
        if ($request->filled('prestador_doc') && $request->filled('prestador_cidade')) {
            $prestador = new Prestador(
                cpfCnpj: (string) $request->input('prestador_doc'),
                codigoMunicipio: (string) $request->input('prestador_cidade'),
                inscricaoMunicipal: $request->input('prestador_im') ?: null,
            );
        }

        return new NotaServico(
            serie: (int) $request->input('serie', 1),
            dataFatoGerador: (string) $request->input('data_fato_gerador', date('d/m/Y')),
            valorTotal: $valorTotal,
            tomador: $tomador,
            itens: [$item],
            prestador: $prestador,
            observacao: $request->input('observacao') ?: null,
            teste: $request->boolean('teste'),
            competencia: $request->input('competencia') ?: date('Y-m-01'),
        );
    }

    protected function prestadorDaConfig(string $padrao): ?Prestador
    {
        $cpfCnpj = $this->configValor($padrao, 'cpf_cnpj');

        if ($cpfCnpj === null) {
            return null;
        }

        if ($padrao === 'ipm-abrasf') {
            $ibge = $this->configValor($padrao, 'codigo_ibge');

            return $ibge === null ? null : new Prestador(
                cpfCnpj: $cpfCnpj,
                codigoMunicipio: $ibge,
                inscricaoMunicipal: $this->configValor($padrao, 'inscricao_municipal'),
            );
        }

        $cidade = $this->configValor($padrao, 'cidade');

        return $cidade === null ? null : new Prestador($cpfCnpj, $cidade);
    }

    protected function configValor(string $padrao, string $chave): ?string
    {
        $valor = config("notas-fiscais.drivers.{$padrao}.{$chave}");

        return $valor === null ? null : (string) $valor;
    }

    /**
     * @return array<string, mixed>
     */
    protected function dadosDaView(?Request $request = null): array
    {
        $padrao = $this->padrao($request);

        return [
            'padrao' => $padrao,
            'situacoes' => SituacaoTributaria::cases(),
            'tipos' => TipoTomador::cases(),
            'configPrestador' => $this->prestadorDaConfig($padrao),
            'request' => $request,
            'xmlEnviado' => null,
            'resultado' => null,
            'erro' => null,
        ];
    }

    protected function formatar(string $xml): string
    {
        $dom = new DOMDocument;
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;

        $anterior = libxml_use_internal_errors(true);
        $dom->loadXML($xml);
        libxml_use_internal_errors($anterior);

        return (string) $dom->saveXML();
    }

    protected function xmlCenario(string $padrao, string $cenario): string
    {
        return $padrao === 'ipm-abrasf'
            ? $this->xmlCenarioAbrasf($cenario)
            : $this->xmlCenarioRest($cenario);
    }

    protected function xmlCenarioRest(string $cenario): string
    {
        return match ($cenario) {
            'erro' => <<<'XML'
                <?xml version="1.0" encoding="UTF-8"?>
                <retorno>
                    <mensagem><codigo>00128 - Erro na validação dos dados</codigo></mensagem>
                </retorno>
                XML,
            'cancelado' => <<<'XML'
                <?xml version="1.0" encoding="UTF-8"?>
                <retorno>
                    <mensagem><codigo>00001 - Sucesso</codigo></mensagem>
                    <numero_nfse>1293</numero_nfse>
                    <serie_nfse>1</serie_nfse>
                    <situacao_codigo_nfse>2</situacao_codigo_nfse>
                    <situacao_descricao_nfse>Cancelada</situacao_descricao_nfse>
                </retorno>
                XML,
            default => <<<'XML'
                <?xml version="1.0" encoding="UTF-8"?>
                <retorno>
                    <mensagem><codigo>00001 - Sucesso</codigo></mensagem>
                    <numero_nfse>1293</numero_nfse>
                    <serie_nfse>1</serie_nfse>
                    <data_nfse>28/10/2026</data_nfse>
                    <hora_nfse>10:39:12</hora_nfse>
                    <situacao_codigo_nfse>1</situacao_codigo_nfse>
                    <situacao_descricao_nfse>Emitida</situacao_descricao_nfse>
                    <link_nfse>https://riodosul.atende.net/?pg=autoatendimento&amp;identificador=835773809025825307</link_nfse>
                    <cod_verificador_autenticidade>835773809025825307202210281202023961913</cod_verificador_autenticidade>
                </retorno>
                XML,
        };
    }

    protected function xmlCenarioAbrasf(string $cenario): string
    {
        if ($cenario === 'erro') {
            return <<<'XML'
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
                XML;
        }

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
}
