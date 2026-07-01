<?php

declare(strict_types=1);

namespace DanielBBarcelos\NotasFiscais\Drivers\Ipm\Mappers;

use DanielBBarcelos\NotasFiscais\Data\Nfse\FormaPagamento;
use DanielBBarcelos\NotasFiscais\Data\Nfse\ItemServico;
use DanielBBarcelos\NotasFiscais\Data\Nfse\NotaEmitida;
use DanielBBarcelos\NotasFiscais\Data\Nfse\NotaServico;
use DanielBBarcelos\NotasFiscais\Data\Nfse\Prestador;
use DanielBBarcelos\NotasFiscais\Data\Nfse\Tomador;
use DanielBBarcelos\NotasFiscais\Data\Shared\Valor;
use DanielBBarcelos\NotasFiscais\Enums\SituacaoNota;
use DanielBBarcelos\NotasFiscais\Enums\TipoTomador;
use DOMDocument;
use DOMElement;
use InvalidArgumentException;

/**
 * De-para entre o DTO canônico de emissão e o XML <nfse> do IPM (GearNfseEnvio),
 * e da resposta <retorno> de volta para NotaEmitida. Este é o único lugar que
 * conhece os nomes de tag do IPM para emissão.
 */
class NotaServicoMapper
{
    use EscreveXml;

    /** Canônico -> XML de emissão do IPM. */
    public function paraApi(NotaServico $dados, ?Prestador $prestadorPadrao = null): string
    {
        $prestador = $dados->prestador ?? $prestadorPadrao;

        if ($prestador === null) {
            throw new InvalidArgumentException(
                'Prestador não informado: defina-o na NotaServico ou em notas-fiscais.drivers.{provedor}.'
            );
        }

        $dom = $this->novoDocumento();

        $nfse = $dom->createElement('nfse');
        if ($dados->assinada) {
            $nfse->setAttribute('id', 'nota');
        }
        $dom->appendChild($nfse);

        if ($dados->identificador !== null) {
            $this->add($dom, $nfse, 'identificador', $dados->identificador);
        }

        if ($dados->teste) {
            $this->add($dom, $nfse, 'nfse_teste', '1');
        }

        $nfse->appendChild($this->nfNode($dom, $dados));
        $nfse->appendChild($this->prestadorNode($dom, $prestador));
        $nfse->appendChild($this->tomadorNode($dom, $dados->tomador));
        $nfse->appendChild($this->itensNode($dom, $dados->itens));

        if ($dados->formaPagamento !== null) {
            $nfse->appendChild($this->pagamentoNode($dom, $dados->formaPagamento));
        }

        return (string) $dom->saveXML();
    }

    /** Resposta <retorno> do IPM -> DTO canônico. */
    public function paraDominio(array $retorno): NotaEmitida
    {
        // Retorno reduzido traz os campos direto sob <retorno>; o completo os
        // aninha em <nfse>. Mesclamos para ler de um único lugar.
        $d = $retorno;
        if (isset($retorno['nfse']) && is_array($retorno['nfse'])) {
            $d = array_merge($retorno, $retorno['nfse']);
        }

        $situacaoCodigo = $this->texto($d['situacao_codigo_nfse'] ?? null);

        return new NotaEmitida(
            numero: $this->inteiro($d['numero_nfse'] ?? null),
            serie: $this->inteiro($d['serie_nfse'] ?? null),
            data: $this->texto($d['data_nfse'] ?? null),
            hora: $this->texto($d['hora_nfse'] ?? null),
            situacao: $situacaoCodigo !== null ? SituacaoNota::tryFrom((int) $situacaoCodigo) : null,
            codigoVerificacao: $this->texto($d['cod_verificador_autenticidade'] ?? null),
            link: $this->texto($d['link_nfse'] ?? null),
            bruto: $retorno,
        );
    }

    protected function nfNode(DOMDocument $dom, NotaServico $dados): DOMElement
    {
        $nf = $dom->createElement('nf');

        $this->add($dom, $nf, 'serie_nfse', (string) $dados->serie);
        $this->add($dom, $nf, 'data_fato_gerador', $dados->dataFatoGerador);
        $this->add($dom, $nf, 'valor_total', $dados->valorTotal->paraReal());
        $this->addValor($dom, $nf, 'valor_desconto', $dados->valorDesconto);
        $this->addValor($dom, $nf, 'valor_ir', $dados->valorIr);
        $this->addValor($dom, $nf, 'valor_inss', $dados->valorInss);
        $this->addValor($dom, $nf, 'valor_contribuicao_social', $dados->valorContribuicaoSocial);
        $this->addValor($dom, $nf, 'valor_rps', $dados->valorRps);
        $this->addValor($dom, $nf, 'valor_pis', $dados->valorPis);
        $this->addValor($dom, $nf, 'valor_cofins', $dados->valorCofins);

        if ($dados->observacao !== null) {
            $this->add($dom, $nf, 'observacao', $dados->observacao);
        }

        return $nf;
    }

    protected function prestadorNode(DOMDocument $dom, Prestador $prestador): DOMElement
    {
        $node = $dom->createElement('prestador');
        $this->add($dom, $node, 'cpfcnpj', $prestador->cpfCnpjLimpo());
        $this->add($dom, $node, 'cidade', $prestador->codigoMunicipio);

        return $node;
    }

    protected function tomadorNode(DOMDocument $dom, Tomador $t): DOMElement
    {
        $node = $dom->createElement('tomador');

        $this->add($dom, $node, 'tipo', $t->tipo->value);

        if ($t->tipo === TipoTomador::Estrangeiro) {
            $this->add($dom, $node, 'identificador', $t->identificacao);
            if ($t->estado !== null) {
                $this->add($dom, $node, 'estado', $t->estado);
            }
            if ($t->pais !== null) {
                $this->add($dom, $node, 'pais', $t->pais);
            }
            $this->add($dom, $node, 'endereco_informado', $t->endereco !== null ? 'S' : 'N');
        } else {
            $this->add($dom, $node, 'cpfcnpj', $t->identificacaoLimpa());
        }

        if ($t->inscricaoEstadual !== null) {
            $this->add($dom, $node, 'ie', $t->inscricaoEstadual);
        }

        $this->add($dom, $node, 'nome_razao_social', $t->nomeRazaoSocial);

        if ($t->nomeFantasia !== null) {
            $this->add($dom, $node, 'sobrename_nome_fantasia', $t->nomeFantasia);
        }

        $e = $t->endereco;
        if ($e !== null && $e->logradouro !== null) {
            $this->add($dom, $node, 'logradouro', $e->logradouro);
        }

        if ($t->email !== null) {
            $this->add($dom, $node, 'email', $t->email);
        }

        if ($e !== null) {
            if ($e->numero !== null) {
                $this->add($dom, $node, 'numero_residencia', $e->numero);
            }
            if ($e->complemento !== null) {
                $this->add($dom, $node, 'complemento', $e->complemento);
            }
            if ($e->pontoReferencia !== null) {
                $this->add($dom, $node, 'ponto_referencia', $e->pontoReferencia);
            }
            if ($e->bairro !== null) {
                $this->add($dom, $node, 'bairro', $e->bairro);
            }
            if ($e->codigoMunicipio !== null) {
                $this->add($dom, $node, 'cidade', $e->codigoMunicipio);
            }
            if (($cep = $e->cepLimpo()) !== null) {
                $this->add($dom, $node, 'cep', $cep);
            }
        }

        if ($t->telefoneComercial !== null) {
            $this->add($dom, $node, 'ddd_fone_comercial', $t->telefoneComercial->dddLimpo());
            $this->add($dom, $node, 'fone_comercial', $t->telefoneComercial->numeroLimpo());
        }

        if ($t->telefoneResidencial !== null) {
            $this->add($dom, $node, 'ddd_fone_residencial', $t->telefoneResidencial->dddLimpo());
            $this->add($dom, $node, 'fone_residencial', $t->telefoneResidencial->numeroLimpo());
        }

        return $node;
    }

    /**
     * @param  list<ItemServico>  $itens
     */
    protected function itensNode(DOMDocument $dom, array $itens): DOMElement
    {
        $node = $dom->createElement('itens');

        foreach ($itens as $item) {
            $node->appendChild($this->listaNode($dom, $item));
        }

        return $node;
    }

    protected function listaNode(DOMDocument $dom, ItemServico $i): DOMElement
    {
        $node = $dom->createElement('lista');

        $this->add($dom, $node, 'tributa_municipio_prestador', $i->tributaMunicipioPrestador ? 'S' : 'N');
        $this->add($dom, $node, 'codigo_local_prestacao_servico', $i->codigoLocalPrestacao);

        if ($i->unidadeCodigo !== null) {
            $this->add($dom, $node, 'unidade_codigo', $i->unidadeCodigo);
        }
        if ($i->unidadeQuantidade !== null) {
            $this->add($dom, $node, 'unidade_quantidade', $this->numero($i->unidadeQuantidade));
        }
        if ($i->unidadeValorUnitario !== null) {
            $this->add($dom, $node, 'unidade_valor_unitario', $i->unidadeValorUnitario->paraReal());
        }

        $this->add($dom, $node, 'codigo_item_lista_servico', $i->codigoItemListaServico);

        if ($i->codigoAtividade !== null) {
            $this->add($dom, $node, 'codigo_atividade', $i->codigoAtividade);
        }

        $this->add($dom, $node, 'descritivo', $i->descritivo);
        $this->add($dom, $node, 'aliquota_item_lista_servico', $this->numero($i->aliquota));
        $this->add($dom, $node, 'situacao_tributaria', (string) $i->situacaoTributaria->value);
        $this->add($dom, $node, 'valor_tributavel', $i->valorTributavel->paraReal());

        if ($i->deducao !== null) {
            $this->add($dom, $node, 'valor_deducao', $i->deducao->paraReal());
        }
        if ($i->issRetidoFonte !== null) {
            $this->add($dom, $node, 'valor_issrf', $i->issRetidoFonte->paraReal());
        }

        return $node;
    }

    protected function pagamentoNode(DOMDocument $dom, FormaPagamento $fp): DOMElement
    {
        $node = $dom->createElement('forma_pagamento');
        $this->add($dom, $node, 'tipo_pagamento', (string) $fp->tipo->value);

        if ($fp->parcelas !== []) {
            $parcelas = $dom->createElement('parcelas');
            foreach ($fp->parcelas as $p) {
                $pn = $dom->createElement('parcela');
                $this->add($dom, $pn, 'numero', (string) $p->numero);
                $this->add($dom, $pn, 'valor', $p->valor->paraReal());
                $this->add($dom, $pn, 'data_vencimento', $p->dataVencimento);
                $parcelas->appendChild($pn);
            }
            $node->appendChild($parcelas);
        }

        return $node;
    }

    protected function addValor(DOMDocument $dom, DOMElement $pai, string $tag, ?Valor $valor): void
    {
        if ($valor !== null) {
            $this->add($dom, $pai, $tag, $valor->paraReal());
        }
    }

    /** Formata percentuais/quantidades com vírgula decimal, como o IPM exige. */
    protected function numero(float|string $valor, int $casas = 2): string
    {
        $n = is_string($valor) ? (float) str_replace(',', '.', $valor) : $valor;

        return number_format($n, $casas, ',', '');
    }

    protected function inteiro(mixed $valor): ?int
    {
        $texto = $this->texto($valor);

        return $texto !== null ? (int) $texto : null;
    }

    protected function texto(mixed $valor): ?string
    {
        // Tags XML vazias viram [] no parse; tratamos como ausência.
        if ($valor === null || is_array($valor)) {
            return null;
        }

        $texto = trim((string) $valor);

        return $texto === '' ? null : $texto;
    }
}
