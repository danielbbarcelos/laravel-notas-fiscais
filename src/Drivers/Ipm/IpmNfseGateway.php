<?php

declare(strict_types=1);

namespace DanielBBarcelos\NotasFiscais\Drivers\Ipm;

use DanielBBarcelos\NotasFiscais\Contracts\NfseGateway;
use DanielBBarcelos\NotasFiscais\Data\Nfse\Cancelamento;
use DanielBBarcelos\NotasFiscais\Data\Nfse\NotaEmitida;
use DanielBBarcelos\NotasFiscais\Data\Nfse\NotaServico;
use DanielBBarcelos\NotasFiscais\Data\Nfse\Prestador;
use DanielBBarcelos\NotasFiscais\Drivers\Ipm\Mappers\CancelamentoMapper;
use DanielBBarcelos\NotasFiscais\Drivers\Ipm\Mappers\ConsultaMapper;
use DanielBBarcelos\NotasFiscais\Drivers\Ipm\Mappers\NotaServicoMapper;

/**
 * Implementação de NFS-e do IPM Atende.Net. Constrói o XML via mappers, envia
 * pelo connector e devolve o retorno mapeado para NotaEmitida.
 */
class IpmNfseGateway implements NfseGateway
{
    public function __construct(
        protected IpmConnector $http,
        protected NotaServicoMapper $notas,
        protected CancelamentoMapper $cancelamentos,
        protected ConsultaMapper $consultas,
        protected ?Prestador $prestadorPadrao = null,
    ) {
    }

    public function emitir(NotaServico $dados): NotaEmitida
    {
        $xml = $this->notas->paraApi($dados, $this->prestadorPadrao);

        return $this->notas->paraDominio($this->http->enviar($xml));
    }

    public function cancelar(Cancelamento $dados): NotaEmitida
    {
        $xml = $this->cancelamentos->paraApi($dados, $this->prestadorPadrao);

        return $this->notas->paraDominio($this->http->enviar($xml));
    }

    public function consultar(int $numero, int $serie, string $cadastro): NotaEmitida
    {
        $xml = $this->consultas->porNumero($numero, $serie, $cadastro);

        return $this->notas->paraDominio($this->http->enviar($xml));
    }

    public function consultarPorAutenticidade(string $codigo): NotaEmitida
    {
        $xml = $this->consultas->porAutenticidade($codigo);

        return $this->notas->paraDominio($this->http->enviar($xml));
    }
}
