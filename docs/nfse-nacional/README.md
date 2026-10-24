# Sistema Nacional NFS-e: documentação oficial

Cópia da documentação técnica publicada em
<https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/documentacao-atual>, baixada em
2026-10-23. É a base do driver `nacional`.

| Arquivo | O que é |
|---|---|
| `schemas/` | XSD v1.01 (DPS, NFS-e, pedido de evento). Usados nos testes para validar o XML gerado. |
| `anexo_i-…-dps_nfse-….xlsx` | Leiaute e regras de negócio da DPS e da NFS-e (códigos E0xxx). |
| `anexo_ii-…-pedregevt_evt-….xlsx` | Leiaute e regras dos eventos (cancelamento e101101). |
| `manual-contribuintes-emissor-publico-api-….pdf` | Endpoints da API da Sefin Nacional. |
| `manual-contribuintes-apis-adn-….pdf` | Endpoints do ADN (distribuição e eventos). |
| `nt-008-se-cgnfse-danfse-20260714-v1-02.pdf` | Especificação do DANFSe v2.0 (Anexo I é o modelo seguido por `Pdf\Danfse`). |

O Swagger das APIs (`sefin.nfse.gov.br/SefinNacional/docs`) só abre com certificado
ICP-Brasil (mTLS); sem ele responde 403.

Atenção ao `TSSerieDPS` em `tiposSimples_v1.01.xsd`: o pattern `^0{0,4}\d{1,5}$` usa âncoras,
que em regex de XSD são caracteres literais. O libxml segue a norma e recusa qualquer série; a
Sefin aceita `1`. Os testes validam contra uma cópia sem as âncoras.
