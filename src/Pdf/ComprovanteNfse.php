<?php

declare(strict_types=1);

namespace DanielBBarcelos\NotasFiscais\Pdf;

use DanielBBarcelos\NotasFiscais\Data\Nfse\NotaEmitida;
use DanielBBarcelos\NotasFiscais\Data\Nfse\NotaServico;
use DanielBBarcelos\NotasFiscais\Data\Nfse\Prestador;
use DanielBBarcelos\NotasFiscais\Data\Shared\Valor;
use DanielBBarcelos\NotasFiscais\Exceptions\NotaFiscalException;
use Dompdf\Dompdf;

/**
 * Gera um COMPROVANTE da NFS-e em PDF (para conferência / anexo de e-mail /
 * download) a partir dos dados canônicos — independe do provedor.
 *
 * NÃO é o documento fiscal oficial: o PDF deixa isso explícito e aponta o link
 * de autenticidade do município, onde a NFS-e oficial pode ser consultada e
 * impressa (e que reflete o status atual, inclusive cancelamento).
 *
 * O cabeçalho exibe a identidade visual da empresa (logo + nome) via Emitente;
 * quando não informada, usa placeholders.
 *
 * Requer dompdf/dompdf (dependência opcional do pacote):
 *   composer require dompdf/dompdf
 */
final class ComprovanteNfse
{
    /**
     * Gera o PDF e devolve os bytes. O prestador vem da NotaServico ou do
     * parâmetro $prestador (útil quando a emissão usou o prestador da config).
     */
    public static function gerar(NotaServico $dados, NotaEmitida $emitida, ?Prestador $prestador = null, ?Emitente $emitente = null): string
    {
        if (! class_exists(Dompdf::class)) {
            throw new NotaFiscalException(
                'Para gerar o comprovante em PDF, instale o dompdf: composer require dompdf/dompdf.'
            );
        }

        $html = self::html($dados, $emitida, $dados->prestador ?? $prestador, $emitente);

        $dompdf = new Dompdf(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans']);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /** Salva o PDF num caminho e devolve o próprio caminho. */
    public static function salvar(string $caminho, NotaServico $dados, NotaEmitida $emitida, ?Prestador $prestador = null, ?Emitente $emitente = null): string
    {
        file_put_contents($caminho, self::gerar($dados, $emitida, $prestador, $emitente));

        return $caminho;
    }

    protected static function html(NotaServico $dados, NotaEmitida $emitida, ?Prestador $prestador, ?Emitente $emitente): string
    {
        $item = $dados->itens[0] ?? null;

        $cancelada = $emitida->cancelada();
        $situacao = $emitida->situacao?->name ?? '—';
        $badgeClasse = $cancelada ? 'no' : 'ok';
        $marcaCancelada = $cancelada ? '<div class="cancelada">CANCELADA</div>' : '';

        $numero = self::e((string) ($emitida->numero ?? '—'));
        $serie = self::e((string) ($emitida->serie ?? $dados->serie));
        $dataHora = self::e(trim(($emitida->data ?? '').' '.($emitida->hora ?? '')) ?: '—');
        $verificacao = self::e($emitida->codigoVerificacao ?? '—');
        $competencia = self::e($dados->competencia ?? '—');

        // Cabeçalho da empresa (logo + nome + dados), com placeholders.
        $nomeEmpresa = $emitente?->nome !== null && $emitente->nome !== ''
            ? self::e($emitente->nome)
            : '[ Nome da empresa ]';

        $logoUri = self::logoDataUri($emitente?->logo);
        $logoHtml = $logoUri !== null
            ? '<img class="logo-img" src="'.$logoUri.'" alt="logo">'
            : '<div class="logo-ph">SUA<br>LOGO</div>';

        $empresaDados = $prestador !== null
            ? 'CNPJ/CPF: '.self::e($prestador->cpfCnpj)
                .($prestador->inscricaoMunicipal !== null ? ' &nbsp;·&nbsp; Inscr. Municipal: '.self::e($prestador->inscricaoMunicipal) : '')
                .' &nbsp;·&nbsp; Município (cód.): '.self::e($prestador->codigoMunicipio)
            : '—';

        $avisoLink = $emitida->link !== null
            ? '<div class="auth-label">Consulte e imprima a NFS-e oficial em:</div>'
                .'<div class="link">'.self::e($emitida->link).'</div>'
            : '';

        $tom = $dados->tomador;
        $tomadorNome = self::e($tom->nomeRazaoSocial);
        $tomadorDoc = self::e($tom->identificacao);
        $tomadorEmail = $tom->email !== null ? self::e($tom->email) : '—';
        $tomEndereco = '—';
        if ($tom->endereco !== null) {
            $end = $tom->endereco;
            $partes = array_filter([
                $end->logradouro, $end->numero, $end->complemento, $end->bairro,
                $end->codigoMunicipio, $end->uf, $end->cep,
            ], fn ($x) => $x !== null && $x !== '');
            $tomEndereco = self::e(implode(', ', $partes));
        }

        $servico = $item !== null ? self::e($item->descritivo) : '—';
        $itemLista = $item?->codigoItemListaServico ? self::e($item->codigoItemListaServico) : '—';
        $cnae = $item?->codigoCnae ? self::e($item->codigoCnae) : '—';
        $aliquota = $item !== null ? self::pct($item->aliquota) : '—';

        $valorServicos = $item !== null ? self::brl($item->valorTributavel) : self::brl($dados->valorTotal);
        $valorDeducoes = self::brl($item?->deducao);
        $valorIss = self::brl($item?->valorIss);
        $valorTotal = self::brl($dados->valorTotal);

        $observacao = $dados->observacao !== null ? self::e($dados->observacao) : '';

        return <<<HTML
        <!DOCTYPE html>
        <html lang="pt-BR">
        <head>
        <meta charset="UTF-8">
        <style>
            * { font-family: "DejaVu Sans", sans-serif; }
            body { font-size: 11px; color: #1f2937; margin: 0; }
            .cancelada {
                position: fixed; top: 40%; left: 0; right: 0; text-align: center;
                font-size: 90px; color: rgba(220,38,38,.18); font-weight: bold;
                transform: rotate(-20deg);
            }
            .cab { width: 100%; border-collapse: collapse; border-bottom: 2px solid #e5e7eb; padding-bottom: 6px; }
            .cab td { vertical-align: middle; }
            .cab-logo { width: 96px; }
            .logo-img { max-width: 96px; max-height: 64px; }
            .logo-ph {
                width: 84px; height: 52px; border: 1px dashed #cbd5e1; border-radius: 6px;
                color: #94a3b8; font-size: 9px; text-align: center; line-height: 1.15; padding-top: 14px;
            }
            .cab-emp { padding-left: 12px; }
            .emp-nome { font-size: 15px; font-weight: bold; color: #111827; }
            .emp-dados { font-size: 9px; color: #6b7280; margin-top: 3px; }
            .cab-badge { text-align: right; width: 90px; }
            .badge { font-size: 10px; padding: 2px 8px; border-radius: 999px; }
            .badge.ok { background: #dcfce7; color: #166534; }
            .badge.no { background: #fee2e2; color: #991b1b; }
            h1 { font-size: 16px; margin: 10px 0 2px; }
            .sub { color: #6b7280; font-size: 10px; }
            .aviso {
                margin-top: 8px; padding: 8px 10px; border: 1px solid #fde68a;
                background: #fffbeb; border-radius: 6px; font-size: 10px; color: #92400e;
            }
            .auth {
                margin-top: 8px; padding: 8px 10px; border: 1px solid #bfdbfe;
                background: #eff6ff; border-radius: 6px;
            }
            .auth h2 { font-size: 11px; margin: 0 0 4px; color: #1e40af; text-transform: uppercase; letter-spacing: .04em; }
            .auth .cod { font-size: 11px; }
            .auth .cod strong { font-variant-numeric: tabular-nums; }
            .auth-label { margin-top: 6px; color: #374151; font-size: 10px; }
            .link { font-size: 9px; color: #1d4ed8; word-break: break-all; margin-top: 2px; }
            .box { border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 10px; margin-top: 8px; }
            .box h2 { font-size: 11px; margin: 0 0 4px; color: #374151; text-transform: uppercase; letter-spacing: .04em; }
            table { width: 100%; border-collapse: collapse; }
            td { padding: 2px 0; vertical-align: top; }
            td.k { color: #6b7280; width: 32%; }
            .grid td { width: 50%; }
            .valores td.k { width: 60%; }
            .valores td.v { text-align: right; font-variant-numeric: tabular-nums; }
            .total td { border-top: 1px solid #cbd5e1; font-weight: bold; padding-top: 5px; }
            .obs { margin-top: 6px; font-size: 10px; color: #374151; }
        </style>
        </head>
        <body>
        {$marcaCancelada}

        <table class="cab">
            <tr>
                <td class="cab-logo">{$logoHtml}</td>
                <td class="cab-emp">
                    <div class="emp-nome">{$nomeEmpresa}</div>
                    <div class="emp-dados">{$empresaDados}</div>
                </td>
                <td class="cab-badge"><span class="badge {$badgeClasse}">{$situacao}</span></td>
            </tr>
        </table>

        <h1>Comprovante de NFS-e</h1>
        <div class="sub">Número {$numero} &nbsp;·&nbsp; Série {$serie} &nbsp;·&nbsp; Emissão {$dataHora} &nbsp;·&nbsp; Competência {$competencia}</div>

        <div class="aviso">
            <strong>Este é um comprovante para conferência e anexo — não é o documento fiscal oficial.</strong>
            A NFS-e oficial, com validade fiscal e status sempre atualizado (inclusive cancelamento), está disponível no link de autenticidade abaixo.
        </div>

        <div class="auth">
            <h2>Autenticidade</h2>
            <div class="cod">Código de verificação: <strong>{$verificacao}</strong></div>
            {$avisoLink}
        </div>

        <div class="box">
            <h2>Tomador</h2>
            <table class="grid">
                <tr><td class="k">Nome / Razão social</td><td>{$tomadorNome}</td></tr>
                <tr><td class="k">CPF/CNPJ</td><td>{$tomadorDoc}</td></tr>
                <tr><td class="k">E-mail</td><td>{$tomadorEmail}</td></tr>
                <tr><td class="k">Endereço</td><td>{$tomEndereco}</td></tr>
            </table>
        </div>

        <div class="box">
            <h2>Serviço</h2>
            <table>
                <tr><td class="k">Discriminação</td><td>{$servico}</td></tr>
                <tr><td class="k">Item lista serviço (LC 116)</td><td>{$itemLista}</td></tr>
                <tr><td class="k">CNAE</td><td>{$cnae}</td></tr>
                <tr><td class="k">Alíquota</td><td>{$aliquota}</td></tr>
            </table>
            <div class="obs">{$observacao}</div>
        </div>

        <div class="box">
            <h2>Valores</h2>
            <table class="valores">
                <tr><td class="k">Valor dos serviços</td><td class="v">{$valorServicos}</td></tr>
                <tr><td class="k">Deduções</td><td class="v">{$valorDeducoes}</td></tr>
                <tr><td class="k">ISS</td><td class="v">{$valorIss}</td></tr>
                <tr class="total"><td class="k">Valor total da NFS-e</td><td class="v">{$valorTotal}</td></tr>
            </table>
        </div>
        </body>
        </html>
        HTML;
    }

    /** Lê o logo de um caminho absoluto e devolve um data URI base64 (ou null). */
    protected static function logoDataUri(?string $caminho): ?string
    {
        if ($caminho === null || $caminho === '' || ! is_file($caminho) || ! is_readable($caminho)) {
            return null;
        }

        $dados = @file_get_contents($caminho);
        if ($dados === false || $dados === '') {
            return null;
        }

        return 'data:'.self::mime($caminho).';base64,'.base64_encode($dados);
    }

    protected static function mime(string $caminho): string
    {
        $porExtensao = [
            'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif', 'svg' => 'image/svg+xml', 'webp' => 'image/webp',
        ];

        $ext = strtolower(pathinfo($caminho, PATHINFO_EXTENSION));

        if (isset($porExtensao[$ext])) {
            return $porExtensao[$ext];
        }

        return function_exists('mime_content_type')
            ? (@mime_content_type($caminho) ?: 'image/png')
            : 'image/png';
    }

    protected static function e(string $valor): string
    {
        return htmlspecialchars($valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    protected static function brl(?Valor $valor): string
    {
        $centavos = $valor?->centavos ?? 0;

        return 'R$ '.number_format($centavos / 100, 2, ',', '.');
    }

    protected static function pct(float|string $aliquota): string
    {
        $n = is_string($aliquota) ? (float) str_replace(',', '.', $aliquota) : $aliquota;

        return number_format($n, 2, ',', '.').'%';
    }
}
