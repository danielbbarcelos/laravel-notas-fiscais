<?php

declare(strict_types=1);

namespace DanielBBarcelos\NotasFiscais\Contracts;

use DanielBBarcelos\NotasFiscais\Data\Nfse\Cancelamento;
use DanielBBarcelos\NotasFiscais\Data\Nfse\NotaEmitida;
use DanielBBarcelos\NotasFiscais\Data\Nfse\NotaServico;

/**
 * Contrato de operações de NFS-e (Nota Fiscal de Serviço eletrônica). Mesma
 * assinatura para todos os provedores — o de-para para a API específica vive no
 * driver que implementa esta interface.
 */
interface NfseGateway
{
    /** Emite uma NFS-e a partir dos dados do serviço. */
    public function emitir(NotaServico $dados): NotaEmitida;

    /** Cancela uma NFS-e já emitida. */
    public function cancelar(Cancelamento $dados): NotaEmitida;

    /** Consulta uma NFS-e por número, série e cadastro do prestador. */
    public function consultar(int $numero, int $serie, string $cadastro): NotaEmitida;

    /** Consulta uma NFS-e pelo código de autenticidade (40 caracteres). */
    public function consultarPorAutenticidade(string $codigo): NotaEmitida;

    // Reservado para expansão futura (previsto, fora do MVP):
    // public function substituir(NotaServico $dados, int $numeroAnterior, int $serieAnterior): NotaEmitida;
    // public function enviarLote(array $notas, bool $sincrono = true): LoteRps;
}
