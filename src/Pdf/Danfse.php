<?php

declare(strict_types=1);

namespace DanielBBarcelos\NotasFiscais\Pdf;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use DanielBBarcelos\NotasFiscais\Drivers\Nacional\Mappers\DpsMapper;
use DanielBBarcelos\NotasFiscais\Drivers\Nacional\NacionalXml;
use DanielBBarcelos\NotasFiscais\Exceptions\NotaFiscalException;
use DOMDocument;
use DOMElement;
use Dompdf\Dompdf;

/**
 * DANFSe v2.0 (Documento Auxiliar da NFS-e) do padrão nacional, conforme a Nota
 * Técnica SE/CGNFS-e nº 008/2026 (v1.02). Desde 03/08/2026 a API do ADN que
 * devolvia o PDF pronto está suspensa, e o DANFSe passou a ser gerado pelo
 * sistema emissor.
 *
 * Regras da NT que moldam esta classe:
 *  - o DANFSe só mostra o que consta do XML da NFS-e (item 2.1), por isso a
 *    entrada é o XML devolvido pela Sefin, e não o NotaServico;
 *  - uma página A4 retrato, margens de 0,15 a 0,20 cm, borda de 1 pt, linhas de
 *    0,5 pt, cabeçalho, títulos de bloco, "Emitente da NFS-e" e "Valor Líquido +
 *    IBS/CBS" com fundo cinza 5% (item 2.2);
 *  - Arial nos títulos e Microsoft Sans Serif no conteúdo (item 2.4). O dompdf
 *    não traz nenhuma das duas; a Helvetica, de métrica igual à da Arial, ocupa
 *    as duas funções;
 *  - QR Code da consulta pública, com no mínimo 1,52 cm (item 2.4.3);
 *  - "NFS-e SEM VALIDADE JURÍDICA" em vermelho quando tpAmb = 2, e marca d'água
 *    "CANCELADA"/"SUBSTITUÍDA" (itens 2.4.3 e 2.5). Cancelamento e substituição
 *    são eventos, não estão no XML da nota: quem chama informa.
 *
 * Requer dompdf/dompdf e bacon/bacon-qr-code (dependências opcionais).
 */
final class Danfse
{
    /** Linhas de texto de 7 pt por centímetro, e caracteres por linha na largura útil da página. */
    private const LINHAS_POR_CM = 3.1;

    private const CARACTERES_POR_LINHA = 150;

    private DOMDocument $dom;

    private ?DOMElement $inf;

    private ?DOMElement $dps;

    /**
     * @param  callable(string): ?string|null  $municipio  nome do município pelo código IBGE, para os
     *                                                     endereços que o XML traz só como código
     */
    private function __construct(
        string $xmlNfse,
        private readonly bool $cancelada,
        private readonly bool $substituida,
        private readonly bool $canhoto,
        private readonly mixed $municipio,
    ) {
        $this->dom = NacionalXml::parse($xmlNfse);
        $inf = $this->dom->getElementsByTagNameNS('*', 'infNFSe')->item(0);
        $dps = $this->dom->getElementsByTagNameNS('*', 'infDPS')->item(0);
        $this->inf = $inf instanceof DOMElement ? $inf : null;
        $this->dps = $dps instanceof DOMElement ? $dps : null;

        if ($this->inf === null) {
            throw new NotaFiscalException('O XML informado não é de uma NFS-e do padrão nacional (sem <infNFSe>).');
        }
    }

    /**
     * Gera o PDF e devolve os bytes.
     *
     * @param  array{cancelada?: bool, substituida?: bool, canhoto?: bool, municipio?: callable(string): ?string}  $opcoes
     */
    public static function gerar(string $xmlNfse, array $opcoes = []): string
    {
        if (! class_exists(Dompdf::class)) {
            throw new NotaFiscalException('Para gerar o DANFSe, instale o dompdf: composer require dompdf/dompdf.');
        }

        if (! class_exists(Writer::class)) {
            throw new NotaFiscalException('Para gerar o QR Code do DANFSe, instale o bacon/bacon-qr-code: composer require bacon/bacon-qr-code.');
        }

        $danfse = new self(
            $xmlNfse,
            (bool) ($opcoes['cancelada'] ?? false),
            (bool) ($opcoes['substituida'] ?? false),
            (bool) ($opcoes['canhoto'] ?? false),
            $opcoes['municipio'] ?? null,
        );

        $dompdf = new Dompdf(['isRemoteEnabled' => false, 'defaultFont' => 'Helvetica']);
        $dompdf->loadHtml($danfse->html(), 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /**
     * Salva o PDF num caminho e devolve o próprio caminho.
     *
     * @param  array{cancelada?: bool, substituida?: bool, canhoto?: bool, municipio?: callable(string): ?string}  $opcoes
     */
    public static function salvar(string $caminho, string $xmlNfse, array $opcoes = []): string
    {
        file_put_contents($caminho, self::gerar($xmlNfse, $opcoes));

        return $caminho;
    }

    /** O HTML do DANFSe (exposto para testes e para quem quiser outro renderizador). */
    public function html(): string
    {
        $chave = $this->chave();
        $homologacao = $this->txt($this->dps, 'tpAmb') === '2';

        $marca = $this->cancelada ? 'CANCELADA' : ($this->substituida ? 'SUBSTITUÍDA' : null);

        $corpo = $this->cabecalho($homologacao)
            .$this->identificacao($chave)
            .$this->prestador()
            .$this->pessoa('TOMADOR / ADQUIRENTE', $this->filho($this->dps, 'toma'), true, 'TOMADOR/ADQUIRENTE DA OPERAÇÃO NÃO IDENTIFICADO NA NFS-e')
            .$this->destinatario()
            .$this->pessoa('INTERMEDIÁRIO DA OPERAÇÃO', $this->filho($this->dps, 'interm'), true, 'INTERMEDIÁRIO DA OPERAÇÃO NÃO IDENTIFICADO NA NFS-e')
            .$this->servico()
            .$this->tributacaoMunicipal()
            .$this->tributacaoFederal()
            .$this->tributacaoIbsCbs()
            .$this->valorTotal()
            .$this->informacoesComplementares()
            .($this->canhoto ? $this->blocoCanhoto($chave) : '');

        $marcaDagua = $marca !== null ? '<div class="marca">'.$marca.'</div>' : '';
        $alturas = $this->alturasLivres();

        return <<<HTML
        <!doctype html>
        <html lang="pt-BR"><head><meta charset="utf-8"><title>DANFSe {$chave}</title>
        <style>
            @page { size: A4 portrait; margin: 0.2cm; }
            * { box-sizing: border-box; }
            body { margin: 0; font-family: Helvetica, Arial, sans-serif; color: #000; font-size: 7pt; }
            .pagina { border: 1pt solid #000; width: 20.6cm; height: 29.2cm; position: relative; overflow: hidden; }
            table { width: 100%; border-collapse: collapse; table-layout: fixed; }
            td { vertical-align: top; padding: 0.06cm 0.12cm 0.08cm; overflow: hidden; }
            .bloco { border-top: 0.5pt solid #000; }
            .cinza { background: #f2f2f2; }
            .titulo { font-weight: bold; font-size: 7pt; text-transform: uppercase; }
            .lbl { font-weight: bold; font-size: 6pt; display: block; }
            .lbl-id { font-weight: bold; font-size: 7pt; text-transform: uppercase; display: block; }
            .val { font-size: 7pt; display: block; min-height: 8pt; word-wrap: break-word; }
            .cab td { vertical-align: middle; }
            .cab .centro { text-align: center; font-weight: bold; font-size: 9pt; }
            .cab .sem-validade { color: #ff0000; font-weight: bold; font-size: 9pt; }
            .cab .municipio { font-size: 8pt; }
            .cab .amb { font-size: 6pt; }
            .qr { text-align: center; }
            .qr img { width: 1.9cm; height: 1.9cm; }
            .qr .aviso { font-size: 6pt; text-align: left; margin-top: 0.05cm; }
            .chave { font-size: 7pt; letter-spacing: 0.02cm; }
            .descricao { height: {$alturas[0]}cm; }
            .info { height: {$alturas[1]}cm; }
            .vazio { font-weight: bold; font-size: 7pt; padding: 0.12cm; }
            .canhoto td { border: 0.5pt solid #000; height: 0.95cm; }
            .marca { position: absolute; top: 12cm; left: 2.4cm; width: 16cm; text-align: center;
                     font-size: 64pt; color: #a6a6a6; transform: rotate(-35deg); font-family: Helvetica, Arial, sans-serif; }
        </style></head>
        <body><div class="pagina">{$corpo}{$marcaDagua}</div></body></html>
        HTML;
    }

    // ------------------------------------------------------------------ blocos

    private function cabecalho(bool $homologacao): string
    {
        $logo = $this->logoNfse();
        $municipio = $this->e($this->txt($this->inf, 'xLocEmi') ?? '');
        $uf = $this->e($this->txt($this->filho($this->inf, 'emit'), 'UF') ?? '');
        $ambGer = match ($this->txt($this->inf, 'ambGer')) {
            '1' => 'Prefeitura',
            '2' => 'Sistema Nacional NFS-e',
            default => '-',
        };
        $tpAmb = $homologacao ? 'Homologação' : 'Produção';
        $semValidade = $homologacao ? '<div class="sem-validade">NFS-e SEM VALIDADE JURÍDICA</div>' : '';

        return <<<HTML
        <table class="cab cinza"><tr>
            <td style="width:5.1cm"><img src="{$logo}" style="width:4cm"></td>
            <td class="centro" style="width:10.2cm">DANFSe v2.0<br>Documento Auxiliar da NFS-e{$semValidade}</td>
            <td style="width:5.3cm"><div class="municipio">Município: {$municipio}{$this->sufixoUf($uf)}</div>
                <div class="amb">Ambiente Gerador: {$ambGer}</div><div class="amb">Tipo de Ambiente: {$tpAmb}</div></td>
        </tr></table>
        HTML;
    }

    private function identificacao(string $chave): string
    {
        $qr = $this->qrCode(DpsMapper::linkConsultaPublica($chave));
        $situacao = $this->cancelada ? 'Cancelada' : ($this->substituida ? 'Substituída' : $this->situacao());
        $emitente = match ($this->txt($this->dps, 'tpEmit')) {
            '1' => 'Prestador',
            '2' => 'Tomador',
            '3' => 'Intermediário',
            default => '-',
        };
        $finalidade = $this->txt($this->filho($this->dps, 'IBSCBS'), 'finNFSe') === '0' ? 'NFS-e regular' : ($this->txt($this->filho($this->dps, 'IBSCBS'), 'finNFSe') ?? '-');

        $c = fn (string $label, ?string $valor, string $classe = '') => $this->campoId($label, $valor, $classe);

        return '<table class="bloco"><tr>'
            .'<td colspan="3">'.$this->rotuloId('Chave de Acesso da NFS-e').'<span class="val chave">'.$this->e($chave).'</span></td>'
            .'<td rowspan="4" class="qr" style="width:5.15cm"><img src="'.$qr.'"><div class="aviso">A autenticidade desta NFS-e pode ser verificada pela leitura deste código QR ou pela consulta da chave de acesso no portal nacional da NFS-e</div></td>'
            .'</tr><tr>'
            .$c('Número da NFS-e', $this->txt($this->inf, 'nNFSe'))
            .$c('Competência da NFS-e', $this->data($this->txt($this->dps, 'dCompet')))
            .$c('Data e Hora da Emissão da NFS-e', $this->dataHora($this->txt($this->inf, 'dhProc')))
            .'</tr><tr>'
            .$c('Número da DPS', $this->txt($this->dps, 'nDPS'))
            .$c('Série da DPS', $this->txt($this->dps, 'serie'))
            .$c('Data e Hora da Emissão da DPS', $this->dataHora($this->txt($this->dps, 'dhEmi')))
            .'</tr><tr>'
            .$c('Emitente da NFS-e', $emitente, 'cinza')
            .$c('Situação da NFS-e', $situacao)
            .$c('Finalidade', $finalidade)
            .'</tr></table>';
    }

    private function prestador(): string
    {
        $emit = $this->filho($this->inf, 'emit');
        $end = $this->filho($emit, 'enderNac');
        $regTrib = $this->filho($this->filho($this->dps, 'prest'), 'regTrib');

        $municipioCodigo = $this->txt($end, 'cMun');
        // O XML só traz o nome do município emissor (xLocEmi); é o do prestador quando os códigos batem.
        $municipioNome = $municipioCodigo !== null && $municipioCodigo === $this->txt($this->dps, 'cLocEmi')
            ? $this->txt($this->inf, 'xLocEmi')
            : $this->nomeMunicipio($municipioCodigo);

        return '<table class="bloco"><tr>'
            .'<td class="cinza titulo" style="width:5.1cm">PRESTADOR / FORNECEDOR</td>'
            .$this->campo('CNPJ / CPF / NIF', $this->documento($emit))
            .$this->campo('Indicador Municipal (Inscrição)', $this->txt($emit, 'IM'))
            .$this->campo('Telefone', $this->telefone($this->txt($emit, 'fone')))
            .'</tr><tr>'
            .$this->campo('Nome / Nome Empresarial', $this->txt($emit, 'xNome'), 2)
            .$this->campo('Município / Sigla UF', $this->juntar($municipioNome, $this->txt($end, 'UF')))
            .$this->campo('Código IBGE / CEP', $this->juntar($municipioCodigo, $this->cep($this->txt($end, 'CEP'))))
            .'</tr><tr>'
            .$this->campo('Endereço', $this->endereco($end), 2)
            .$this->campo('E-mail', $this->txt($emit, 'email'), 2)
            .'</tr><tr>'
            .$this->campo('Simples Nacional na Data de Competência', $this->simplesNacional($this->txt($regTrib, 'opSimpNac')))
            .$this->campo('Regime de Apuração Tributária pelo SN', $this->apuracaoSimples($this->txt($regTrib, 'regApTribSN')), 3)
            .'</tr></table>';
    }

    /**
     * Tomador ou intermediário (mesma grade). Sem a pessoa no XML, o bloco vira
     * a linha única que a NT prevê no item 2.3.1.
     */
    private function pessoa(string $titulo, ?DOMElement $p, bool $comIm, string $vazio): string
    {
        if ($p === null) {
            return '<table class="bloco"><tr><td class="vazio">'.$this->e($vazio).'</td></tr></table>';
        }

        $end = $this->filho($p, 'end');
        $endNac = $this->filho($end, 'endNac');
        $cMun = $this->txt($endNac, 'cMun');

        return '<table class="bloco"><tr>'
            .'<td class="cinza titulo" style="width:5.1cm">'.$this->e($titulo).'</td>'
            .$this->campo('CNPJ / CPF / NIF', $this->documento($p))
            .$this->campo($comIm ? 'Indicador Municipal (Inscrição)' : '', $comIm ? $this->txt($p, 'IM') : null)
            .$this->campo('Telefone', $this->telefone($this->txt($p, 'fone')))
            .'</tr><tr>'
            .$this->campo('Nome / Nome Empresarial', $this->txt($p, 'xNome'), 2)
            .$this->campo('Município / Sigla UF', $this->nomeMunicipio($cMun))
            .$this->campo('Código IBGE / CEP', $this->juntar($cMun, $this->cep($this->txt($endNac, 'CEP'))))
            .'</tr><tr>'
            .$this->campo('Endereço', $this->endereco($end), 2)
            .$this->campo('E-mail', $this->txt($p, 'email'), 2)
            .'</tr></table>';
    }

    private function destinatario(): string
    {
        $ibscbs = $this->filho($this->dps, 'IBSCBS');
        $dest = $this->filho($ibscbs, 'dest');

        if ($dest === null) {
            // indDest = 0: o destinatário é o próprio tomador (item 2.3.2).
            $mensagem = $this->txt($ibscbs, 'indDest') === '0'
                ? 'O DESTINATÁRIO É O PRÓPRIO TOMADOR/ADQUIRENTE DA OPERAÇÃO'
                : 'DESTINATÁRIO DA OPERAÇÃO NÃO IDENTIFICADO NA NFS-e';

            return '<table class="bloco"><tr><td class="vazio">'.$mensagem.'</td></tr></table>';
        }

        return $this->pessoa('DESTINATÁRIO DA OPERAÇÃO', $dest, false, '');
    }

    private function servico(): string
    {
        $serv = $this->filho($this->dps, 'serv');
        $cServ = $this->filho($serv, 'cServ');
        $locPrest = $this->filho($serv, 'locPrest');

        $codigo = $this->juntar($this->codigoTributacao($this->txt($cServ, 'cTribNac')), $this->txt($cServ, 'cTribMun'), ' / ');
        $local = $this->txt($locPrest, 'cPaisPrestacao') !== null
            ? $this->txt($locPrest, 'cPaisPrestacao')
            : $this->txt($this->inf, 'xLocPrestacao');
        // Descrição do código: a municipal quando existe, senão a nacional (nota do Anexo I).
        $descricaoCodigo = $this->txt($this->inf, 'xTribMun') ?? $this->txt($this->inf, 'xTribNac');

        return '<table class="bloco"><tr>'
            .'<td class="cinza titulo" style="width:5.1cm">SERVIÇO PRESTADO</td>'
            .$this->campo('Código de Tributação Nacional / Municipal', $codigo)
            .$this->campo('Código da NBS', $this->nbs($this->txt($cServ, 'cNBS')))
            .$this->campo('Local da Prestação / Sigla UF / País', $local)
            .'</tr><tr>'
            .'<td colspan="4"><span class="val">'.$this->e($descricaoCodigo ?? '').'</span>'
            .'<span class="lbl">Descrição do Serviço</span>'
            .'<div class="val descricao">'.nl2br($this->e($this->caber($this->txt($cServ, 'xDescServ') ?? '', $this->alturasLivres()[0] - 0.35))).'</div></td>'
            .'</tr></table>';
    }

    private function tributacaoMunicipal(): string
    {
        $trib = $this->filho($this->filho($this->filho($this->dps, 'valores'), 'trib'), 'tribMun');

        if ($trib === null) {
            return '<table class="bloco"><tr><td class="vazio">TRIBUTAÇÃO MUNICIPAL (ISSQN) - OPERAÇÃO NÃO SUJEITA AO ISSQN</td></tr></table>';
        }

        $valoresNfse = $this->filho($this->inf, 'valores');
        $valoresDps = $this->filho($this->dps, 'valores');
        $susp = $this->filho($trib, 'exigSusp');
        $bm = $this->filho($trib, 'BM');

        $linhaRegime = [
            $this->regimeEspecial($this->txt($this->filho($this->filho($this->dps, 'prest'), 'regTrib'), 'regEspTrib')),
            $this->imunidade($this->txt($trib, 'tpImunidade')),
            $this->suspensao($this->txt($susp, 'tpSusp')),
            $this->txt($susp, 'nProcesso'),
        ];
        $linhaBm = [
            $this->txt($bm, 'nBM'),
            $this->brl($this->txt($valoresNfse, 'vCalcBM')),
            $this->brl($this->txt($valoresNfse, 'vCalcDR')),
            $this->brl($this->txt($this->filho($valoresDps, 'vDescCondIncond'), 'vDescIncond')),
        ];

        $html = '<table class="bloco"><tr>'
            .'<td class="cinza titulo" style="width:5.1cm">TRIBUTAÇÃO MUNICIPAL (ISSQN)</td>'
            .$this->campo('Tipo de Tributação do ISSQN', $this->tributacaoIssqn($this->txt($trib, 'tribISSQN')))
            .$this->campo('Município / Sigla UF / País de Incidência do ISSQN', $this->txt($this->inf, 'xLocIncid') ?? $this->txt($trib, 'cPaisResult'), 2)
            .'</tr>';

        // Linhas "**" do Anexo I: suprimidas quando nenhum dos campos tem valor no XML.
        if (array_filter($linhaRegime, fn ($v) => $v !== null) !== []) {
            $html .= '<tr>'
                .$this->campo('Regime Especial de Tributação do ISSQN', $linhaRegime[0])
                .$this->campo('Tipo de Imunidade do ISSQN', $linhaRegime[1])
                .$this->campo('Suspensão da Exigibilidade do ISSQN', $linhaRegime[2])
                .$this->campo('Número Processo Suspensão', $linhaRegime[3])
                .'</tr>';
        }

        if (array_filter($linhaBm, fn ($v) => $v !== null) !== []) {
            $html .= '<tr>'
                .$this->campo('Benefício Municipal', $linhaBm[0])
                .$this->campo('Cálculo do BM', $linhaBm[1])
                .$this->campo('Total Deduções/Reduções', $linhaBm[2])
                .$this->campo('Desconto Incondicionado', $linhaBm[3])
                .'</tr>';
        }

        return $html.'<tr>'
            .$this->campo('BC ISSQN', $this->brl($this->txt($valoresNfse, 'vBC')))
            .$this->campo('Alíquota Aplicada', $this->pct($this->txt($valoresNfse, 'pAliqAplic')))
            .$this->campo('Retenção do ISSQN', $this->retencaoIssqn($this->txt($trib, 'tpRetISSQN')))
            .$this->campo('ISSQN Apurado', $this->brl($this->txt($valoresNfse, 'vISSQN')))
            .'</tr></table>';
    }

    private function tributacaoFederal(): string
    {
        $fed = $this->filho($this->filho($this->filho($this->dps, 'valores'), 'trib'), 'tribFed');
        $pc = $this->filho($fed, 'piscofins');

        [$pisRetido, $cofinsRetido, $csllRetido] = $this->retencoesPisCofins($this->txt($pc, 'tpRetPisCofins'));

        $vPis = $this->num($this->txt($pc, 'vPis'));
        $vCofins = $this->num($this->txt($pc, 'vCofins'));
        $vCsll = $this->num($this->txt($fed, 'vRetCSLL'));

        $retidas = ($pisRetido ? $vPis : 0) + ($cofinsRetido ? $vCofins : 0) + ($csllRetido ? $vCsll : 0);
        $descricao = implode(', ', array_keys(array_filter(['PIS' => $pisRetido && $vPis > 0, 'COFINS' => $cofinsRetido && $vCofins > 0, 'CSLL' => $csllRetido && $vCsll > 0])));

        $html = '<table class="bloco"><tr>'
            .'<td class="cinza titulo" style="width:5.1cm">TRIBUTAÇÃO FEDERAL (EXCETO CBS)</td>'
            .$this->campo('IRRF', $this->brl($this->txt($fed, 'vRetIRRF')))
            .$this->campo('Contribuição Previdenciária - Retida', $this->brl($this->txt($fed, 'vRetCP')))
            .$this->campo('Contribuições Sociais - Retidas', $retidas > 0 ? $this->brl((string) $retidas) : null)
            .'</tr>';

        // Linha "***": só para competência até o fim de 2026.
        if (substr((string) $this->txt($this->dps, 'dCompet'), 0, 4) <= '2026') {
            $html .= '<tr>'
                .$this->campo('PIS - Débito Apuração Própria', ! $pisRetido && $vPis > 0 ? $this->brl((string) $vPis) : null)
                .$this->campo('COFINS - Débito Apuração Própria', ! $cofinsRetido && $vCofins > 0 ? $this->brl((string) $vCofins) : null)
                .$this->campo('Descrição Contrib. Sociais - Retidas', $descricao !== '' ? $descricao : null, 2)
                .'</tr>';
        }

        return $html.'</table>';
    }

    private function tributacaoIbsCbs(): string
    {
        $nfse = $this->filho($this->inf, 'IBSCBS');
        $dps = $this->filho($this->dps, 'IBSCBS');
        $valores = $this->filho($nfse, 'valores');
        $uf = $this->filho($valores, 'uf');
        $mun = $this->filho($valores, 'mun');
        $fed = $this->filho($valores, 'fed');
        $tot = $this->filho($nfse, 'totCIBS');
        $gIbs = $this->filho($tot, 'gIBS');
        $gCbs = $this->filho($tot, 'gCBS');
        $trib = $this->filho($this->filho($dps, 'valores'), 'trib');

        $cst = $this->juntar($this->txt($trib, 'CST'), $this->txt($trib, 'cClassTrib'), ' / ');
        $incidencia = $this->juntar(
            $this->txt($dps, 'cIndOp'),
            $this->juntar($this->txt($nfse, 'cLocalidadeIncid'), $this->txt($nfse, 'xLocalidadeIncid'), ' / '),
            ' / '
        );

        return '<table class="bloco"><tr>'
            .'<td class="cinza titulo" style="width:5.1cm">TRIBUTAÇÃO IBS / CBS</td>'
            .$this->campo('CST / cClassTrib', $cst)
            .$this->campo('Indicador de Operação / Código IBGE Incidência / Município Incidência / Sigla UF', $incidencia, 2)
            .'</tr><tr>'
            .$this->campo('Exclusões e Reduções da Base de Cálculo', $this->brl($this->txt($valores, 'vCalcReeRepRes')))
            .$this->campo('Base de Cálculo Após Exclusões e Reduções', $this->brl($this->txt($valores, 'vBC')))
            .$this->campo('Red. Alíquota IBS / Red. Alíquota CBS', $this->juntar($this->juntar($this->pct($this->txt($uf, 'pRedAliqUF')), $this->pct($this->txt($mun, 'pRedAliqMun')), ' / '), $this->pct($this->txt($fed, 'pRedAliqCBS')), ' / '))
            .$this->campo('Alíquota - IBS UF / IBS Mun', $this->juntar($this->pct($this->txt($uf, 'pIBSUF')), $this->pct($this->txt($mun, 'pIBSMun')), ' / '))
            .'</tr><tr>'
            .$this->campo('Alíq. Efetiva Municipal - IBS', $this->pct($this->txt($mun, 'pAliqEfetMun')))
            .$this->campo('Valor Apurado Municipal - IBS', $this->brl($this->txt($this->filho($gIbs, 'gIBSMunTot'), 'vIBSMun')))
            .$this->campo('Alíq. Efetiva Estadual - IBS', $this->pct($this->txt($uf, 'pAliqEfetUF')))
            .$this->campo('Valor Apurado Estadual - IBS', $this->brl($this->txt($this->filho($gIbs, 'gIBSUFTot'), 'vIBSUF')))
            .'</tr><tr>'
            .$this->campo('Valor Total Apurado - IBS', $this->brl($this->txt($gIbs, 'vIBSTot')))
            .$this->campo('Alíquota - CBS', $this->pct($this->txt($fed, 'pCBS')))
            .$this->campo('Alíquota Efetiva - CBS', $this->pct($this->txt($fed, 'pAliqEfetCBS')))
            .$this->campo('Valor Total Apurado - CBS', $this->brl($this->txt($gCbs, 'vCBS')))
            .'</tr></table>';
    }

    private function valorTotal(): string
    {
        $valoresDps = $this->filho($this->dps, 'valores');
        $valoresNfse = $this->filho($this->inf, 'valores');
        $desc = $this->filho($valoresDps, 'vDescCondIncond');
        $tot = $this->filho($this->filho($this->inf, 'IBSCBS'), 'totCIBS');

        $ibs = $this->txt($this->filho($tot, 'gIBS'), 'vIBSTot');
        $cbs = $this->txt($this->filho($tot, 'gCBS'), 'vCBS');
        $totalIbsCbs = $ibs !== null || $cbs !== null ? (string) ($this->num($ibs) + $this->num($cbs)) : null;
        $liquido = $this->txt($valoresNfse, 'vLiq');
        $liquidoComIbsCbs = $this->txt($tot, 'vTotNF') ?? $liquido;

        return '<table class="bloco"><tr>'
            .'<td class="cinza titulo" style="width:5.1cm">VALOR TOTAL DA NFS-E</td>'
            .$this->campoId('Valor da Operação / Serviço', $this->brl($this->txt($this->filho($valoresDps, 'vServPrest'), 'vServ')))
            .$this->campo('Desconto Incondicionado', $this->brl($this->txt($desc, 'vDescIncond')))
            .$this->campo('Desconto Condicionado', $this->brl($this->txt($desc, 'vDescCond')))
            .'</tr><tr>'
            .$this->campo('Total das Retenções (ISSQN / Federais)', $this->brl($this->txt($valoresNfse, 'vTotalRet')))
            .$this->campoId('Valor Líquido da NFS-e', $this->brl($liquido))
            .$this->campo('Total do IBS/CBS', $this->brl($totalIbsCbs))
            .$this->campoId('Valor Líquido da NFS-e + IBS/CBS', $this->brl($liquidoComIbsCbs), 'cinza')
            .'</tr></table>';
    }

    private function informacoesComplementares(): string
    {
        $linhas = array_filter([
            $this->txt($this->filho($this->filho($this->dps, 'serv'), 'infoCompl'), 'xInfComp'),
            $this->txt($this->inf, 'xOutInf'),
            $this->totaisAproximados(),
        ]);

        $texto = $this->caber(implode("\n", $linhas), $this->alturasLivres()[1]);

        return '<table class="bloco"><tr><td>'
            .'<span class="titulo">INFORMAÇÕES COMPLEMENTARES</span>'
            .'<div class="val info">'.nl2br($this->e($texto)).'</div>'
            .'</td></tr></table>';
    }

    private function blocoCanhoto(string $chave): string
    {
        return '<table class="bloco canhoto"><tr>'
            .'<td style="width:5.1cm"><span class="lbl-id">Data Cientificação:</span></td>'
            .'<td style="width:5.1cm"><span class="lbl-id">Identificação e Assinatura</span></td>'
            .'<td colspan="2"><span class="lbl-id">Nº NFS-e / Chave NFS-e</span><span class="val">'
            .$this->e(($this->txt($this->inf, 'nNFSe') ?? '').' / '.$chave).'</span></td>'
            .'</tr></table>';
    }

    /**
     * Alturas (cm) da "Descrição do Serviço" e das "Informações Complementares".
     * A NT manda que o espaço dos blocos suprimidos vá para essas duas caixas
     * (itens 2.3.1 a 2.3.3) e que tudo caiba numa página: parte do espaço livre
     * de uma nota sem destinatário, intermediário, linhas opcionais nem canhoto,
     * e desconta o que cada um desses ocupa quando aparece.
     *
     * @return array{0: float, 1: float}
     */
    private function alturasLivres(): array
    {
        $livre = 8.8;

        $trib = $this->filho($this->filho($this->filho($this->dps, 'valores'), 'trib'), 'tribMun');
        $susp = $this->filho($trib, 'exigSusp');
        $valoresNfse = $this->filho($this->inf, 'valores');

        if ($this->filho($this->dps, 'toma') === null) {
            $livre += 1.7;
        }
        if ($this->filho($this->dps, 'interm') !== null) {
            $livre -= 1.7;
        }
        if ($this->filho($this->filho($this->dps, 'IBSCBS'), 'dest') !== null) {
            $livre -= 1.7;
        }
        if ($this->regimeEspecial($this->txt($this->filho($this->filho($this->dps, 'prest'), 'regTrib'), 'regEspTrib')) !== null
            || $this->txt($trib, 'tpImunidade') !== null || $susp !== null) {
            $livre -= 0.6;
        }
        if ($this->filho($trib, 'BM') !== null || $this->txt($valoresNfse, 'vCalcDR') !== null
            || $this->txt($this->filho($this->filho($this->dps, 'valores'), 'vDescCondIncond'), 'vDescIncond') !== null) {
            $livre -= 0.6;
        }
        if ($this->canhoto) {
            $livre -= 1.2;
        }

        $livre = max($livre, 3.0);

        return [round($livre * 0.55, 2), round($livre * 0.45, 2)];
    }

    // ------------------------------------------------------------- traduções

    private function situacao(): string
    {
        return match ($this->txt($this->inf, 'cStat')) {
            '100' => 'NFS-e Gerada',
            '102' => 'NFS-e de Decisão Judicial',
            '103' => 'NFS-e Avulsa',
            '107' => 'NFS-e MEI',
            default => '-',
        };
    }

    private function simplesNacional(?string $codigo): ?string
    {
        return match ($codigo) {
            '1' => 'Não Optante',
            '2' => 'Optante - Microempreendedor Individual (MEI)',
            '3' => 'Optante - Microempresa ou Empresa de Pequeno Porte (ME/EPP)',
            default => null,
        };
    }

    private function apuracaoSimples(?string $codigo): ?string
    {
        return match ($codigo) {
            '1' => 'Regime de apuração dos tributos federais e municipal pelo SN',
            '2' => 'Regime de apuração dos tributos federais pelo SN e o ISSQN pela NFS-e conforme legislação municipal',
            '3' => 'Regime de apuração dos tributos federais e municipal pela NFS-e conforme legislações federal e municipal',
            default => null,
        };
    }

    private function regimeEspecial(?string $codigo): ?string
    {
        return match ($codigo) {
            '1' => 'Ato Cooperado (Cooperativa)',
            '2' => 'Estimativa',
            '3' => 'Microempresa Municipal',
            '4' => 'Notário ou Registrador',
            '5' => 'Profissional Autônomo',
            '6' => 'Sociedade de Profissionais',
            '9' => 'Outros',
            default => null, // 0 = Nenhum: a linha "**" pode sumir.
        };
    }

    private function tributacaoIssqn(?string $codigo): ?string
    {
        return match ($codigo) {
            '1' => 'Operação Tributável',
            '2' => 'Imunidade',
            '3' => 'Exportação de Serviço',
            '4' => 'Não Incidência',
            default => null,
        };
    }

    private function retencaoIssqn(?string $codigo): ?string
    {
        return match ($codigo) {
            '1' => 'Não Retido',
            '2' => 'Retido pelo Tomador',
            '3' => 'Retido pelo Intermediário',
            default => null,
        };
    }

    private function imunidade(?string $codigo): ?string
    {
        return match ($codigo) {
            '0' => 'Imunidade (tipo não informado na nota de origem)',
            '1' => 'Patrimônio, renda ou serviços, uns dos outros',
            '2' => 'Templos de qualquer culto',
            '3' => 'Partidos políticos, entidades sindicais, educação e assistência social',
            '4' => 'Livros, jornais, periódicos e o papel destinado a sua impressão',
            '5' => 'Fonogramas e videofonogramas musicais produzidos no Brasil',
            default => null,
        };
    }

    private function suspensao(?string $codigo): ?string
    {
        return match ($codigo) {
            '1' => 'Exigibilidade Suspensa por Decisão Judicial',
            '2' => 'Exigibilidade Suspensa por Processo Administrativo',
            default => null,
        };
    }

    /**
     * Quais contribuições estão retidas, pelo tpRetPisCofins.
     *
     * @return array{0: bool, 1: bool, 2: bool} [PIS, COFINS, CSLL]
     */
    private function retencoesPisCofins(?string $codigo): array
    {
        return match ($codigo) {
            '1' => [true, true, false],
            '3' => [true, true, true],
            '4' => [true, true, false],
            '5' => [true, false, false],
            '6' => [false, true, false],
            '7' => [false, true, true],
            '8' => [false, false, true],
            '9' => [true, false, true],
            // 0 e 2: nada retido. Sem o grupo, a CSLL informada em vRetCSLL é retenção.
            null => [false, false, true],
            default => [false, false, false],
        };
    }

    private function totaisAproximados(): ?string
    {
        $tot = $this->filho($this->filho($this->filho($this->dps, 'valores'), 'trib'), 'totTrib');

        if ($tot === null) {
            return null;
        }

        if (($v = $this->filho($tot, 'vTotTrib')) !== null) {
            return 'Totais Aproximados dos Tributos cfe. Lei nº12.741/2012: Federais: '.$this->brl($this->txt($v, 'vTotTribFed'))
                .'; Estaduais: '.$this->brl($this->txt($v, 'vTotTribEst')).'; Municipais: '.$this->brl($this->txt($v, 'vTotTribMun')).';';
        }

        if (($p = $this->filho($tot, 'pTotTrib')) !== null) {
            return 'Totais Aproximados dos Tributos cfe. Lei nº12.741/2012: Federais: '.$this->pct($this->txt($p, 'pTotTribFed'))
                .'; Estaduais: '.$this->pct($this->txt($p, 'pTotTribEst')).'; Municipais: '.$this->pct($this->txt($p, 'pTotTribMun')).';';
        }

        if (($sn = $this->txt($tot, 'pTotTribSN')) !== null) {
            return 'Totais Aproximados dos Tributos cfe. Lei nº12.741/2012: '.$this->pct($sn).' (Simples Nacional);';
        }

        return null;
    }

    // --------------------------------------------------------------- helpers

    private function chave(): string
    {
        return substr(NacionalXml::digitos($this->inf?->getAttribute('Id')), 0, 50);
    }

    private function filho(?DOMElement $pai, string $nome): ?DOMElement
    {
        if ($pai === null) {
            return null;
        }

        foreach ($pai->childNodes as $no) {
            if ($no instanceof DOMElement && $no->localName === $nome) {
                return $no;
            }
        }

        return null;
    }

    /** Texto do primeiro descendente com o nome, ou null. */
    private function txt(?DOMElement $pai, string $nome): ?string
    {
        return $pai === null ? null : NacionalXml::texto($pai, $nome);
    }

    private function campo(string $label, ?string $valor, int $colspan = 1, string $classe = ''): string
    {
        $span = $colspan > 1 ? ' colspan="'.$colspan.'"' : '';
        $cls = $classe !== '' ? ' class="'.$classe.'"' : '';

        return '<td'.$span.$cls.'><span class="lbl">'.$this->e($label).'</span><span class="val">'.$this->e($valor ?? '-').'</span></td>';
    }

    private function campoId(string $label, ?string $valor, string $classe = ''): string
    {
        $cls = $classe !== '' ? ' class="'.$classe.'"' : '';

        return '<td'.$cls.'>'.$this->rotuloId($label).'<span class="val">'.$this->e($valor ?? '-').'</span></td>';
    }

    private function rotuloId(string $label): string
    {
        return '<span class="lbl-id">'.$this->e($label).'</span>';
    }

    private function documento(?DOMElement $p): ?string
    {
        if (($cnpj = $this->txt($p, 'CNPJ')) !== null && strlen($cnpj) === 14) {
            return substr($cnpj, 0, 2).'.'.substr($cnpj, 2, 3).'.'.substr($cnpj, 5, 3).'/'.substr($cnpj, 8, 4).'-'.substr($cnpj, 12, 2);
        }

        if (($cpf = $this->txt($p, 'CPF')) !== null && strlen($cpf) === 11) {
            return substr($cpf, 0, 3).'.'.substr($cpf, 3, 3).'.'.substr($cpf, 6, 3).'-'.substr($cpf, 9, 2);
        }

        return $this->txt($p, 'NIF');
    }

    private function endereco(?DOMElement $end): ?string
    {
        if ($end === null) {
            return null;
        }

        $partes = array_filter([
            $this->juntar($this->txt($end, 'xLgr'), $this->txt($end, 'nro'), ', '),
            $this->txt($end, 'xCpl'),
            $this->txt($end, 'xBairro'),
        ]);

        return $partes === [] ? null : implode(', ', $partes);
    }

    private function nomeMunicipio(?string $codigo): ?string
    {
        if ($codigo === null || ! is_callable($this->municipio)) {
            return null;
        }

        return ($this->municipio)($codigo);
    }

    private function codigoTributacao(?string $codigo): ?string
    {
        return $codigo !== null && strlen($codigo) === 6
            ? substr($codigo, 0, 2).'.'.substr($codigo, 2, 2).'.'.substr($codigo, 4, 2)
            : $codigo;
    }

    private function nbs(?string $codigo): ?string
    {
        return $codigo !== null && strlen($codigo) === 9
            ? substr($codigo, 0, 1).'.'.substr($codigo, 1, 4).'.'.substr($codigo, 5, 2).'.'.substr($codigo, 7, 2)
            : $codigo;
    }

    private function cep(?string $cep): ?string
    {
        return $cep !== null && strlen($cep) === 8 ? substr($cep, 0, 5).'-'.substr($cep, 5) : $cep;
    }

    private function telefone(?string $fone): ?string
    {
        if ($fone === null) {
            return null;
        }

        $d = NacionalXml::digitos($fone);

        return match (strlen($d)) {
            10 => '('.substr($d, 0, 2).') '.substr($d, 2, 4).'-'.substr($d, 6),
            11 => '('.substr($d, 0, 2).') '.substr($d, 2, 5).'-'.substr($d, 7),
            default => $fone,
        };
    }

    private function data(?string $iso): ?string
    {
        return $iso !== null && preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $iso, $m) ? "{$m[3]}/{$m[2]}/{$m[1]}" : null;
    }

    private function dataHora(?string $iso): ?string
    {
        return $iso !== null && preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}:\d{2}:\d{2})/', $iso, $m)
            ? "{$m[3]}/{$m[2]}/{$m[1]} {$m[4]}"
            : null;
    }

    private function num(?string $valor): float
    {
        return $valor === null ? 0.0 : (float) $valor;
    }

    private function brl(?string $valor): ?string
    {
        return $valor === null ? null : 'R$ '.number_format((float) $valor, 2, ',', '.');
    }

    private function pct(?string $valor): ?string
    {
        return $valor === null ? null : number_format((float) $valor, 2, ',', '.').'%';
    }

    private function juntar(?string $a, ?string $b, string $sep = ' / '): ?string
    {
        $partes = array_values(array_filter([$a, $b], fn ($v) => $v !== null && $v !== ''));

        return $partes === [] ? null : implode($sep, $partes);
    }

    private function sufixoUf(string $uf): string
    {
        return $uf !== '' ? ' - '.$uf : '';
    }

    /**
     * Corta o texto para caber numa caixa de altura fixa, contando as quebras de
     * linha que ele já traz. O dompdf não corta pela altura (a caixa cresceria e
     * a nota iria para uma segunda página), e a NT admite reticências (2.1).
     */
    private function caber(string $texto, float $alturaCm): string
    {
        $linhasDisponiveis = max(1, (int) floor($alturaCm * self::LINHAS_POR_CM));
        $saida = [];
        $usadas = 0;

        foreach (preg_split('/\R/', $texto) ?: [] as $linha) {
            $ocupa = max(1, (int) ceil(mb_strlen($linha) / self::CARACTERES_POR_LINHA));

            if ($usadas + $ocupa > $linhasDisponiveis) {
                $resto = ($linhasDisponiveis - $usadas) * self::CARACTERES_POR_LINHA - 6;

                if ($resto > 0) {
                    $saida[] = rtrim(mb_substr($linha, 0, $resto)).' (...)';
                } elseif ($saida !== []) {
                    $saida[count($saida) - 1] .= ' (...)';
                }

                return implode("\n", $saida);
            }

            $saida[] = $linha;
            $usadas += $ocupa;
        }

        return implode("\n", $saida);
    }

    private function qrCode(string $conteudo): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(220, 1), new SvgImageBackEnd()));

        return 'data:image/svg+xml;base64,'.base64_encode($writer->writeString($conteudo));
    }

    private function logoNfse(): string
    {
        $arquivo = __DIR__.'/../../resources/danfse/logo-nfse-horizontal.png';

        return 'data:image/png;base64,'.base64_encode((string) file_get_contents($arquivo));
    }

    private function e(string $valor): string
    {
        return htmlspecialchars($valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
