<?php

declare(strict_types=1);

namespace DanielBBarcelos\NotasFiscais\Drivers\Nacional\Mappers;

use DanielBBarcelos\NotasFiscais\Data\Nfse\Cancelamento;
use DanielBBarcelos\NotasFiscais\Data\Nfse\Prestador;
use DanielBBarcelos\NotasFiscais\Drivers\Nacional\NacionalXml;
use DateTimeImmutable;
use DateTimeZone;
use DOMDocument;
use DOMElement;
use InvalidArgumentException;

/**
 * Cancelamento no padrão nacional é um evento (e101101) registrado na NFS-e:
 * monta o pedRegEvento, que o gateway assina e envia para /nfse/{chave}/eventos.
 */
class CancelamentoNacionalMapper
{
    public const EVENTO = '101101';

    public function __construct(
        protected string $versaoAplicativo = 'laravel-nf 1.3',
    ) {
    }

    public function paraApi(Cancelamento $dados, ?Prestador $prestador, int $ambiente): DOMDocument
    {
        $chave = self::chave($dados);

        if ($prestador === null) {
            throw new InvalidArgumentException(
                'Prestador não informado: defina-o no Cancelamento ou em notas-fiscais.drivers.{provedor}.'
            );
        }

        $motivo = NacionalXml::texto2000($dados->motivo, 255);

        if (mb_strlen($motivo) < 15) {
            throw new InvalidArgumentException(
                'O motivo do cancelamento precisa ter entre 15 e 255 caracteres no padrão nacional.'
            );
        }

        $codigo = $dados->codigoMotivo ?? 1;

        if (! in_array($codigo, [1, 2, 9], true)) {
            throw new InvalidArgumentException("Código de justificativa inválido: {$codigo} (use 1, 2 ou 9).");
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = false;

        $ped = $dom->createElementNS(NacionalXml::NS, 'pedRegEvento');
        $ped->setAttribute('versao', DpsMapper::VERSAO);
        $dom->appendChild($ped);

        $inf = $this->el($dom, $ped, 'infPedReg');
        // "PRE" + chave (50) + tipo do evento (6).
        $inf->setAttribute('Id', 'PRE'.$chave.self::EVENTO);

        $this->add($dom, $inf, 'tpAmb', (string) $ambiente);
        $this->add($dom, $inf, 'verAplic', mb_substr($this->versaoAplicativo, 0, 20));
        $this->add($dom, $inf, 'dhEvento', (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->modify('-1 minute')->format('Y-m-d\TH:i:sP'));

        $documento = $prestador->cpfCnpjLimpo();
        $this->add($dom, $inf, strlen($documento) === 11 ? 'CPFAutor' : 'CNPJAutor', $documento);
        $this->add($dom, $inf, 'chNFSe', $chave);

        $evento = $this->el($dom, $inf, 'e'.self::EVENTO);
        $this->add($dom, $evento, 'xDesc', 'Cancelamento de NFS-e');
        $this->add($dom, $evento, 'cMotivo', (string) $codigo);
        $this->add($dom, $evento, 'xMotivo', $motivo);

        return $dom;
    }

    public static function chave(Cancelamento $dados): string
    {
        $chave = NacionalXml::digitos($dados->chaveAcesso);

        if (strlen($chave) !== 50) {
            throw new InvalidArgumentException(
                'Cancelamento sem chaveAcesso: no padrão nacional a NFS-e é identificada pela chave de acesso de 50 dígitos.'
            );
        }

        return $chave;
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
}
