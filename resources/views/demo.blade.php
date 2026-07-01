<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>NFS-e · Playground</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
@php
    $old = fn (string $campo, $padrao = null) => old($campo, optional($request)->input($campo) ?? $padrao);
@endphp
<body class="bg-slate-100 text-slate-800">
<div class="max-w-6xl mx-auto p-6">
    <header class="mb-6">
        <h1 class="text-2xl font-bold">NFS-e · Playground de validação</h1>
        <p class="text-sm text-slate-500">Provedor <strong>IPM Atende.Net</strong> — escolha o padrão <strong>REST</strong> ou <strong>ABRASF</strong>. Use o modo <em>faked</em> para validar campos e telas sem credenciais.</p>
    </header>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {{-- Formulário --}}
        <form method="POST" action="{{ route('notas-fiscais.demo.emitir') }}" class="bg-white rounded-xl shadow p-5 space-y-4">
            @csrf

            <div class="flex flex-wrap items-center gap-4 p-3 bg-slate-50 rounded-lg border border-slate-200">
                <div>
                    <label class="block text-xs font-semibold text-slate-500 mb-1">Padrão</label>
                    <select name="padrao" class="border rounded px-2 py-1 text-sm">
                        <option value="ipm" @selected($old('padrao', 'ipm') === 'ipm')>REST proprietário (ipm)</option>
                        <option value="ipm-abrasf" @selected($old('padrao') === 'ipm-abrasf')>ABRASF SOAP (ipm-abrasf)</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-500 mb-1">Backend</label>
                    <select name="backend" class="border rounded px-2 py-1 text-sm">
                        <option value="faked" @selected($old('backend', 'faked') === 'faked')>Faked (XML dos docs)</option>
                        <option value="real" @selected($old('backend') === 'real')>IPM real (config .env)</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-500 mb-1">Cenário (faked)</label>
                    <select name="cenario" class="border rounded px-2 py-1 text-sm">
                        <option value="sucesso" @selected($old('cenario', 'sucesso') === 'sucesso')>Sucesso</option>
                        <option value="erro" @selected($old('cenario') === 'erro')>Erro de validação</option>
                        <option value="cancelado" @selected($old('cenario') === 'cancelado')>Cancelada</option>
                    </select>
                </div>
                <label class="flex items-center gap-2 text-sm mt-4">
                    <input type="checkbox" name="teste" value="1" @checked($old('teste', '1'))>
                    <span>Modo teste (<code>&lt;nfse_teste&gt;</code>)</span>
                </label>
            </div>

            <div class="grid grid-cols-3 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-slate-500 mb-1">Série</label>
                    <input name="serie" value="{{ $old('serie', '1') }}" class="w-full border rounded px-2 py-1 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-500 mb-1">Data fato gerador</label>
                    <input name="data_fato_gerador" value="{{ $old('data_fato_gerador', now()->format('d/m/Y')) }}" class="w-full border rounded px-2 py-1 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-500 mb-1">Valor total (R$)</label>
                    <input name="valor" value="{{ $old('valor', '1000.00') }}" class="w-full border rounded px-2 py-1 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-500 mb-1">Competência (ABRASF)</label>
                    <input name="competencia" value="{{ $old('competencia', now()->format('Y-m-01')) }}" class="w-full border rounded px-2 py-1 text-sm">
                </div>
            </div>

            <fieldset class="border border-slate-200 rounded-lg p-3">
                <legend class="text-xs font-bold text-slate-600 px-1">Tomador</legend>
                <div class="grid grid-cols-3 gap-3">
                    <div>
                        <label class="block text-xs font-semibold text-slate-500 mb-1">Tipo</label>
                        <select name="tomador_tipo" class="w-full border rounded px-2 py-1 text-sm">
                            @foreach ($tipos as $tipo)
                                <option value="{{ $tipo->value }}" @selected($old('tomador_tipo', 'J') === $tipo->value)>{{ $tipo->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-span-2">
                        <label class="block text-xs font-semibold text-slate-500 mb-1">CPF/CNPJ ou identificação</label>
                        <input name="tomador_doc" value="{{ $old('tomador_doc', '12.345.678/0001-95') }}" class="w-full border rounded px-2 py-1 text-sm">
                    </div>
                    <div class="col-span-2">
                        <label class="block text-xs font-semibold text-slate-500 mb-1">Nome / Razão social</label>
                        <input name="tomador_nome" value="{{ $old('tomador_nome', 'Empresa Tomadora LTDA') }}" class="w-full border rounded px-2 py-1 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-500 mb-1">E-mail</label>
                        <input name="tomador_email" value="{{ $old('tomador_email', 'tomador@exemplo.com.br') }}" class="w-full border rounded px-2 py-1 text-sm">
                    </div>
                    <div class="col-span-2">
                        <label class="block text-xs font-semibold text-slate-500 mb-1">Logradouro</label>
                        <input name="end_logradouro" value="{{ $old('end_logradouro', 'Rua das Flores') }}" class="w-full border rounded px-2 py-1 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-500 mb-1">Número</label>
                        <input name="end_numero" value="{{ $old('end_numero', '123') }}" class="w-full border rounded px-2 py-1 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-500 mb-1">Bairro</label>
                        <input name="end_bairro" value="{{ $old('end_bairro', 'Centro') }}" class="w-full border rounded px-2 py-1 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-500 mb-1">Cód. município (TOM/IBGE)</label>
                        <input name="end_cidade" value="{{ $old('end_cidade', '8055') }}" class="w-full border rounded px-2 py-1 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-500 mb-1">UF (ABRASF)</label>
                        <input name="end_uf" value="{{ $old('end_uf', 'MG') }}" class="w-full border rounded px-2 py-1 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-500 mb-1">CEP</label>
                        <input name="end_cep" value="{{ $old('end_cep', '89160-000') }}" class="w-full border rounded px-2 py-1 text-sm">
                    </div>
                </div>
            </fieldset>

            <fieldset class="border border-slate-200 rounded-lg p-3">
                <legend class="text-xs font-bold text-slate-600 px-1">Item de serviço</legend>
                <div class="grid grid-cols-3 gap-3">
                    <div>
                        <label class="block text-xs font-semibold text-slate-500 mb-1">Cód. item (LC 116)</label>
                        <input name="item_codigo" value="{{ $old('item_codigo', '010700') }}" class="w-full border rounded px-2 py-1 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-500 mb-1">Alíquota (%)</label>
                        <input name="item_aliquota" value="{{ $old('item_aliquota', '3.00') }}" class="w-full border rounded px-2 py-1 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-500 mb-1">Situação tributária</label>
                        <select name="item_situacao" class="w-full border rounded px-2 py-1 text-sm">
                            @foreach ($situacoes as $sit)
                                <option value="{{ $sit->value }}" @selected((int) $old('item_situacao', '0') === $sit->value)>{{ $sit->value }} — {{ $sit->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-500 mb-1">CNAE (ABRASF)</label>
                        <input name="item_cnae" value="{{ $old('item_cnae', '6201501') }}" class="w-full border rounded px-2 py-1 text-sm">
                    </div>
                    <div class="col-span-3">
                        <label class="block text-xs font-semibold text-slate-500 mb-1">Descritivo</label>
                        <input name="item_descritivo" value="{{ $old('item_descritivo', 'Desenvolvimento de software sob encomenda') }}" class="w-full border rounded px-2 py-1 text-sm">
                    </div>
                </div>
            </fieldset>

            <div>
                <label class="block text-xs font-semibold text-slate-500 mb-1">Observação</label>
                <input name="observacao" value="{{ $old('observacao') }}" class="w-full border rounded px-2 py-1 text-sm">
            </div>

            <details class="text-sm">
                <summary class="cursor-pointer text-slate-500">Prestador (opcional — padrão vem da config)</summary>
                <div class="grid grid-cols-3 gap-3 mt-2">
                    <div>
                        <label class="block text-xs font-semibold text-slate-500 mb-1">CPF/CNPJ</label>
                        <input name="prestador_doc" value="{{ $old('prestador_doc') }}" placeholder="{{ optional($configPrestador)->cpfCnpj }}" class="w-full border rounded px-2 py-1 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-500 mb-1">Município (TOM/IBGE)</label>
                        <input name="prestador_cidade" value="{{ $old('prestador_cidade') }}" placeholder="{{ optional($configPrestador)->codigoMunicipio }}" class="w-full border rounded px-2 py-1 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-500 mb-1">Inscr. Municipal (ABRASF)</label>
                        <input name="prestador_im" value="{{ $old('prestador_im') }}" placeholder="{{ optional($configPrestador)->inscricaoMunicipal }}" class="w-full border rounded px-2 py-1 text-sm">
                    </div>
                </div>
            </details>

            <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-semibold py-2 rounded-lg">Emitir NFS-e</button>
        </form>

        {{-- Resultado --}}
        <div class="space-y-6">
            @isset($xmlEnviado)
                <div class="bg-white rounded-xl shadow p-5">
                    <h2 class="font-bold mb-2 text-slate-700">XML gerado (enviado ao IPM)</h2>
                    <pre class="bg-slate-900 text-emerald-300 text-xs rounded-lg p-3 overflow-auto max-h-72">{{ $xmlEnviado }}</pre>
                </div>
            @endisset

            @if ($erro)
                <div class="bg-white rounded-xl shadow p-5 border-l-4 border-red-500">
                    <h2 class="font-bold mb-2 text-red-600">Erro</h2>
                    @if ($erro instanceof \DanielBBarcelos\NotasFiscais\Exceptions\NotaFiscalApiException && $erro->codigo)
                        <p class="text-sm"><span class="font-mono bg-red-100 text-red-700 px-1 rounded">{{ $erro->codigo }}</span></p>
                    @endif
                    <p class="text-sm text-slate-700 mt-1">{{ $erro->getMessage() }}</p>
                </div>
            @endif

            @if ($resultado)
                @php($emitida = $resultado->emitida())
                <div class="bg-white rounded-xl shadow p-5 border-l-4 {{ $emitida ? 'border-emerald-500' : 'border-amber-500' }}">
                    <div class="flex items-center justify-between mb-3">
                        <h2 class="font-bold text-slate-700">NotaEmitida</h2>
                        <span class="text-xs font-semibold px-2 py-1 rounded-full {{ $emitida ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' }}">
                            {{ $resultado->situacao?->name ?? '—' }}
                        </span>
                    </div>
                    <dl class="grid grid-cols-2 gap-y-2 text-sm">
                        <dt class="text-slate-500">Número</dt><dd class="font-mono">{{ $resultado->numero ?? '—' }}</dd>
                        <dt class="text-slate-500">Série</dt><dd class="font-mono">{{ $resultado->serie ?? '—' }}</dd>
                        <dt class="text-slate-500">Data / Hora</dt><dd class="font-mono">{{ $resultado->data ?? '—' }} {{ $resultado->hora }}</dd>
                        <dt class="text-slate-500">Cód. verificação</dt><dd class="font-mono break-all">{{ $resultado->codigoVerificacao ?? '—' }}</dd>
                        <dt class="text-slate-500">Link (PDF)</dt>
                        <dd class="break-all">
                            @if ($resultado->link)
                                <a href="{{ $resultado->link }}" target="_blank" class="text-indigo-600 underline">abrir</a>
                            @else — @endif
                        </dd>
                    </dl>
                    <details class="mt-3 text-sm">
                        <summary class="cursor-pointer text-slate-500">Retorno bruto</summary>
                        <pre class="bg-slate-50 text-slate-700 text-xs rounded-lg p-3 overflow-auto max-h-60 mt-2">{{ json_encode($resultado->bruto, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                    </details>
                </div>
            @endif

            @unless($xmlEnviado || $erro || $resultado)
                <div class="bg-white rounded-xl shadow p-5 text-sm text-slate-500">
                    Preencha o formulário e clique em <strong>Emitir NFS-e</strong> para ver o XML gerado e o retorno parseado aqui.
                </div>
            @endunless
        </div>
    </div>
</div>
</body>
</html>
