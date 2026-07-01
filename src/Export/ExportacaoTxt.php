<?php

declare(strict_types=1);

namespace DanielBBarcelos\NotasFiscais\Export;

use DanielBBarcelos\NotasFiscais\Data\Nfse\ItemServico;
use DanielBBarcelos\NotasFiscais\Data\Nfse\NotaEmitida;
use DanielBBarcelos\NotasFiscais\Data\Nfse\NotaServico;
use DanielBBarcelos\NotasFiscais\Data\Nfse\Prestador;
use DanielBBarcelos\NotasFiscais\Data\Nfse\Tomador;
use DanielBBarcelos\NotasFiscais\Data\Shared\Valor;
use DanielBBarcelos\NotasFiscais\Enums\SituacaoNota;
use InvalidArgumentException;

/**
 * Gera o arquivo-texto de exportação de NFS-e do IPM (Nota Técnica 65/2020).
 * Formato posicional de largura fixa, um documento por trio de registros:
 *
 *  - Tipo 10: identificação do documento (prestador, número, valores, situação);
 *  - Tipo 20: um por item de serviço;
 *  - Tipo 30: dados do tomador.
 *
 * O layout é único do IPM (não varia entre os drivers REST e ABRASF), por isso
 * vive aqui e é reaproveitado pelos dois gateways. Campos ausentes viram zeros
 * (numéricos) ou espaços (texto), conforme a legenda da nota técnica.
 */
final class ExportacaoTxt
{
    /** Numérico: zeros à esquerda. */
    private const N = 'N';

    /** Alfanumérico/data: espaços à direita. */
    private const A = 'A';

    private const EOL = "\r\n";

    public static function gerar(NotaServico $dados, NotaEmitida $emitida, ?Prestador $prestador): string
    {
        $prestador = $dados->prestador ?? $prestador;

        if ($prestador === null) {
            throw new InvalidArgumentException(
                'Prestador não informado: defina-o na NotaServico ou em notas-fiscais.drivers.{provedor}.'
            );
        }

        $linhas = [self::registro10($dados, $emitida, $prestador)];

        foreach ($dados->itens as $item) {
            $linhas[] = self::registro20($dados, $emitida, $prestador, $item);
        }

        $linhas[] = self::registro30($dados->tomador);

        return implode(self::EOL, $linhas).self::EOL;
    }

    /** Registro Tipo 10 — identificação do documento (Tabela 1). */
    private static function registro10(NotaServico $dados, NotaEmitida $emitida, Prestador $prestador): string
    {
        return self::linha([
            [1, 2, self::A, '10'],
            [4, 1, self::A, self::tipoPessoa($prestador->cpfCnpjLimpo())],
            [6, 14, self::N, $prestador->cpfCnpjLimpo()],
            [21, 1, self::N, (string) ($emitida->serie ?? $dados->serie)],
            [23, 18, self::N, (string) ($emitida->numero ?? 0)],
            [42, 40, self::A, $emitida->codigoVerificacao ?? ''],
            [83, 10, self::A, self::data($emitida->data)],
            [94, 8, self::A, $emitida->hora ?? ''],
            [103, 1, self::A, self::tipoTomador($dados->tomador)],
            [105, 14, self::N, $dados->tomador->identificacaoLimpa()],
            [120, 18, self::N, self::real($dados->valorTotal)],
            [139, 18, self::N, self::real($dados->valorDesconto)],
            [158, 18, self::N, self::real($dados->valorIr)],
            [177, 18, self::N, self::real($dados->valorInss)],
            [196, 18, self::N, self::real($dados->valorContribuicaoSocial)],
            [215, 1, self::A, self::situacao($emitida->situacao)],
            [217, 250, self::A, $dados->observacao ?? ''],
            // 468/479: data e observações do cancelamento — não disponíveis na emissão.
            [730, 18, self::N, self::real($dados->valorPis)],
            [749, 18, self::N, self::real($dados->valorCofins)],
            [768, 18, self::N, self::real($dados->valorRps)],
            [787, 1, self::A, $dados->optanteSimplesNacional ? 'S' : 'N'],
        ]);
    }

    /** Registro Tipo 20 — item de serviço (Tabela 2). */
    private static function registro20(NotaServico $dados, NotaEmitida $emitida, Prestador $prestador, ItemServico $item): string
    {
        return self::linha([
            [1, 2, self::A, '20'],
            [4, 1, self::A, self::tipoPessoa($prestador->cpfCnpjLimpo())],
            [6, 14, self::N, $prestador->cpfCnpjLimpo()],
            [21, 1, self::N, (string) ($emitida->serie ?? $dados->serie)],
            [23, 18, self::N, (string) ($emitida->numero ?? 0)],
            [42, 7, self::N, self::digitos($item->codigoItemListaServico)],
            [50, 6, self::N, self::dec($item->aliquota)],
            [57, 250, self::A, $item->descritivo],
            [308, 2, self::N, (string) $item->situacaoTributaria->value],
            [311, 18, self::N, self::real($item->valorTributavel)],
            [330, 18, self::N, self::real($item->deducao)],
            [349, 18, self::N, self::real($item->issRetidoFonte)],
            [368, 8, self::N, self::digitos($item->codigoLocalPrestacao)],
            [377, 1, self::A, $item->tributaMunicipioPrestador ? 'S' : 'N'],
            [379, 18, self::N, self::real($item->valorIss)],
        ]);
    }

    /** Registro Tipo 30 — dados do tomador (Tabela 3). */
    private static function registro30(Tomador $t): string
    {
        $e = $t->endereco;

        return self::linha([
            [1, 2, self::A, '30'],
            [4, 1, self::A, self::tipoTomador($t)],
            [6, 14, self::N, $t->identificacaoLimpa()],
            [21, 100, self::A, $t->nomeRazaoSocial],
            [122, 40, self::A, $e->logradouro ?? ''],
            [163, 8, self::A, $e->numero ?? ''],
            [172, 20, self::A, $e->complemento ?? ''],
            [193, 20, self::A, $e->bairro ?? ''],
            // 214 (Cidade, por nome): o DTO guarda apenas o código do município.
            [245, 2, self::A, $e->uf ?? ($t->estado ?? '')],
            [248, 8, self::N, $e?->cepLimpo() ?? ''],
            [257, 12, self::A, self::telefone($t)],
        ]);
    }

    /**
     * Monta uma linha posicional: parte de um buffer de espaços do tamanho do
     * registro e grava cada campo na sua posição (início 1-based). Os intervalos
     * entre campos ficam como espaço, exatamente como o layout do IPM.
     *
     * @param  list<array{0:int,1:int,2:string,3:string}>  $campos  [inicio, tamanho, tipo, valor]
     */
    private static function linha(array $campos): string
    {
        $largura = 0;
        foreach ($campos as [$inicio, $tamanho]) {
            $largura = max($largura, $inicio + $tamanho - 1);
        }

        $buffer = str_repeat(' ', $largura);

        foreach ($campos as [$inicio, $tamanho, $tipo, $valor]) {
            $buffer = substr_replace($buffer, self::formatar($tipo, $valor, $tamanho), $inicio - 1, $tamanho);
        }

        return $buffer;
    }

    private static function formatar(string $tipo, string $valor, int $tamanho): string
    {
        if ($tipo === self::N) {
            return substr(str_pad($valor, $tamanho, '0', STR_PAD_LEFT), 0, $tamanho);
        }

        return substr(str_pad(substr($valor, 0, $tamanho), $tamanho), 0, $tamanho);
    }

    /** Valor monetário no formato Real do layout: ponto decimal, 2 casas. */
    private static function real(?Valor $valor): string
    {
        return $valor?->paraApi() ?? '0.00';
    }

    /** Percentual/alíquota (ex.: "3.00"), completado com zeros no campo. */
    private static function dec(float|string $n): string
    {
        $f = is_string($n) ? (float) str_replace(',', '.', $n) : $n;

        return number_format($f, 2, '.', '');
    }

    private static function digitos(string $valor): string
    {
        return preg_replace('/\D/', '', $valor) ?? '';
    }

    /** "F" (Física, CPF) ou "J" (Jurídica, CNPJ) pelo tamanho do documento. */
    private static function tipoPessoa(string $cpfCnpj): string
    {
        return strlen($cpfCnpj) === 14 ? 'J' : 'F';
    }

    private static function tipoTomador(Tomador $t): string
    {
        return $t->ehCnpj() ? 'J' : 'F';
    }

    private static function situacao(?SituacaoNota $situacao): string
    {
        return match ($situacao) {
            SituacaoNota::Cancelada => 'C',
            default => 'E',
        };
    }

    /** DDD + número do telefone comercial, sem máscara. */
    private static function telefone(Tomador $t): string
    {
        $fone = $t->telefoneComercial;

        return $fone !== null ? $fone->dddLimpo().$fone->numeroLimpo() : '';
    }

    /** Normaliza a data para dd/mm/aaaa (aceita dd/mm/aaaa ou ISO AAAA-MM-DD). */
    private static function data(?string $data): string
    {
        if ($data === null || $data === '') {
            return '';
        }

        if (preg_match('#^(\d{2})/(\d{2})/(\d{4})#', $data, $m) === 1) {
            return "{$m[1]}/{$m[2]}/{$m[3]}";
        }

        if (preg_match('#^(\d{4})-(\d{2})-(\d{2})#', $data, $m) === 1) {
            return "{$m[3]}/{$m[2]}/{$m[1]}";
        }

        return substr($data, 0, 10);
    }
}
