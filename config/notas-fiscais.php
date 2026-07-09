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

    ],

];
