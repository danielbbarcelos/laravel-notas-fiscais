<?php

declare(strict_types=1);

namespace DanielBBarcelos\NotasFiscais\Drivers\IpmAbrasf\Mappers;

use DanielBBarcelos\NotasFiscais\Data\Nfse\ItemServico;
use DanielBBarcelos\NotasFiscais\Data\Nfse\NotaEmitida;
use DanielBBarcelos\NotasFiscais\Data\Nfse\NotaServico;
use DanielBBarcelos\NotasFiscais\Data\Nfse\Prestador;
use DanielBBarcelos\NotasFiscais\Data\Nfse\Tomador;
use DanielBBarcelos\NotasFiscais\Data\Shared\Valor;
use DanielBBarcelos\NotasFiscais\Drivers\IpmAbrasf\AbrasfXml;
use DanielBBarcelos\NotasFiscais\Enums\SituacaoNota;
use DOMDocument;
use DOMElement;
use InvalidArgumentException;

/**
 * De-para entre o DTO canônico de emissão e o envelope SOAP do IPM no padrão
 * ABRASF 2.04, e da resposta de volta para NotaEmitida. Único lugar que conhece
 * as tags ABRASF.
 *
 * Emissão real usa GerarNfseEnvio (síncrono, 1 RPS). O modo teste (dry-run) usa
 * EnviarLoteRpsSincronoEnvio, pois o <EnvioTeste> só é aceito nessa operação —
 * no GerarNfse o XSD não prevê esse elemento.
 */
class DeclaracaoMapper
{
    /** Canônico -> SOAP do IPM (ABRASF). */
    public function paraApi(NotaServico $dados, ?Prestador $prestadorPadrao = null, bool $teste = false): string
    {
        $prestador = $dados->prestador ?? $prestadorPadrao;

        if ($prestador === null) {
            throw new InvalidArgumentException(
                'Prestador não informado: defina-o na NotaServico ou em notas-fiscais.drivers.{provedor}.'
            );
        }

        if ($dados->itens === []) {
            throw new InvalidArgumentException('NotaServico sem itens: o ABRASF exige um serviço.');
        }

        $item = $dados->itens[0];
        $municipio = $item->codigoLocalPrestacao !== '' ? $item->codigoLocalPrestacao : $prestador->codigoMunicipio;

        [$dom, $body] = AbrasfXml::envelope();

        if ($teste) {
            $this->envioLoteTeste($dom, $body, $dados, $prestador, $item, $municipio);
        } else {
            $gerar = $this->el($dom, $body, 'GerarNfseEnvio');
            $rps = $this->el($dom, $gerar, 'Rps');
            $this->declaracao($dom, $rps, $dados, $prestador, $item, $municipio);
        }

        return (string) $dom->saveXML();
    }

    /** EnviarLoteRpsSincronoEnvio com <EnvioTeste>1>: valida sem emitir. */
    protected function envioLoteTeste(DOMDocument $dom, DOMElement $body, NotaServico $dados, Prestador $prestador, ItemServico $item, string $municipio): void
    {
        $envio = $this->el($dom, $body, 'EnviarLoteRpsSincronoEnvio');
        $this->add($dom, $envio, 'EnvioTeste', '1');

        $lote = $this->el($dom, $envio, 'LoteRps');
        $lote->setAttribute('Id', 'LOTE_'.bin2hex(random_bytes(4)));
        $lote->setAttribute('versao', '2.04');
        $this->add($dom, $lote, 'NumeroLote', '1');
        $cpfcnpj = $this->el($dom, $lote, 'CpfCnpj');
        $this->add($dom, $cpfcnpj, 'Cnpj', $prestador->cpfCnpjLimpo());
        if ($prestador->inscricaoMunicipal !== null) {
            $this->add($dom, $lote, 'InscricaoMunicipal', $prestador->inscricaoMunicipal);
        }
        $this->add($dom, $lote, 'QuantidadeRps', '1');

        $listaRps = $this->el($dom, $lote, 'ListaRps');
        $rps = $this->el($dom, $listaRps, 'Rps');
        $this->declaracao($dom, $rps, $dados, $prestador, $item, $municipio);
    }

    /** Monta <InfDeclaracaoPrestacaoServico> dentro do <Rps> informado. */
    protected function declaracao(DOMDocument $dom, DOMElement $rps, NotaServico $dados, Prestador $prestador, ItemServico $item, string $municipio): void
    {
        $inf = $this->el($dom, $rps, 'InfDeclaracaoPrestacaoServico');
        $inf->setAttribute('Id', 'RPS_'.bin2hex(random_bytes(4)));

        $this->add($dom, $inf, 'Competencia', $dados->competencia ?? date('Y-m-d'));

        // <Servico>
        $servico = $this->el($dom, $inf, 'Servico');
        $valores = $this->el($dom, $servico, 'Valores');
        $this->add($dom, $valores, 'ValorServicos', $this->v($item->valorTributavel));
        $this->add($dom, $valores, 'ValorDeducoes', $this->v($item->deducao));
        $this->add($dom, $valores, 'ValorPis', $this->v($dados->valorPis));
        $this->add($dom, $valores, 'ValorCofins', $this->v($dados->valorCofins));
        $this->add($dom, $valores, 'ValorInss', $this->v($dados->valorInss));
        $this->add($dom, $valores, 'ValorIr', $this->v($dados->valorIr));
        $this->add($dom, $valores, 'ValorCsll', $this->v($dados->valorContribuicaoSocial));
        $this->add($dom, $valores, 'OutrasRetencoes', '0.00');
        $this->add($dom, $valores, 'ValorIss', $this->v($item->valorIss));
        $this->add($dom, $valores, 'Aliquota', $this->dec($item->aliquota));
        $this->add($dom, $valores, 'DescontoIncondicionado', $this->v($dados->valorDesconto));
        $this->add($dom, $valores, 'DescontoCondicionado', '0.00');

        $this->add($dom, $servico, 'IssRetido', $item->issRetido === true ? '1' : '2');
        $this->add($dom, $servico, 'ItemListaServico', $item->codigoItemListaServico);
        if ($item->codigoCnae !== null) {
            $this->add($dom, $servico, 'CodigoCnae', $item->codigoCnae);
        }
        $this->add($dom, $servico, 'Discriminacao', $item->descritivo);
        $this->add($dom, $servico, 'CodigoMunicipio', $municipio);
        $this->add($dom, $servico, 'ExigibilidadeISS', $item->exigibilidadeIss ?? '1');
        $this->add($dom, $servico, 'MunicipioIncidencia', $municipio);

        // <Prestador>
        $prest = $this->el($dom, $inf, 'Prestador');
        $cpfcnpjPrest = $this->el($dom, $prest, 'CpfCnpj');
        $this->add($dom, $cpfcnpjPrest, 'Cnpj', $prestador->cpfCnpjLimpo());
        if ($prestador->inscricaoMunicipal !== null) {
            $this->add($dom, $prest, 'InscricaoMunicipal', $prestador->inscricaoMunicipal);
        }

        // <TomadorServico>
        $this->tomadorNode($dom, $inf, $dados->tomador);

        $this->add($dom, $inf, 'OptanteSimplesNacional', $dados->optanteSimplesNacional ? '1' : '2');
        $this->add($dom, $inf, 'IncentivoFiscal', $dados->incentivoFiscal ? '1' : '2');

        if ($dados->observacao !== null) {
            $this->add($dom, $inf, 'InformacoesComplementares', $dados->observacao);
        }
    }

    /** Resposta do IPM -> DTO canônico. */
    public function paraDominio(string $xmlResposta, ?string $linkTemplate = null): NotaEmitida
    {
        $dom = AbrasfXml::parse($xmlResposta);

        $numero = AbrasfXml::texto($dom, 'Numero');
        $codigo = AbrasfXml::texto($dom, 'CodigoVerificacao');
        $link = AbrasfXml::texto($dom, 'link_nfse') ?? AbrasfXml::texto($dom, 'LinkNfse')
            ?? AbrasfXml::montarLink($linkTemplate, $codigo, $numero);
        $dataEmissao = AbrasfXml::texto($dom, 'DataEmissao');

        return new NotaEmitida(
            numero: $numero !== null ? (int) $numero : null,
            serie: null,
            data: $dataEmissao,
            hora: null,
            situacao: $numero !== null ? SituacaoNota::Emitida : null,
            codigoVerificacao: $codigo,
            link: $link,
            bruto: ['xml_response' => $xmlResposta],
        );
    }

    protected function tomadorNode(DOMDocument $dom, DOMElement $inf, Tomador $t): void
    {
        $tomador = $this->el($dom, $inf, 'TomadorServico');

        $idTom = $this->el($dom, $tomador, 'IdentificacaoTomador');
        $cpfcnpj = $this->el($dom, $idTom, 'CpfCnpj');
        $this->add($dom, $cpfcnpj, $t->ehCnpj() ? 'Cnpj' : 'Cpf', $t->identificacaoLimpa());

        $this->add($dom, $tomador, 'RazaoSocial', $t->nomeRazaoSocial);

        $e = $t->endereco;
        if ($e !== null && $e->logradouro !== null) {
            $endereco = $this->el($dom, $tomador, 'Endereco');
            $this->add($dom, $endereco, 'Endereco', $e->logradouro);
            if ($e->numero !== null) {
                $this->add($dom, $endereco, 'Numero', $e->numero);
            }
            if ($e->complemento !== null) {
                $this->add($dom, $endereco, 'Complemento', $e->complemento);
            }
            if ($e->bairro !== null) {
                $this->add($dom, $endereco, 'Bairro', $e->bairro);
            }
            if ($e->codigoMunicipio !== null) {
                $this->add($dom, $endereco, 'CodigoMunicipio', $e->codigoMunicipio);
            }
            if ($e->uf !== null) {
                $this->add($dom, $endereco, 'Uf', $e->uf);
            }
            if (($cep = $e->cepLimpo()) !== null) {
                $this->add($dom, $endereco, 'Cep', $cep);
            }
        }

        if ($t->email !== null) {
            $contato = $this->el($dom, $tomador, 'Contato');
            $this->add($dom, $contato, 'Email', $t->email);
        }
    }

    protected function el(DOMDocument $dom, DOMElement $pai, string $tag): DOMElement
    {
        $el = $dom->createElement($tag);
        $pai->appendChild($el);

        return $el;
    }

    protected function add(DOMDocument $dom, DOMElement $pai, string $tag, string $valor): void
    {
        $el = $dom->createElement($tag);
        $el->appendChild($dom->createTextNode($valor));
        $pai->appendChild($el);
    }

    /** Valor monetário no formato ABRASF: ponto decimal, 2 casas. */
    protected function v(?Valor $valor): string
    {
        return $valor?->paraApi() ?? '0.00';
    }

    protected function dec(float|string $n): string
    {
        $f = is_string($n) ? (float) str_replace(',', '.', $n) : $n;

        return number_format($f, 2, '.', '');
    }
}
