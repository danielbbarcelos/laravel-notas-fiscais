<?php

declare(strict_types=1);

namespace DanielBBarcelos\NotasFiscais\Drivers\Nacional\Mappers;

use DanielBBarcelos\NotasFiscais\Data\Nfse\ItemServico;
use DanielBBarcelos\NotasFiscais\Data\Nfse\NotaEmitida;
use DanielBBarcelos\NotasFiscais\Data\Nfse\NotaServico;
use DanielBBarcelos\NotasFiscais\Data\Nfse\Prestador;
use DanielBBarcelos\NotasFiscais\Data\Nfse\Tomador;
use DanielBBarcelos\NotasFiscais\Data\Shared\Valor;
use DanielBBarcelos\NotasFiscais\Drivers\Nacional\NacionalXml;
use DanielBBarcelos\NotasFiscais\Drivers\Nacional\RegimeTributario;
use DanielBBarcelos\NotasFiscais\Enums\SituacaoNota;
use DanielBBarcelos\NotasFiscais\Enums\SituacaoTributaria;
use DanielBBarcelos\NotasFiscais\Enums\TipoTomador;
use DateTimeImmutable;
use DateTimeZone;
use DOMDocument;
use DOMElement;
use InvalidArgumentException;

/**
 * De-para entre o DTO canônico de emissão e a DPS (Declaração de Prestação de
 * Serviço) do leiaute nacional v1.01, e da NFS-e gerada de volta para
 * NotaEmitida. Único lugar que conhece as tags do leiaute.
 *
 * Diferenças para o ABRASF que pesam aqui:
 *  - quem numera é o emitente (série + nDPS), e o Id da DPS é derivado deles;
 *  - o código do serviço é o cTribNac de 6 dígitos (item, subitem e desdobro);
 *  - nome e IM do prestador não vão quando o prestador é o emitente (E0121 e
 *    E0120), porque a Sefin já os conhece pelo cadastro nacional;
 *  - a alíquota (pAliq) só pode ser informada em algumas combinações de regime
 *    e convênio, ver aliquotaInformada().
 */
class DpsMapper
{
    public const VERSAO = '1.01';

    public function __construct(
        protected RegimeTributario $regime,
        protected ?string $codigoNbsPadrao = null,
        protected string $versaoAplicativo = 'laravel-nf 1.3',
        protected bool $informarInscricaoMunicipal = false,
    ) {
    }

    /** Canônico -> DPS sem assinatura (o documento, para o gateway assinar). */
    public function paraApi(NotaServico $dados, ?Prestador $prestadorPadrao, int $ambiente): DOMDocument
    {
        $prestador = $dados->prestador ?? $prestadorPadrao;

        if ($prestador === null) {
            throw new InvalidArgumentException(
                'Prestador não informado: defina-o na NotaServico ou em notas-fiscais.drivers.{provedor}.'
            );
        }

        if ($dados->itens === []) {
            throw new InvalidArgumentException('NotaServico sem itens: a DPS exige um serviço.');
        }

        if ($dados->numeroDps === null || $dados->numeroDps < 1) {
            throw new InvalidArgumentException(
                'NotaServico sem numeroDps: no padrão nacional o emitente numera a DPS (1 a 15 dígitos, único por série).'
            );
        }

        $item = $dados->itens[0];
        $municipioEmissor = NacionalXml::digitos($prestador->codigoMunicipio);

        if (strlen($municipioEmissor) !== 7) {
            throw new InvalidArgumentException(
                "Código do município do prestador inválido: \"{$prestador->codigoMunicipio}\". O padrão nacional usa o IBGE de 7 dígitos."
            );
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = false;

        $dps = $dom->createElementNS(NacionalXml::NS, 'DPS');
        $dps->setAttribute('versao', self::VERSAO);
        $dom->appendChild($dps);

        $inf = $this->el($dom, $dps, 'infDPS');
        $inf->setAttribute('Id', $this->idDps($municipioEmissor, $prestador, $dados->serie, $dados->numeroDps));

        $this->add($dom, $inf, 'tpAmb', (string) $ambiente);
        $this->add($dom, $inf, 'dhEmi', $this->dataHoraEmissao());
        $this->add($dom, $inf, 'verAplic', mb_substr($this->versaoAplicativo, 0, 20));
        $this->add($dom, $inf, 'serie', (string) $dados->serie);
        $this->add($dom, $inf, 'nDPS', (string) $dados->numeroDps);
        $this->add($dom, $inf, 'dCompet', $this->competencia($dados));
        $this->add($dom, $inf, 'tpEmit', '1');
        $this->add($dom, $inf, 'cLocEmi', $municipioEmissor);

        $this->prestador($dom, $inf, $prestador);
        $this->tomador($dom, $inf, $dados->tomador);
        $this->servico($dom, $inf, $dados, $item, $municipioEmissor);
        $this->valores($dom, $inf, $dados, $item);

        return $dom;
    }

    /**
     * Id da DPS: "DPS" + município emissor (7) + tipo de inscrição (1 = CPF,
     * 2 = CNPJ) + inscrição (14, CPF com zeros à esquerda) + série (5) + nDPS (15).
     */
    public function idDps(string $municipioEmissor, Prestador $prestador, int $serie, int $numeroDps): string
    {
        $documento = $prestador->cpfCnpjLimpo();

        return 'DPS'
            .$municipioEmissor
            .(strlen($documento) === 11 ? '1' : '2')
            .str_pad($documento, 14, '0', STR_PAD_LEFT)
            .str_pad((string) $serie, 5, '0', STR_PAD_LEFT)
            .str_pad((string) $numeroDps, 15, '0', STR_PAD_LEFT);
    }

    /**
     * Resposta da Sefin (JSON + NFS-e em XML) -> DTO canônico. A chave de acesso
     * de 50 dígitos ocupa o codigoVerificacao: é ela que identifica a nota em
     * consulta, cancelamento e na consulta pública.
     *
     * @param  array<string, mixed>  $json
     */
    public function paraDominio(array $json, ?string $xmlNfse, ?int $serie = null): NotaEmitida
    {
        $dom = $xmlNfse !== null ? NacionalXml::parse($xmlNfse) : null;

        $chave = isset($json['chaveAcesso']) && is_string($json['chaveAcesso']) ? $json['chaveAcesso'] : null;

        if ($chave === null && $dom !== null) {
            $id = NacionalXml::atributo($dom, 'infNFSe', 'Id');
            $chave = $id !== null ? substr(NacionalXml::digitos($id), 0, 50) : null;
        }

        $numero = $dom !== null ? NacionalXml::texto($dom, 'nNFSe') : null;
        $processamento = ($dom !== null ? NacionalXml::texto($dom, 'dhProc') : null)
            ?? (is_string($json['dataHoraProcessamento'] ?? null) ? $json['dataHoraProcessamento'] : null);

        [$data, $hora] = $this->dataHora($processamento);

        return new NotaEmitida(
            numero: $numero !== null ? (int) $numero : null,
            serie: $serie ?? ($dom !== null && ($s = NacionalXml::texto($dom, 'serie')) !== null ? (int) $s : null),
            data: $data,
            hora: $hora,
            situacao: $numero !== null ? SituacaoNota::Emitida : null,
            codigoVerificacao: $chave,
            link: $chave !== null ? self::linkConsultaPublica($chave) : null,
            bruto: array_filter([
                'chave_acesso' => $chave,
                'id_dps' => $json['idDps'] ?? $json['idDPS'] ?? null,
                'ambiente' => $json['tipoAmbiente'] ?? null,
                'alertas' => $json['alertas'] ?? null,
                'xml_nfse' => $xmlNfse,
            ], fn ($v) => $v !== null),
        );
    }

    public static function linkConsultaPublica(string $chave): string
    {
        return 'https://www.nfse.gov.br/ConsultaPublica/?tpc=1&chave='.$chave;
    }

    protected function prestador(DOMDocument $dom, DOMElement $inf, Prestador $prestador): void
    {
        $prest = $this->el($dom, $inf, 'prest');
        $documento = $prestador->cpfCnpjLimpo();
        $this->add($dom, $prest, strlen($documento) === 11 ? 'CPF' : 'CNPJ', $documento);

        // E0120: a IM só é aceita quando o município registrou dados complementares
        // no cadastro nacional. Por padrão não vai; quem precisar liga na config.
        if ($this->informarInscricaoMunicipal && $prestador->inscricaoMunicipal !== null && $prestador->inscricaoMunicipal !== '') {
            $this->add($dom, $prest, 'IM', $prestador->inscricaoMunicipal);
        }

        $regTrib = $this->el($dom, $prest, 'regTrib');
        $this->add($dom, $regTrib, 'opSimpNac', (string) $this->regime->opcaoSimples);
        if ($this->regime->opcaoSimples === RegimeTributario::SIMPLES_ME_EPP) {
            $this->add($dom, $regTrib, 'regApTribSN', (string) $this->regime->apuracaoSimples);
        }
        $this->add($dom, $regTrib, 'regEspTrib', (string) $this->regime->regimeEspecial);
    }

    protected function tomador(DOMDocument $dom, DOMElement $inf, Tomador $t): void
    {
        $toma = $this->el($dom, $inf, 'toma');

        if ($t->tipo === TipoTomador::Estrangeiro) {
            $this->add($dom, $toma, 'NIF', NacionalXml::texto2000($t->identificacao, 40));
        } else {
            $this->add($dom, $toma, $t->ehCnpj() ? 'CNPJ' : 'CPF', $t->identificacaoLimpa());
        }

        $this->add($dom, $toma, 'xNome', NacionalXml::texto2000($t->nomeRazaoSocial, 300));

        // O endereço é opcional, mas quando vai precisa estar completo: logradouro,
        // número, bairro, município (IBGE) e CEP. Meio endereço é rejeição certa.
        $e = $t->endereco;
        $municipio = NacionalXml::digitos($e?->codigoMunicipio);
        $cep = $e?->cepLimpo() ?? '';

        if ($e !== null && $t->tipo !== TipoTomador::Estrangeiro
            && $e->logradouro !== null && $e->bairro !== null
            && strlen($municipio) === 7 && strlen($cep) === 8) {
            $end = $this->el($dom, $toma, 'end');
            $endNac = $this->el($dom, $end, 'endNac');
            $this->add($dom, $endNac, 'cMun', $municipio);
            $this->add($dom, $endNac, 'CEP', $cep);
            $this->add($dom, $end, 'xLgr', NacionalXml::texto2000($e->logradouro, 255));
            $this->add($dom, $end, 'nro', NacionalXml::texto2000($e->numero !== null && $e->numero !== '' ? $e->numero : 'S/N', 60));
            if ($e->complemento !== null && trim($e->complemento) !== '') {
                $this->add($dom, $end, 'xCpl', NacionalXml::texto2000($e->complemento, 156));
            }
            $this->add($dom, $end, 'xBairro', NacionalXml::texto2000($e->bairro, 60));
        }

        if ($t->email !== null && $t->email !== '') {
            $this->add($dom, $toma, 'email', mb_substr($t->email, 0, 80));
        }
    }

    protected function servico(DOMDocument $dom, DOMElement $inf, NotaServico $dados, ItemServico $item, string $municipioEmissor): void
    {
        $serv = $this->el($dom, $inf, 'serv');

        $locPrest = $this->el($dom, $serv, 'locPrest');
        $local = NacionalXml::digitos($item->codigoLocalPrestacao);
        $this->add($dom, $locPrest, 'cLocPrestacao', strlen($local) === 7 ? $local : $municipioEmissor);

        $cServ = $this->el($dom, $serv, 'cServ');
        $this->add($dom, $cServ, 'cTribNac', $this->codigoTributacaoNacional($item->codigoItemListaServico));
        $this->add($dom, $cServ, 'xDescServ', NacionalXml::texto2000($item->descritivo));
        if (($nbs = $this->nbs($item)) !== null) {
            $this->add($dom, $cServ, 'cNBS', $nbs);
        }

        if ($dados->observacao !== null && trim($dados->observacao) !== '') {
            $infoCompl = $this->el($dom, $serv, 'infoCompl');
            $this->add($dom, $infoCompl, 'xInfComp', NacionalXml::texto2000($dados->observacao));
        }
    }

    protected function valores(DOMDocument $dom, DOMElement $inf, NotaServico $dados, ItemServico $item): void
    {
        $valores = $this->el($dom, $inf, 'valores');

        $vServPrest = $this->el($dom, $valores, 'vServPrest');
        $this->add($dom, $vServPrest, 'vServ', $item->valorTributavel->paraApi());

        if ($this->positivo($dados->valorDesconto)) {
            $desc = $this->el($dom, $valores, 'vDescCondIncond');
            $this->add($dom, $desc, 'vDescIncond', $dados->valorDesconto->paraApi());
        }

        $trib = $this->el($dom, $valores, 'trib');

        $tribMun = $this->el($dom, $trib, 'tribMun');
        $this->add($dom, $tribMun, 'tribISSQN', $this->tributacaoIssqn($item->situacaoTributaria));
        $retido = $item->issRetido === true;
        $this->add($dom, $tribMun, 'tpRetISSQN', $retido ? '2' : '1');
        if ($this->aliquotaInformada($retido)) {
            $this->add($dom, $tribMun, 'pAliq', $this->dec($item->aliquota));
        }

        $this->tributosFederais($dom, $trib, $dados, $item->valorTributavel);

        $this->totalTributos($dom, $trib);
    }

    /** Retenções federais: INSS (vRetCP), IR, CSLL e o grupo PIS/COFINS. */
    protected function tributosFederais(DOMDocument $dom, DOMElement $trib, NotaServico $dados, Valor $base): void
    {
        $pis = $this->positivo($dados->valorPis);
        $cofins = $this->positivo($dados->valorCofins);
        $csll = $this->positivo($dados->valorContribuicaoSocial);
        $inss = $this->positivo($dados->valorInss);
        $ir = $this->positivo($dados->valorIr);

        if (! ($pis || $cofins || $csll || $inss || $ir)) {
            return;
        }

        $tribFed = $this->el($dom, $trib, 'tribFed');

        if ($pis || $cofins) {
            $piscofins = $this->el($dom, $tribFed, 'piscofins');
            $this->add($dom, $piscofins, 'CST', $this->regime->cstPisCofins);
            $this->add($dom, $piscofins, 'vBCPisCofins', $base->paraApi());
            if ($pis) {
                $this->add($dom, $piscofins, 'vPis', $dados->valorPis->paraApi());
            }
            if ($cofins) {
                $this->add($dom, $piscofins, 'vCofins', $dados->valorCofins->paraApi());
            }
            $this->add($dom, $piscofins, 'tpRetPisCofins', $this->tipoRetencaoPisCofins($pis, $cofins, $csll));
        }

        if ($inss) {
            $this->add($dom, $tribFed, 'vRetCP', $dados->valorInss->paraApi());
        }
        if ($ir) {
            $this->add($dom, $tribFed, 'vRetIRRF', $dados->valorIr->paraApi());
        }
        if ($csll) {
            $this->add($dom, $tribFed, 'vRetCSLL', $dados->valorContribuicaoSocial->paraApi());
        }
    }

    /**
     * Total aproximado de tributos (Lei 12.741). O choice depende do regime:
     * não optante só aceita valor ou percentual; ME/EPP não aceita indTotTrib;
     * MEI não aceita pTotTribSN. Sem percentuais configurados, o não optante
     * vai com 0,00 nos três entes e o MEI com indTotTrib = 0.
     */
    protected function totalTributos(DOMDocument $dom, DOMElement $trib): void
    {
        $totTrib = $this->el($dom, $trib, 'totTrib');

        if ($this->regime->opcaoSimples === RegimeTributario::SIMPLES_ME_EPP && $this->regime->percentualTributosSimples !== null) {
            $this->add($dom, $totTrib, 'pTotTribSN', $this->dec($this->regime->percentualTributosSimples));

            return;
        }

        if ($this->regime->opcaoSimples === RegimeTributario::SIMPLES_MEI && $this->regime->percentuaisTributos === null) {
            $this->add($dom, $totTrib, 'indTotTrib', '0');

            return;
        }

        [$federal, $estadual, $municipal] = $this->regime->percentuaisTributos ?? [0, 0, 0];
        $pTotTrib = $this->el($dom, $totTrib, 'pTotTrib');
        $this->add($dom, $pTotTrib, 'pTotTribFed', $this->dec($federal));
        $this->add($dom, $pTotTrib, 'pTotTribEst', $this->dec($estadual));
        $this->add($dom, $pTotTrib, 'pTotTribMun', $this->dec($municipal));
    }

    /**
     * Quando a alíquota pode (e deve) ir na DPS. Resume as regras do Anexo I:
     * com o município conveniado, a Sefin aplica a alíquota parametrizada e
     * rejeita a informada; sem convênio, ela precisa vir do emitente. A exceção
     * é o ME/EPP que apura ISS pelo Simples, que informa só quando há retenção
     * (a alíquota do Simples vai para o tomador recolher). MEI nunca informa.
     */
    public function aliquotaInformada(bool $retido): bool
    {
        return match ($this->regime->opcaoSimples) {
            RegimeTributario::SIMPLES_MEI => false,
            RegimeTributario::SIMPLES_ME_EPP => $this->regime->apuracaoSimples === 1
                ? $retido
                : ! $this->regime->municipioConveniado,
            default => ! $this->regime->municipioConveniado,
        };
    }

    /**
     * cTribNac: 6 dígitos (item, subitem e desdobro nacional). Aceita "010701",
     * "01.07.01" ou "1.07.01"; rejeita o subitem sem desdobro ("01.07"), que o
     * leiaute não aceita.
     */
    protected function codigoTributacaoNacional(string $codigo): string
    {
        $codigo = trim($codigo);

        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{1,2})$/', $codigo, $m)) {
            return sprintf('%02d%02d%02d', (int) $m[1], (int) $m[2], (int) $m[3]);
        }

        $digitos = NacionalXml::digitos($codigo);

        if (strlen($digitos) !== 6) {
            throw new InvalidArgumentException(
                "Código de tributação nacional inválido: \"{$codigo}\". O padrão nacional exige 6 dígitos (item, subitem e desdobro, ex.: 01.07.01)."
            );
        }

        return $digitos;
    }

    /** NBS de 9 dígitos, do item ou da config; null omite a tag (é opcional no v1.01). */
    protected function nbs(ItemServico $item): ?string
    {
        $bruto = $item->codigoNbs ?? $this->codigoNbsPadrao;

        if ($bruto === null || trim($bruto) === '') {
            return null;
        }

        $digitos = NacionalXml::digitos($bruto);

        if (strlen($digitos) !== 9) {
            throw new InvalidArgumentException(
                "Código NBS inválido: \"{$bruto}\". O padrão nacional exige 9 dígitos (ex.: 1.0105.10.00 ou 101051000)."
            );
        }

        return $digitos;
    }

    protected function tributacaoIssqn(SituacaoTributaria $situacao): string
    {
        return match ($situacao) {
            SituacaoTributaria::Imune => '2',
            SituacaoTributaria::NaoTributada, SituacaoTributaria::NaoTributadaAtoCooperado => '4',
            default => '1',
        };
    }

    protected function tipoRetencaoPisCofins(bool $pis, bool $cofins, bool $csll): string
    {
        return match (true) {
            $pis && $cofins && $csll => '3',
            $pis && $cofins => '4',
            $pis && $csll => '9',
            $cofins && $csll => '7',
            $pis => '5',
            $cofins => '6',
            default => '2',
        };
    }

    /**
     * dhEmi no fuso de Brasília. Um minuto para trás: a Sefin rejeita data de
     * emissão posterior ao processamento, e o relógio do servidor do emitente
     * raramente está alinhado ao dela.
     */
    protected function dataHoraEmissao(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))
            ->modify('-1 minute')
            ->format('Y-m-d\TH:i:sP');
    }

    /** dCompet (AAAA-MM-DD): a competência informada, senão a data do fato gerador. */
    protected function competencia(NotaServico $dados): string
    {
        if ($dados->competencia !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dados->competencia)) {
            return $dados->competencia;
        }

        $data = DateTimeImmutable::createFromFormat('!d/m/Y', $dados->dataFatoGerador);

        if ($data === false) {
            throw new InvalidArgumentException(
                "Data do fato gerador inválida: \"{$dados->dataFatoGerador}\" (esperado dd/mm/aaaa)."
            );
        }

        return $data->format('Y-m-d');
    }

    /**
     * @return array{0: ?string, 1: ?string} [dd/mm/aaaa, HH:MM:SS]
     */
    protected function dataHora(?string $iso): array
    {
        if ($iso === null) {
            return [null, null];
        }

        try {
            $dt = new DateTimeImmutable($iso);
        } catch (\Exception) {
            return [null, null];
        }

        return [$dt->format('d/m/Y'), $dt->format('H:i:s')];
    }

    protected function positivo(?Valor $valor): bool
    {
        return $valor !== null && $valor->centavos > 0;
    }

    protected function el(DOMDocument $dom, DOMElement $pai, string $tag): DOMElement
    {
        $el = $dom->createElementNS(NacionalXml::NS, $tag);
        $pai->appendChild($el);

        return $el;
    }

    protected function add(DOMDocument $dom, DOMElement $pai, string $tag, string $valor): void
    {
        $el = $dom->createElementNS(NacionalXml::NS, $tag);
        $el->appendChild($dom->createTextNode($valor));
        $pai->appendChild($el);
    }

    protected function dec(float|int|string $n): string
    {
        $f = is_string($n) ? (float) str_replace(',', '.', $n) : (float) $n;

        return number_format($f, 2, '.', '');
    }
}
