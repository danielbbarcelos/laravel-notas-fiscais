<?php

declare(strict_types=1);

namespace DanielBBarcelos\NotasFiscais\Drivers\Nacional;

use DanielBBarcelos\NotasFiscais\Contracts\ExportaArquivos;
use DanielBBarcelos\NotasFiscais\Contracts\NfseGateway;
use DanielBBarcelos\NotasFiscais\Data\Nfse\Cancelamento;
use DanielBBarcelos\NotasFiscais\Data\Nfse\NotaEmitida;
use DanielBBarcelos\NotasFiscais\Data\Nfse\NotaServico;
use DanielBBarcelos\NotasFiscais\Data\Nfse\Prestador;
use DanielBBarcelos\NotasFiscais\Drivers\Nacional\Mappers\CancelamentoNacionalMapper;
use DanielBBarcelos\NotasFiscais\Drivers\Nacional\Mappers\DpsMapper;
use DanielBBarcelos\NotasFiscais\Enums\SituacaoNota;
use DanielBBarcelos\NotasFiscais\Exceptions\NotaFiscalApiException;
use DanielBBarcelos\NotasFiscais\Exceptions\NotaFiscalException;

/**
 * NFS-e no padrão nacional (Sefin Nacional / Emissor Nacional, leiaute v1.01).
 *
 * O NotaServico::teste manda a DPS para a produção restrita (tpAmb 2), que é a
 * homologação deste padrão: gera uma NFS-e de verdade, só que sem valor fiscal.
 * Diferente do <EnvioTeste> do ABRASF, que só valida.
 */
class NacionalNfseGateway implements ExportaArquivos, NfseGateway
{
    /** Rejeição de DPS já usada: a NFS-e existe, então é recuperada em vez de falhar. */
    public const DPS_DUPLICADA = 'E0014';

    public function __construct(
        protected NacionalConnector $http,
        protected Certificado $certificado,
        protected DpsMapper $dps,
        protected CancelamentoNacionalMapper $cancelamentos,
        protected int $ambiente = 1,
        protected ?Prestador $prestadorPadrao = null,
    ) {
    }

    public function emitir(NotaServico $dados): NotaEmitida
    {
        $ambiente = $dados->teste ? 2 : $this->ambiente;
        $dom = $this->dps->paraApi($dados, $this->prestadorPadrao, $ambiente);
        $idDps = (string) $dom->getElementsByTagName('infDPS')->item(0)?->getAttribute('Id');
        $xml = AssinaturaXml::assinar($dom, 'infDPS', $this->certificado);

        try {
            $json = $this->http->enviar('/nfse', 'dpsXmlGZipB64', $xml, $ambiente);
        } catch (NotaFiscalApiException $e) {
            // Reenvio da mesma DPS (timeout na primeira tentativa, reprocessamento):
            // a nota já existe na Sefin. Devolvê-la evita emitir de novo com outro
            // número só para descobrir que a primeira tinha passado.
            if ($e->codigo !== self::DPS_DUPLICADA) {
                throw $e;
            }

            $json = $this->recuperarPorDps($idDps, $ambiente);
            $this->conferirRecuperada($dom, $this->xmlNfse($json), $idDps);
        }

        return $this->dps->paraDominio($json, $this->xmlNfse($json), $dados->serie);
    }

    public function cancelar(Cancelamento $dados): NotaEmitida
    {
        $prestador = $dados->prestador ?? $this->prestadorPadrao;
        $chave = CancelamentoNacionalMapper::chave($dados);
        $dom = $this->cancelamentos->paraApi($dados, $prestador, $this->ambiente);
        $xml = AssinaturaXml::assinar($dom, 'infPedReg', $this->certificado);

        $json = $this->http->enviar("/nfse/{$chave}/eventos", 'pedidoRegistroEventoXmlGZipB64', $xml, $this->ambiente);

        $evento = $json['eventoXmlGZipB64'] ?? null;

        // O connector lança em caso de rejeição; aqui o evento já está registrado.
        return new NotaEmitida(
            numero: $dados->numero,
            serie: $dados->serie,
            data: null,
            hora: null,
            situacao: SituacaoNota::Cancelada,
            codigoVerificacao: $chave,
            link: DpsMapper::linkConsultaPublica($chave),
            bruto: array_filter([
                'chave_acesso' => $chave,
                'xml_evento' => is_string($evento) ? NacionalXml::descompactar($evento) : null,
            ], fn ($v) => $v !== null),
        );
    }

    /**
     * O padrão nacional não consulta pelo número da NFS-e: só pela chave de
     * acesso (consultarPorAutenticidade) ou pelo Id da DPS.
     */
    public function consultar(int $numero, int $serie, string $cadastro): NotaEmitida
    {
        throw new NotaFiscalException(
            'Consulta por número não existe no padrão nacional; use consultarPorAutenticidade() com a chave de acesso de 50 dígitos.'
        );
    }

    /** Consulta pela chave de acesso de 50 dígitos (o "código de autenticidade" deste padrão). */
    public function consultarPorAutenticidade(string $codigo): NotaEmitida
    {
        $chave = NacionalXml::digitos($codigo);

        if (strlen($chave) !== 50) {
            throw new NotaFiscalException("Chave de acesso inválida: \"{$codigo}\" (são 50 dígitos).");
        }

        $json = $this->http->consultar("/nfse/{$chave}", $this->ambiente);

        return $this->dps->paraDominio($json, $this->xmlNfse($json));
    }

    /**
     * Situação do convênio do município com o Sistema Nacional (ADN, GET
     * /parametrizacao/{cMun}/convenio). Decide se a alíquota vai na DPS: com
     * convênio ativo a Sefin aplica a parametrizada e rejeita a informada.
     *
     * A resposta é {mensagem, parametrosConvenio: {aderenteAmbienteNacional,
     * aderenteEmissorNacional, ...}}, com os "sim/não" como 1, 0 e -1 (não
     * informado). O JSON original volta em "bruto".
     *
     * @return array{conveniado: ?bool, bruto: array<string, mixed>}
     */
    public function consultarConvenio(string $codigoIbge): array
    {
        $ibge = NacionalXml::digitos($codigoIbge);

        if (strlen($ibge) !== 7) {
            throw new NotaFiscalException("Código IBGE inválido: \"{$codigoIbge}\" (são 7 dígitos).");
        }

        $json = $this->http->consultarParametros("/{$ibge}/convenio", $this->ambiente);

        return ['conveniado' => self::lerConveniado($json), 'bruto' => $json];
    }

    /**
     * Alíquota do município para um código de tributação nacional na competência
     * (ADN, GET /parametrizacao/{cMun}/{código}/{competência}/aliquota). O código
     * vai completo e pontuado, cTribNac mais o complemento municipal "000"
     * (01.07.01.000): o cTribNac puro e os 9 dígitos sem ponto voltam 400. A
     * competência é AAAA-MM-DD, e sem ela vale o dia de hoje.
     *
     * @return array{aliquota: ?float, bruto: array<string, mixed>}
     */
    public function consultarAliquota(string $codigoIbge, string $codigoTributacaoNacional, ?string $competencia = null): array
    {
        $ibge = NacionalXml::digitos($codigoIbge);
        $codigo = NacionalXml::digitos($codigoTributacaoNacional);

        if (strlen($ibge) !== 7 || strlen($codigo) !== 6) {
            throw new NotaFiscalException('Consulta de alíquota exige o IBGE (7 dígitos) e o código de tributação nacional (6 dígitos).');
        }

        $completo = substr($codigo, 0, 2).'.'.substr($codigo, 2, 2).'.'.substr($codigo, 4, 2).'.000';
        $competencia ??= date('Y-m-d');

        $json = $this->http->consultarParametros("/{$ibge}/{$completo}/{$competencia}/aliquota", $this->ambiente);

        return ['aliquota' => self::lerAliquota($json, $completo, $competencia), 'bruto' => $json];
    }

    /**
     * @param  array<mixed>  $json
     */
    public static function lerConveniado(array $json): ?bool
    {
        // aderenteEmissorNacional fica de fora: diz se o município usa o emissor web
        // público, não se o convênio (que decide a alíquota na DPS) está ativo.
        foreach (['convenioAtivo', 'conveniado', 'situacaoConvenio', 'aderenteAmbienteNacional'] as $campo) {
            $valor = self::procurar($json, $campo);

            if ($valor === null) {
                continue;
            }

            // -1 é o "não informado" dos enums do ADN: não decide nada.
            if ((string) $valor === '-1') {
                return null;
            }

            return in_array(strtolower((string) $valor), ['1', 'true', 's', 'sim', 'ativo'], true);
        }

        return null;
    }

    /**
     * A alíquota vigente na competência. A resposta do ADN é {aliquotas: {"01.07.01.000":
     * [{Aliq, DtIni, DtFim}, ...]}}, um histórico por código; sem esse formato, cai na
     * busca pelo nome do campo.
     *
     * @param  array<mixed>  $json
     */
    public static function lerAliquota(array $json, ?string $codigo = null, ?string $competencia = null): ?float
    {
        $mapa = $json['aliquotas'] ?? null;

        if (is_array($mapa) && $mapa !== []) {
            $historico = ($codigo !== null && isset($mapa[$codigo]) && is_array($mapa[$codigo])) ? $mapa[$codigo] : reset($mapa);
            $dia = $competencia ?? date('Y-m-d');

            foreach (is_array($historico) ? $historico : [] as $faixa) {
                if (! is_array($faixa) || ! is_numeric($faixa['Aliq'] ?? null)) {
                    continue;
                }

                $inicio = substr((string) ($faixa['DtIni'] ?? ''), 0, 10);
                $fim = substr((string) ($faixa['DtFim'] ?? ''), 0, 10);

                if (($inicio === '' || $inicio <= $dia) && ($fim === '' || $fim >= $dia)) {
                    return (float) $faixa['Aliq'];
                }
            }
        }

        foreach (['aliquota', 'Aliq', 'aliq', 'pAliq', 'valorAliquota'] as $campo) {
            $valor = self::procurar($json, $campo);

            if (is_numeric($valor)) {
                return (float) $valor;
            }
        }

        return null;
    }

    /**
     * Primeiro valor escalar com a chave informada, em qualquer profundidade
     * (comparação sem diferenciar maiúsculas).
     *
     * @param  array<mixed>  $json
     */
    private static function procurar(array $json, string $chave): mixed
    {
        foreach ($json as $k => $v) {
            if (is_string($k) && strcasecmp($k, $chave) === 0 && is_scalar($v)) {
                return $v;
            }
        }

        foreach ($json as $v) {
            if (is_array($v) && ($achado = self::procurar($v, $chave)) !== null) {
                return $achado;
            }
        }

        return null;
    }

    public function xmlNota(NotaServico $dados, ?NotaEmitida $emitida = null): string
    {
        // A NFS-e oficial (assinada pela Sefin), quando veio na emissão ou na consulta.
        $oficial = $emitida?->bruto['xml_nfse'] ?? null;

        if (is_string($oficial) && $oficial !== '') {
            return $oficial;
        }

        // Sem ela, a DPS que a originou (sem assinatura), para conferência.
        $dom = $this->dps->paraApi($dados, $this->prestadorPadrao, $dados->teste ? 2 : $this->ambiente);
        $dom->formatOutput = true;

        return (string) $dom->saveXML();
    }

    public function txtExportacao(NotaServico $dados, NotaEmitida $emitida): string
    {
        throw new NotaFiscalException(
            'O TXT de exportação é um layout do IPM (NT 65/2020) e não existe no padrão nacional; use xmlNota().'
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function recuperarPorDps(string $idDps, int $ambiente): array
    {
        $dps = $this->http->consultar("/dps/{$idDps}", $ambiente);
        $chave = $dps['chaveAcesso'] ?? null;

        if (! is_string($chave) || $chave === '') {
            throw new NotaFiscalException(
                "A DPS {$idDps} já gerou uma NFS-e, mas a Sefin não informou a chave de acesso."
            );
        }

        return $this->http->consultar("/nfse/{$chave}", $ambiente) + ['chaveAcesso' => $chave, 'idDps' => $idDps];
    }

    /**
     * A NFS-e recuperada pelo Id da DPS precisa ser a desta emissão. Se valor,
     * tomador, código do serviço ou descrição divergem, devolvê-la como se fosse
     * esta seria pior que falhar: ou o número foi usado por outra nota do mesmo
     * emitente e série (outra instalação), ou é a primeira tentativa desta mesma
     * nota, que passou com dados que o reprocessamento mudou. Nos dois casos a
     * mensagem traz a chave da nota que existe, para ela ser consultada ou cancelada.
     */
    protected function conferirRecuperada(\DOMDocument $dps, ?string $xmlNfse, string $idDps): void
    {
        if ($xmlNfse === null) {
            return;
        }

        $nfse = NacionalXml::parse($xmlNfse);
        $tomadorDps = ($t = $dps->getElementsByTagName('toma')->item(0)) instanceof \DOMElement
            ? (NacionalXml::texto($t, 'CNPJ') ?? NacionalXml::texto($t, 'CPF')) : null;
        $tomadorNfse = ($t = $nfse->getElementsByTagNameNS('*', 'toma')->item(0)) instanceof \DOMElement
            ? (NacionalXml::texto($t, 'CNPJ') ?? NacionalXml::texto($t, 'CPF')) : null;

        $campos = ['vServ', 'cTribNac', 'xDescServ'];
        $diverge = $tomadorDps !== $tomadorNfse;

        foreach ($campos as $campo) {
            $diverge = $diverge || NacionalXml::texto($dps, $campo) !== NacionalXml::texto($nfse, $campo);
        }

        if ($diverge) {
            $id = NacionalXml::atributo($nfse, 'infNFSe', 'Id');
            $chave = $id !== null ? substr(NacionalXml::digitos($id), 0, 50) : '?';

            throw new NotaFiscalException(
                "A DPS {$idDps} já gerou uma NFS-e com outros dados (chave {$chave}). Consulte essa nota e, se for o caso, cancele-a antes de emitir de novo."
            );
        }
    }

    /**
     * @param  array<string, mixed>  $json
     */
    protected function xmlNfse(array $json): ?string
    {
        $campo = $json['nfseXmlGZipB64'] ?? null;

        return is_string($campo) && $campo !== '' ? NacionalXml::descompactar($campo) : null;
    }
}
