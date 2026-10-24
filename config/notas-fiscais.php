<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Provedor padrão
    |--------------------------------------------------------------------------
    |
    | Driver usado quando você chama a fachada sem especificar o provedor:
    | NotaFiscal::nfse()->emitir(...). Deve existir em "drivers" abaixo.
    |
    */

    'default' => env('NFSE_DRIVER', 'ipm'),

    /*
    |--------------------------------------------------------------------------
    | Playground / demo visual
    |--------------------------------------------------------------------------
    |
    | Quando true, o pacote registra rotas e views de teste em /notas-fiscais/demo
    | para validação visual dos campos e retornos. Mantenha DESLIGADO em produção.
    |
    */

    'demo' => (bool) env('NFSE_DEMO', false),

    /*
    |--------------------------------------------------------------------------
    | Drivers (provedores) configurados
    |--------------------------------------------------------------------------
    |
    | Cada entrada aponta para um driver registrado no NotaFiscalManager. A
    | chave "driver" determina a implementação; o restante é específico do
    | provedor.
    |
    | Os valores via env() abaixo são apenas o DEFAULT (cenário single-tenant) e
    | são todos OPCIONAIS. Em aplicações SaaS multi-tenant, não guarde credenciais
    | de clientes aqui: a aplicação deve armazená-las com segurança e injetá-las
    | em runtime, sem passar pelo .env:
    |
    |   NotaFiscal::build([
    |       'driver'   => 'ipm',
    |       'base_url' => $tenant->ipmUrl,
    |       'cpf_cnpj' => $tenant->cnpj,
    |       'senha'    => decrypt($tenant->ipmSenha),
    |       'cidade'   => $tenant->codigoTom,
    |   ])->nfse()->emitir($nota);
    |
    | ou, sobrepondo apenas alguns campos sobre a base nomeada:
    |
    |   NotaFiscal::driver('ipm', ['cpf_cnpj' => ..., 'senha' => ...]);
    |
    */

    'drivers' => [

        /*
        | IPM REST proprietário (NTE 35/2021): layout <nfse> via multipart no
        | serviço WNERestServiceNFSe. Use para municípios que expõem esse REST.
        */
        'ipm' => [
            'driver' => 'ipm',

            // URL completa do Web Service REST do município (varia por prefeitura).
            // Ex.: https://riodosul.atende.net/atende.php?pg=rest&service=WNERestServiceNFSe&cidade=padrao
            //  ou: https://ws-riodosul.atende.net:7443/?pg=rest&service=WNERestServiceNFSe
            'base_url' => env('IPM_BASE_URL'),

            // Login do Web Service: CPF/CNPJ do prestador (emissor) e senha do sistema.
            'cpf_cnpj' => env('IPM_CPF_CNPJ'),
            'senha'    => env('IPM_SENHA'),

            // Código TOM (Receita Federal) do município de inscrição do prestador.
            'cidade'   => env('IPM_CIDADE_TOM'),

            // Alguns municípios só aceitam requisição de IP brasileiro. Se a
            // aplicação roda fora do país, aponte para um proxy no Brasil:
            // "http://usuario:senha@host:3128" ou "socks5://host:1080".
            'proxy'    => env('IPM_PROXY'),

            'timeout'  => (int) env('IPM_TIMEOUT', 30),
        ],

        /*
        | IPM ABRASF 2.04 (SOAP): serviço WNENotaFiscalEletronicaNfe, autenticação
        | Basic (login/senha), SEM certificado/assinatura. Use para municípios no
        | padrão ABRASF — ex.: Pouso Alegre/MG. Note que aqui o município é
        | identificado pelo código IBGE (não TOM), como o ABRASF exige.
        */
        'ipm-abrasf' => [
            'driver' => 'ipm-abrasf',

            // URL SOAP do município (sem &wsdl).
            // Ex.: https://pousoalegre.atende.net/?pg=services&service=WNENotaFiscalEletronicaNfe
            'base_url' => env('IPM_ABRASF_URL'),

            // Login Basic = CPF/CNPJ do prestador (apenas números) + senha.
            'cpf_cnpj' => env('IPM_ABRASF_CNPJ'),
            'senha'    => env('IPM_ABRASF_SENHA'),

            // Identificadores do prestador exigidos pelo ABRASF.
            'inscricao_municipal' => env('IPM_ABRASF_INSCRICAO_MUNICIPAL'),
            'codigo_ibge'         => env('IPM_ABRASF_CODIGO_IBGE'),

            // Código NBS (Nomenclatura Brasileira de Serviços) padrão do emissor:
            // 9 dígitos, com ou sem pontuação. Obrigatório desde a versão 2.03 do
            // layout ABRASF; sem ele o município recusa a emissão com o erro E351.
            // Serve de default para todo ItemServico que não informe codigoNbs.
            // Ex.: 1.0105.10.00 (serviços de desenvolvimento de software sob encomenda).
            'codigo_nbs' => env('IPM_ABRASF_CODIGO_NBS'),

            // O GerarNfseResposta de alguns municípios não retorna o link do PDF.
            // Informe aqui o template da URL de consulta/autenticidade do Atende.Net,
            // com {codigo} (código de verificação) e, opcionalmente, {numero}. O driver
            // preenche NotaEmitida::link quando a resposta não traz o link. Ex.:
            // https://pousoalegre.atende.net/?pg=autoatendimento#!/tipo/servico/valor/{ID}/padrao/1/load/1/identificador/{codigo}
            // (o {ID} do serviço varia por município — pegue abrindo uma NFS-e no portal).
            'link_template' => env('IPM_ABRASF_LINK_TEMPLATE'),

            // Alguns municípios só aceitam requisição de IP brasileiro. Se a
            // aplicação roda fora do país, aponte para um proxy no Brasil:
            // "http://usuario:senha@host:3128" ou "socks5://host:1080".
            'proxy'    => env('IPM_ABRASF_PROXY'),

            'timeout'  => (int) env('IPM_ABRASF_TIMEOUT', 60),
        ],


        /*
        | Sistema Nacional NFS-e (Emissor Nacional / API da Sefin Nacional),
        | leiaute DPS v1.01. Autenticação por mTLS com o certificado A1 (ICP-Brasil)
        | do emitente, o mesmo que assina a DPS e os eventos. Não há senha de
        | prefeitura. Quem numera é o emitente: passe NotaServico::numeroDps.
        */
        'nacional' => [
            'driver' => 'nacional',

            // 1/"producao" ou 2/"homologacao" (produção restrita). NotaServico::teste
            // força a produção restrita na emissão, qualquer que seja este valor.
            'ambiente' => env('NFSE_NACIONAL_AMBIENTE', 'producao'),

            // Certificado A1: o conteúdo (bytes do .pfx ou Base64), que uma aplicação
            // multi-tenant guarda por cliente, ou o caminho do arquivo no servidor. São
            // chaves separadas: o conteúdo nunca é lido como caminho.
            'certificado'         => env('NFSE_NACIONAL_CERTIFICADO'),
            'certificado_arquivo' => env('NFSE_NACIONAL_CERTIFICADO_ARQUIVO'),
            'certificado_senha' => env('NFSE_NACIONAL_CERTIFICADO_SENHA'),

            // Prestador: CPF/CNPJ (o do certificado) e o IBGE (7) do município emissor.
            'cpf_cnpj'    => env('NFSE_NACIONAL_CNPJ'),
            'codigo_ibge' => env('NFSE_NACIONAL_CODIGO_IBGE'),

            // A IM só vai na DPS quando o município a registrou no cadastro nacional;
            // fora disso a Sefin rejeita com E0120. Por isso desligado por padrão.
            'inscricao_municipal'          => env('NFSE_NACIONAL_INSCRICAO_MUNICIPAL'),
            'informar_inscricao_municipal' => (bool) env('NFSE_NACIONAL_INFORMAR_IM', false),

            // Regime do emitente (prest/regTrib):
            //  simples_nacional: 1 não optante, 2 MEI, 3 ME/EPP;
            //  regime_apuracao_simples (só ME/EPP): 1 tudo pelo SN, 2 ISS fora, 3 tudo fora;
            //  regime_especial: 0 nenhum, 1 cooperativa, 2 estimativa, 3 ME municipal,
            //  4 notário, 5 autônomo, 6 sociedade de profissionais, 9 outros.
            'simples_nacional'        => env('NFSE_NACIONAL_SIMPLES', 1),
            'regime_apuracao_simples' => env('NFSE_NACIONAL_APURACAO_SN', 1),
            'regime_especial'         => env('NFSE_NACIONAL_REGIME_ESPECIAL', 0),

            // Município de incidência conveniado ao Sistema Nacional: com convênio a
            // Sefin aplica a alíquota parametrizada e rejeita a informada.
            'municipio_conveniado' => (bool) env('NFSE_NACIONAL_CONVENIADO', true),

            // Total aproximado de tributos (Lei 12.741), em %. ME/EPP usa
            // tributos_simples; os demais, os três entes (0,00 quando vazios).
            'tributos_federais'   => env('NFSE_NACIONAL_TRIBUTOS_FEDERAIS'),
            'tributos_estaduais'  => env('NFSE_NACIONAL_TRIBUTOS_ESTADUAIS'),
            'tributos_municipais' => env('NFSE_NACIONAL_TRIBUTOS_MUNICIPAIS'),
            'tributos_simples'    => env('NFSE_NACIONAL_TRIBUTOS_SIMPLES'),

            // NBS padrão (9 dígitos), para itens que não informem codigoNbs.
            'codigo_nbs' => env('NFSE_NACIONAL_CODIGO_NBS'),

            // Sobrescreve as URLs da Sefin (municípios com Sefin própria no padrão).
            'base_url'             => env('NFSE_NACIONAL_URL'),
            'base_url_homologacao' => env('NFSE_NACIONAL_URL_HOMOLOGACAO'),

            'proxy'   => env('NFSE_NACIONAL_PROXY'),
            'timeout' => (int) env('NFSE_NACIONAL_TIMEOUT', 60),
        ],

    ],

];
