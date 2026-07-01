<?php

declare(strict_types=1);

namespace DanielBBarcelos\NotasFiscais\Contracts;

use DanielBBarcelos\NotasFiscais\Data\Nfse\NotaEmitida;
use DanielBBarcelos\NotasFiscais\Data\Nfse\NotaServico;

/**
 * Serialização da NFS-e para os arquivos que o portal Atende.Net disponibiliza
 * em "Download" (XML nativo do provedor e TXT de exportação). Contrato opcional:
 * um gateway pode implementá-lo além de {@see NfseGateway}.
 *
 * Importante: o Web Service do IPM NÃO devolve esses arquivos prontos — eles são
 * gerados localmente a partir dos DTOs (a nota enviada + o retorno da emissão).
 * Por isso os métodos recebem a NotaServico de origem, e não apenas o número.
 */
interface ExportaArquivos
{
    /**
     * Serializa a NFS-e no XML nativo do provedor.
     *
     * No ABRASF, quando $emitida traz o XML oficial assinado pela prefeitura (no
     * retorno da emissão/consulta), ele é devolvido; caso contrário, monta-se o
     * RPS/declaração a partir de $dados. No REST proprietário sempre gera o XML
     * <nfse> do IPM a partir de $dados (não existe XML assinado nesse padrão).
     */
    public function xmlNota(NotaServico $dados, ?NotaEmitida $emitida = null): string;

    /**
     * Gera o arquivo-texto de exportação de NFS-e (IPM, Nota Técnica 65/2020:
     * registros 10/20/30). Precisa da nota enviada e do retorno da emissão
     * (número, código de autenticação, situação).
     */
    public function txtExportacao(NotaServico $dados, NotaEmitida $emitida): string;
}
