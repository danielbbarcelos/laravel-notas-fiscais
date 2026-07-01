<?php

declare(strict_types=1);

namespace DanielBBarcelos\NotasFiscais\Drivers\IpmAbrasf;

use DanielBBarcelos\NotasFiscais\Contracts\NfseGateway;
use DanielBBarcelos\NotasFiscais\Data\Nfse\Cancelamento;
use DanielBBarcelos\NotasFiscais\Data\Nfse\NotaEmitida;
use DanielBBarcelos\NotasFiscais\Data\Nfse\NotaServico;
use DanielBBarcelos\NotasFiscais\Data\Nfse\Prestador;
use DanielBBarcelos\NotasFiscais\Drivers\IpmAbrasf\Mappers\CancelamentoAbrasfMapper;
use DanielBBarcelos\NotasFiscais\Drivers\IpmAbrasf\Mappers\ConsultaAbrasfMapper;
use DanielBBarcelos\NotasFiscais\Drivers\IpmAbrasf\Mappers\DeclaracaoMapper;
use DanielBBarcelos\NotasFiscais\Enums\SituacaoNota;
use DanielBBarcelos\NotasFiscais\Exceptions\NotaFiscalException;

/**
 * Implementação de NFS-e do IPM Atende.Net no padrão ABRASF 2.04 (SOAP).
 * Reaproveita os contratos e DTOs canônicos; só os mappers e o transporte
 * diferem do driver REST proprietário.
 */
class IpmAbrasfNfseGateway implements NfseGateway
{
    public function __construct(
        protected AbrasfSoapConnector $http,
        protected DeclaracaoMapper $declaracoes,
        protected CancelamentoAbrasfMapper $cancelamentos,
        protected ConsultaAbrasfMapper $consultas,
        protected ?Prestador $prestadorPadrao = null,
        protected ?string $linkTemplate = null,
    ) {
    }

    public function emitir(NotaServico $dados): NotaEmitida
    {
        $xml = $this->declaracoes->paraApi($dados, $this->prestadorPadrao, $dados->teste);

        return $this->declaracoes->paraDominio($this->http->enviar($xml), $this->linkTemplate);
    }

    public function cancelar(Cancelamento $dados): NotaEmitida
    {
        $prestador = $dados->prestador ?? $this->prestadorPadrao;

        $this->http->enviar($this->cancelamentos->paraApi($dados, $prestador));

        // O connector lança em caso de erro; aqui o cancelamento já está confirmado.
        return new NotaEmitida(
            numero: $dados->numero,
            serie: $dados->serie,
            data: null,
            hora: null,
            situacao: SituacaoNota::Cancelada,
            codigoVerificacao: null,
            link: null,
            bruto: [],
        );
    }

    public function consultar(int $numero, int $serie, string $cadastro): NotaEmitida
    {
        $xml = $this->consultas->porNumero($numero, $this->prestadorPadrao);

        return $this->consultas->paraDominio($this->http->enviar($xml), $this->linkTemplate);
    }

    public function consultarPorAutenticidade(string $codigo): NotaEmitida
    {
        throw new NotaFiscalException(
            'Consulta por código de autenticidade não é suportada no padrão ABRASF; use consultar(numero, serie, cadastro).'
        );
    }
}
