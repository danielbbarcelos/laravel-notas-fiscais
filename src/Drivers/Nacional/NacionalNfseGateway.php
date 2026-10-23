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
     * A NFS-e recuperada pelo Id da DPS precisa ser a desta emissão. Se valor ou
     * tomador divergem, o número da DPS já foi usado por outra nota do mesmo
     * emitente e série (outra instalação, outro sistema): devolver a nota alheia
     * como se fosse esta seria pior que falhar.
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

        if (NacionalXml::texto($dps, 'vServ') !== NacionalXml::texto($nfse, 'vServ') || $tomadorDps !== $tomadorNfse) {
            throw new NotaFiscalException(
                "A DPS {$idDps} já foi usada por outra NFS-e deste emitente, com outro valor ou tomador. Use outra série ou outro número de DPS."
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
