# Motor fiscal NFePHP (NF-e + NFS-e nacional) — design

> **Sequenciamento.** Este é o trabalho da **etapa C**, e depende de duas
> etapas anteriores:
>
> - **Etapa A** (concluída, deployada) — campos fiscais em `produtos` +
>   importação de XML que os preenche. Sem NCM/origem/situação tributária
>   por item não há NF-e possível, em nenhum motor.
> - **Etapa B** (concluída, commitada e empurrada pro GitHub em 2026-08-03,
>   deploy ainda pendente) — refactor compartilhado + NF-e via API do
>   Spedy/Focus + correção de 5 defeitos. Spec:
>   `2026-08-02-etapa-b-refactor-nfe-design.md`.
>
> **⚠️ Revisão de 2026-08-03 — o que a Etapa B realmente entregou é
> diferente do que este spec assumia quando foi escrito (rodada 15/16).**
> Por decisão explícita no brainstorming da Etapa B (pra não dobrar o
> escopo), **`EmissaoOrquestrador` e emissão em fila NÃO foram construídos**
> — ficaram adiados pra uma etapa futura que beneficiaria os três
> provedores de uma vez. O usuário confirmou, ao retomar a etapa C, que o
> NFePHP deve ficar **no mesmo nível da Etapa B**: emissão manual, síncrona,
> uma nota de cada vez (Serviço OU Venda) — não o fluxo automático de OS
> mista que a Seção A abaixo descrevia como premissa. As seções B-F
> (numeração, contingência EPEC, PDFs, tratamento de erro, testes)
> continuam válidas como escritas. A Seção A foi reescrita nesta revisão
> pra refletir a infraestrutura real que a Etapa B deixou pronta:
> `NotaFiscalData` com `itens[]` (array simples, não uma classe
> `ItemNotaData`), a tabela `notas_fiscais_itens` (que este spec original
> não sabia que ia existir), e o padrão de dispatch por `$nota->modelo`
> já usado em `FocusNfeProvider`/`SpedyProvider`.

## Contexto

Pedido do usuário, textual: *"eu quero o NFePHP como terceiro motor para
gerar nfe e nfse, porem tanto spedy como o focus precisam tambem emitir nfe
e nfse"*. São duas frentes distintas:

1. **NFePHP como motor novo** (NF-e + NFS-e) — objeto **deste** spec.
2. **NF-e no Spedy e no Focus** — **entregue na Etapa B** (2026-08-03):
   `FocusNfeProvider` já emite NF-e (`POST /v2/nfe`, confirmado contra a doc
   oficial); `SpedyProvider` ainda só emite NFS-e (`/service-invoices`) —
   NF-e real ficou pendente por falta de acesso à doc/sandbox da Spedy,
   com uma guarda de segurança no lugar (`emitir()` rejeita `modelo=NFE`
   com erro claro). **Fora do escopo deste spec** de qualquer forma — já
   foi tratado separadamente.

O motivo do motor NFePHP é ter uma alternativa **gratuita** aos provedores
pagos, configurável por oficina. A interface `FiscalProvider` já existente
comporta isso sem redesenho — o multi-provedor foi construído exatamente
para esse tipo de extensão.

Parecer de viabilidade prévio (rodada 14 do `PROGRESSO.md`) já havia
concluído que o pacote é confiável. A ressalva central de lá continua
valendo e deve estar clara para o usuário: **adotar NFePHP faz o MecânicaPro
virar o próprio emissor perante o fisco.** Hoje Spedy/Focus absorvem
contingência, atualização de schema e suporte a nota rejeitada; com o motor
próprio, isso passa a ser responsabilidade nossa.

## Pesquisa fiscal (2026-07-25) — o que restringe o design

### Reforma Tributária: IBS/CBS

**NF-e/NFC-e — NT 2025.002-RTC, v1.40 (20/05/2026).** Cria o bloco
`IBSCBS` no leiaute, com campos como `cClassTrib` (classificação tributária
da operação) e `cIndOp` (local da operação, para apuração do IBS
municipal), além dos grupos `gALCZFMCBS` e `refDFeAnt`. A regra de
validação **UB12-10_1115** é a que rejeita documento sem esses campos.

Cronograma de obrigatoriedade:

| Regime | Obrigatório a partir de |
|---|---|
| Regime Normal (CRT=3 — Lucro Real/Presumido) | **03/08/2026** |
| Simples Nacional (CRT=1) e MEI | **04/01/2027** |

A NT declara explicitamente que as orientações de preenchimento para CRT=1
sairão em **NT futura ainda não publicada**.

**NFS-e nacional.** O bloco `IBSCBS` entrou na DPS via NT SE/CGNFS-e
nº 007/2026 (07/02/2026). Em 2026 o preenchimento é **opcional** — porém,
se preenchido, todas as regras de validação da NT 04 v2.0 passam a ser
aplicadas. Obrigatoriedade real em 2027.

**Consequência de design [decisão]:** a v1 **prepara a estrutura mas não
preenche**. `NotaFiscalData` carrega os campos de IBS/CBS e o CRT do
emitente; o `NfePhpProvider` só emite o bloco `IBSCBS` quando **CRT=3**.
Emitente no Simples Nacional emite sem o bloco — que é o que a lei permite
até 04/01/2027. Preencher hoje para CRT=1 seria adivinhar regras que não
existem oficialmente, e no caso da NFS-e o preenchimento parcial **aciona
todas as validações da NT 04 v2.0**, aumentando a chance de rejeição em
vez de reduzir.

### Estado das bibliotecas

- **`nfephp-org/sped-nfe` v5.2.6 (15/06/2026)** — já contempla a NT
  2025.002; a issue #1274 ("publicar versão com suporte a IBS/CBS/IS",
  aberta 13/11/2025) está fechada. **Ressalva:** o changelog menciona até a
  **v1.20** da NT, e a NT já está na **v1.40** — pode haver defasagem de
  campos das versões 1.30/1.40. Impacto baixo na v1 (emitente Simples
  Nacional, dispensado até 2027); **reavaliar antes de atender qualquer
  oficina em Regime Normal.**
- **`nfse-nacional/nfse-php`** — biblioteca de terceiros (não é do
  nfephp-org), MIT, ativa. API: `NfseContext` (ambiente + caminho e senha
  do certificado) → `Nfse` → `contribuinte()->emitir($dps)` /
  `consultar($chave)` / `registrarEvento($chave, $xmlEvento)`.
  **Ponto mais frágil da stack:** não há confirmação de suporte ao bloco
  `IBSCBS` / NT 007/2026, e os mantenedores têm discussão aberta de
  reescrita de arquitetura. Como a v1 não preenche IBS/CBS, isso não
  bloqueia agora — mas é o item a monitorar antes de 2027.
- **EPEC confirmado no `sped-nfe`** (`docs/Contingency.md`).
- `nfephp-org/nfephp` (pacote original) está **deprecated** — não usar.

### Prazo de 01/09/2026 (Res. CGSN nº 189/2026) — avaliado e descartado
como bloqueante

A Resolução torna obrigatório o uso do Emissor Nacional da NFS-e para ME/EPP
do Simples Nacional que prestam serviço sujeito a ISS, a partir de
01/09/2026. Cogitou-se priorizar a NFS-e por causa desse prazo.

**Descartado**, porque o prazo cobra que a oficina emita NFS-e pelo padrão
nacional — coisa que ela **já faz hoje via Spedy/Focus**. O motor NFePHP é
adicional e gratuito, não o caminho de conformidade; atraso aqui não deixa
ninguém irregular. Separar as entregas só geraria retrabalho (OS mista
incompleta e EPEC adiado por acidente de sequenciamento). **Escopo da v1
mantido: NF-e + NFS-e juntas, com contingência.**

### ISS

O padrão nacional uniformiza o formato e o fluxo da nota, **não a
tributação**: a alíquota de ISS continua municipal (2%–5%, LC 116/2003).

## Escopo

### Na v1

- NF-e **modelo 55** via `sped-nfe`, contra a SEFAZ-**MG** apenas.
- NFS-e **padrão nacional (ADN)** via `nfse-nacional/nfse-php`.
- Contingência **EPEC** para NF-e, com reconciliação posterior (via
  comando agendado, não fila — ver Seção A revisada).
- Emissão continua **síncrona** dentro da requisição, no mesmo padrão que
  Spedy/Focus já usam hoje (nenhum comportamento novo pro frontend).
- PDFs (**DANFE** e **DANFSe**) via **DomPDF**.
- Emissão manual, uma nota por vez — mesma UX da Etapa B (o usuário escolhe
  Serviço OU Venda no formulário; sem vínculo automático com OS).
- Estrutura de IBS/CBS presente, **emitida só para CRT=3**.

### Fora da v1

- **NFC-e (modelo 65)** — decisão do usuário.
- **Multi-UF** — só MG. A oficina real fica em Ilicínea/MG; outras UFs
  quando surgir demanda.
- **NF-e no Spedy/Focus** — já entregue na Etapa B (Focus emitindo; Spedy
  pendente de acesso à doc/sandbox real).
- **Preenchimento de IBS/CBS para CRT=1** — depende de NT não publicada.
- **`EmissaoOrquestrador` de OS mista e emissão em fila** — adiados
  explicitamente na Etapa B pra não dobrar escopo; decisão confirmada de
  novo ao retomar a Etapa C (2026-08-03). Ficam pra uma etapa futura que
  beneficia os três provedores (Spedy, Focus e NFePHP) de uma vez.

### Premissas a confirmar fora do código

1. **Adesão de Ilicínea ao ADN.** O município usa hoje
   `ilicinea-mg.prefeituramoderna.com.br`. Se não aderiu ao padrão
   nacional, a `nfse-nacional/nfse-php` não tem com quem falar. A Res. CGSN
   189/2026 tende a resolver. Verificar em `nfse.gov.br` ou com o contador.
   **Não bloqueia o design; bloqueia a validação em homologação.**
2. **Alíquota de ISS de Ilicínea.** Não confirmada (só o intervalo legal
   2%–5%). Confirmar com a Secretaria de Finanças ou o contador **antes de
   codificar qualquer valor**. O campo já é configurável
   (`configuracoes.aliquota_iss`), então isso é dado, não código.

## A) Arquitetura

### O problema central: a interface é orientada a SaaS

`FiscalProvider` pressupõe um provedor externo: `registrarEmissor()` cria a
empresa lá e devolve id + token; `enviarCertificado()` faz upload do `.pfx`.
NFePHP não tem nada disso — **nós viramos o emissor**, o certificado nunca
sai daqui e a assinatura ocorre no nosso processo.

**[decisão] Não alterar a interface.** Mudá-la quebraria `SpedyProvider` e
`FocusNfeProvider` sem ganho. Em vez disso, o `NfePhpProvider` reinterpreta
os dois métodos como **validação local de prontidão** — semântica que é
honesta para os três provedores ("preparar o emissor para este provedor"):

- `registrarEmissor()` → valida que `Configuracao` tem CNPJ, IE, IM, CNAE,
  código IBGE e regime tributário preenchidos. Retorna
  `RegistroResultado::ok()` sem token. O `RegistrarEmissorService` segue
  funcionando **sem alteração**, e o botão "Ativar emissão" passa a ter
  significado real: falha na configuração, não na primeira nota.
- `enviarCertificado()` → abre o `.pfx` com a senha, confere que o CNPJ do
  certificado bate com o da empresa e que não está vencido (reusa o
  `CertificadoValidator` existente). Não envia nada a lugar nenhum.

### Componentes novos

| Componente | Responsabilidade |
|---|---|
| `Services/Fiscal/Providers/NfePhpProvider` | Implementa `FiscalProvider`; `emitir()` ramifica por `$nota->modelo` (`NFE`→`MotorNfe`, `NFSE`→`MotorNfse`), mesmo padrão de `FocusNfeProvider`/`SpedyProvider` da Etapa B |
| `Services/Fiscal/NfePhp/MotorNfe` | Monta, assina e transmite NF-e via `sped-nfe`, síncrono dentro da própria chamada de `emitir()` |
| `Services/Fiscal/NfePhp/MotorNfse` | Monta e transmite DPS via `nfse-nacional/nfse-php`, síncrono |
| `Services/Fiscal/NfePhp/CertificadoStore` | Devolve `[pfx, senha]` decifrados do tenant atual, em memória |
| `Services/Fiscal/NfePhp/ContingenciaEpec` | Detecção de SEFAZ fora, geração e transmissão do EPEC — chamado de dentro do próprio `MotorNfe::emitir()` quando a transmissão normal falha por timeout/conexão, não por um worker separado |
| `Services/Fiscal/Pdf/DanfeRenderer` | XML da NF-e → HTML → DomPDF |
| `Services/Fiscal/Pdf/DanfseRenderer` | XML da NFS-e → HTML → DomPDF |
| `Console/Commands/ReconciliarContingencia` | Comando agendado (não job de fila) que retransmite notas em EPEC de hora em hora e alerta antes dos 7 dias — usa o mesmo agendador (`Schedule::command()` + `->timezone('America/Sao_Paulo')`) já corrigido na rodada 13 |

**Removidos desta revisão** (existiam no spec original, dependiam de
`EmissaoOrquestrador`/fila que não foram construídos): `EmissaoOrquestrador`
e `Jobs/EmitirNotaFiscalJob`. Não fazem parte do escopo da Etapa C.

`CertificadoStore` existe porque a decifragem do `.pfx` está hoje num método
`private` do `RegistrarEmissorService`, usado uma única vez no registro.
NFePHP precisa do certificado **a cada emissão** — a lógica sai de lá para
um lugar só, sem duplicar. O `.pfx` **nunca é escrito em disco**: o
`Certificate::readPfx()` do `sped-nfe` aceita o binário em memória.

`MotorNfe` e `MotorNfse` existem como classes separadas (em vez de código
dentro do provider) porque são a costura com as bibliotecas externas. Atrás
de interfaces finas, podem ser substituídos por fakes — sem isso, testar
qualquer coisa exigiria SEFAZ de verdade.

### `NotaFiscalData` — já é o envelope certo, não precisa de nova cirurgia

**Revisão de 2026-08-03:** a Etapa B já transformou `NotaFiscalData` no
envelope que este spec pedia, só que mais simples do que a versão
hipotética descrita aqui originalmente. O formato real, já em produção:

```
NotaFiscalData (já existe, aditivo desde a Etapa B)
  tipo                     'NFSE' (mantido, não usado quando modelo=NFE)
  tomador                  array
  modelo                   'NFE' | 'NFSE'
  itens                    array<array{
    produto_id, descricao, ncm, cfop, origem,
    tributacao_icms, cst_csosn, quantidade, valor_unitario,
  }>
  ... (demais campos da Etapa A/B preservados)
```

**NFePHP reusa esse formato tal como está — não precisa de `ItemNotaData`
como classe nem de alteração no DTO.** Os itens de NF-e já persistem em
`notas_fiscais_itens` (tabela nova da Etapa B) com exatamente os campos que
`sped-nfe` precisa (NCM, CFOP, origem, CST/CSOSN). Pra NFS-e, o motor
NFePHP usa os mesmos campos flat que Spedy/Focus já usam (`valorServicos`,
`descricao`, `codigoServicoMunicipal`) — não há itemização de serviço nesta
etapa, mesma decisão que a Etapa B tomou.

**CRT não é um campo novo do DTO.** Deriva-se de `Configuracao.regime_tributario`
no momento de montar o payload, com o mesmo padrão de mapeamento por string
que `TributacaoIcmsSaidaResolver` já usa (`'Simples Nacional'` → CRT 1,
`'Lucro Presumido'`/`'Lucro Real'` → CRT 3) — não precisa de coluna nova
nem de mais um campo passado pelo `NotaFiscalData`.

**`emitente` também não precisa entrar no DTO.** Ao contrário de Spedy/Focus
(que registram a empresa uma vez e emitem contra um id remoto), o NFePHP
monta o XML no próprio processo — `MotorNfe`/`MotorNfse` consultam
`Configuracao::first()` diretamente ao montar o payload, no mesmo padrão que
`NfeService::montarNotaData()` já usa pra montar o `tomador` a partir do
`Cliente`. Fica de fora do DTO porque nenhum outro provedor precisa disso.

**IBS/CBS continua precisando de dado novo** (`cClassTrib`, `cIndOp` etc.) —
essa parte do spec original permanece válida, só que não é um bloco do
`NotaFiscalData`: é calculado dentro do `MotorNfe`, condicionado ao CRT
derivado acima, e só entra no XML quando `CRT === 3`.

### Emissão continua síncrona — sem fila nesta etapa

Removido desta revisão: a ideia de enfileirar Spedy/Focus/NFePHP via
`EmitirNotaFiscalJob`. A Etapa B manteve emissão síncrona por decisão
explícita (sem orquestrador de OS mista, não há pressão real por fila
ainda), e a Etapa C mantém o mesmo nível — `NfePhpProvider::emitir()` roda
dentro da própria requisição, igual a `FocusNfeProvider`/`SpedyProvider`.

Isso **não compromete o EPEC**: a tentativa de transmissão normal e a
queda pra contingência acontecem na mesma chamada síncrona (a chamada
`sefazEPEC()` do `sped-nfe` é só mais uma chamada de rede, não precisa de
worker). O que exige agendamento é só a **reconciliação posterior** (Seção
C) — e isso já é resolvido por um comando agendado (`Console\Commands\
ReconciliarContingencia`), não por fila.

Fila (Horizon) fica para uma etapa futura, se o `EmissaoOrquestrador` (OS
mista automática) vier a ser construído — nesse ponto, sim, várias notas
disparando de uma ação só justificam enfileirar, pros três provedores de
uma vez.

### OS mista — fora de escopo, mesma decisão da Etapa B

O usuário confirmou (2026-08-03), ao retomar a Etapa C, que o
`EmissaoOrquestrador` (separar `os_itens` por tipo e criar NF-e + NFS-e
automaticamente numa ação só) fica fora desta etapa — mesma decisão
tomada na Etapa B, agora reafirmada. O NFePHP emite uma nota por vez
(Serviço OU Venda), pelo mesmo formulário manual que a Etapa B já entregou;
o usuário escolhe o provedor (Spedy/Focus/NFePHP) na configuração da
oficina, não por nota.

### Registro do provedor

`FiscalProviderManager::PROVEDORES` é hoje `['SPEDY', 'FOCUS']` e funciona
como allowlist: `resolverProvedor()` descarta qualquer valor fora dela e cai
no default `'SPEDY'`. Passa a `['SPEDY', 'FOCUS', 'NFEPHP']`, com o novo
ramo em `build()`. Sem isso, gravar `oficinas.provedor_fiscal = 'NFEPHP'`
seria silenciosamente ignorado — falha difícil de diagnosticar, porque a
oficina continuaria emitindo normalmente, só que pelo provedor errado.

O `build()` do NFePHP não recebe `baseUrl`/`masterKey` (não há serviço
externo). Recebe o ambiente e resolve o resto via `CertificadoStore` e
`Configuracao`.

### Mudanças de schema (consolidado)

**Já existem, graças à Etapa B — não recriar:** `notas_fiscais.modelo`
(`'NF-e'`/`'NFS-e'`, reusada tal como está) e a tabela
`notas_fiscais_itens` (`ncm`, `cfop`, `origem`, `tributacao_icms`,
`cst_csosn`, `quantidade`, `valor_unitario` — exatamente os campos que
`sped-nfe` precisa pro item de NF-e).

**`configuracoes`**

```
serie_nfe            varchar(3)  default '1'
proximo_numero_nfe   integer     default 1
```

**`notas_fiscais`**

```
contingencia_desde   timestamptz nullable
```

**Novos valores de `notas_fiscais.status`** — hoje o conjunto é
`RASCUNHO | PROCESSANDO | AUTORIZADA | CANCELADA | REJEITADA`. Somam-se:

- **`CONTINGENCIA`** — EPEC autorizado, aguardando retransmissão do XML
  normal. Estado válido: o DANFE pode ser impresso.
- **`ERRO`** — falha nossa ou de infraestrutura (job morreu, certificado
  ilegível, exceção inesperada). **Distinto de `REJEITADA`**, que significa
  que o fisco recebeu e recusou por regra de negócio. A diferença é
  operacional: `REJEITADA` pede correção de dados pelo usuário e mostra o
  `cStat`; `ERRO` pede retentativa ou investigação técnica.

O frontend precisa tratar os dois estados novos nos pills de status da tela
de histórico fiscal.

## B) Numeração da NF-e

NF-e precisa de série e sequencial próprios, separados do
`proximo_numero_nf` que hoje serve à NFS-e. Entram em `configuracoes`:

```
serie_nfe            varchar(3)  default '1'
proximo_numero_nfe   integer     default 1
```

Mesmo `lockForUpdate()` já usado em `NfeService::proximoNumeroNf()`.

**Buraco na numeração da NF-e exige justificativa ao fisco** (inutilização
de faixa). Duas medidas:

1. **[decisão] O número é alocado dentro de `MotorNfe::emitir()`,
   imediatamente antes de transmitir** — não quando o usuário clica em
   emitir. Mesmo lugar que já aloca `proximo_numero_nf` hoje pra NFS-e
   (dentro da chamada síncrona), só que na tabela de série própria da
   NF-e. Encurta ao máximo a janela em que um número está reservado sem
   nota.
2. **Nota rejeitada não queima o número**: como a SEFAZ não autorizou, a
   retentativa reusa o mesmo `nNF`.

Buracos ainda ocorrem (queda de processo entre alocar e transmitir). Por
isso a v1 inclui uma ação mínima de administração usando `sefazInutiliza()`
— um endpoint e um botão. Sem isso a oficina fica travada dependendo do
contador; é barato demais para ficar de fora.

## C) Contingência EPEC

**Detecção [decisão]:** tenta a transmissão normal primeiro e cai para
contingência apenas quando a comunicação falha (timeout ou erro de conexão).
Não consultar `sefazStatus()` antes de cada nota — dobraria a latência de
toda emissão, e o próprio serviço de status cai junto quando a SEFAZ cai.

**Fluxo:** monta a NF-e já com `tpEmis=4`, `dhCont` (momento da detecção) e
`xJust`; chama `$tools->sefazEPEC($xml, $verAplic)`, que vai ao ambiente
nacional, não à SEFAZ-MG. Evento autorizado → nota entra no status novo
`CONTINGENCIA`, e o DANFE já pode ser impresso (documento válido nesse
estado, com a indicação de contingência).

**A regra dos 7 dias é o principal requisito não-óbvio.** Se o XML normal
não for transmitido em até 7 dias, a SEFAZ bloqueia novos EPEC por
"Pendência de Conciliação" — uma nota esquecida em contingência derruba a
contingência inteira da oficina depois. Portanto:

- comando agendado `nfe:reconciliar-contingencia`, de hora em hora,
  retransmitindo tudo que está em `CONTINGENCIA`;
- alerta ao admin da oficina quando faltarem 2 dias, pelos canais que já
  existem (e-mail e WhatsApp da rodada 10);
- coluna `contingencia_desde timestamptz` em `notas_fiscais` para calcular
  o prazo.

Isso depende do agendador consertado na rodada 13 — antes daquele fix,
`Schedule::command()` nunca disparava em produção, e a falha só apareceria
quando a contingência travasse. O agendamento deve usar
`->timezone('America/Sao_Paulo')`, como os três comandos existentes.

**A confirmar em homologação, não assumir:** pelo MOC, a nota emitida em
EPEC é retransmitida depois **mantendo `tpEmis=4`** — a chave de acesso
codifica o tipo de emissão, então alterá-lo invalidaria a chave já impressa
no DANFE. Confiança razoável, mas é exatamente o tipo de detalhe que só o
teste real confirma.

## D) PDFs

`DanfeRenderer` e `DanfseRenderer` produzem HTML e passam ao DomPDF,
seguindo o padrão já usado em OS e relatórios.

**[decisão] Não armazenar PDF.** O XML é a fonte da verdade e o PDF é
renderizado sob demanda em `GET /notas-fiscais/{id}/pdf`. Evita arquivo
desatualizado quando a nota muda de estado (autorizada → cancelada) e evita
crescimento de storage.

No frontend, o download usa `window.location.origin` — lição da rodada 11:
`NEXT_PUBLIC_API_URL` é gravada em build time com um domínio fixo e quebra
por CORS em qualquer oficina que acesse pelo próprio subdomínio.

**Custo registrado:** o DANFE tem layout legalmente especificado (Anexo II
do MOC), incluindo código de barras Code-128C da chave de 44 dígitos —
exige um gerador de barcode embutível e validação visual contra um DANFE
real. É a maior fatia de trabalho evitável do plano, já que a `sped-da` faz
isso pronta. O usuário optou por DomPDF (consistência com o resto do
projeto) com o custo visível.

## E) Tratamento de erro

| Falha | Quando aparece | Resposta |
|---|---|---|
| Configuração incompleta | Antes de emitir | Bloqueia em "Ativar emissão", não na nota |
| Certificado vencido/inválido | Ao abrir o `.pfx` | Bloqueia + alerta ao admin; nunca vira rejeição de nota |
| Payload inválido (schema) | Validação local do `sped-nfe` | `REJEITADA`, **sem retentativa** — repetir o mesmo XML dá o mesmo erro |
| Rejeição da SEFAZ (`cStat`) | Regra de negócio do fisco | `REJEITADA` com `cStat` + motivo literal na tela; correção é humana |
| Falha de comunicação | Timeout/conexão | NF-e → EPEC. NFS-e → retentativa com backoff (EPEC não existe lá) |

**O ponto mais delicado é a retentativa.** Timeout na transmissão não
significa que a nota não chegou — a SEFAZ pode ter autorizado e a resposta
é que se perdeu. Retransmitir cego gera nota duplicada com número queimado.

**[decisão]** Antes de qualquer retentativa de NF-e — seja um reenvio manual
pelo usuário, seja a reconciliação agendada de contingência (Seção C) —
`MotorNfe` consulta `sefazConsultaChave` com a chave já calculada: se já
está autorizada, apenas concilia o resultado localmente.

É a mesma lição das rodadas 7 e 8 do fluxo de pagamento — ack perdido não
significa que o efeito não aconteceu, e a correção certa é consultar o
estado real na origem em vez de confiar no que voltou (ou não voltou) pela
rede.

Sem fila, não há `failed()` de job — o equivalente é `NfePhpProvider::emitir()`
capturar qualquer exceção não prevista (certificado corrompido no meio da
assinatura, erro de biblioteca) e devolver `EmissaoResultado` com um status
que o controller mapeia pra `ERRO` (nunca deixando a nota presa em
`PROCESSANDO` silenciosamente), no mesmo padrão que `NotaFiscalController::emitir()`
já usa hoje pra capturar exceção e marcar a nota como `REJEITADA` — só que
`ERRO` é um status novo, distinto, porque a causa é técnica, não uma
recusa do fisco.

## F) Estratégia de teste

### Unitários (única camada executável nesta máquina)

- montagem do payload de NF-e e de NFS-e, no espírito do
  `RegistrarEmissorMontagemTest` existente;
- mapeamento de `cStat` → status interno;
- derivação de CRT a partir de `regime_tributario` (mesmo padrão de
  `TributacaoIcmsSaidaResolverTest`, incluindo o caso não coberto lançando
  exceção, nunca um CRT chutado);
- alocação de número sob concorrência, e reuso do número após rejeição;
- **gating de IBS/CBS pelo CRT**: bloco ausente com CRT=1, presente com
  CRT=3 — é o teste que protege a decisão de escopo deste spec;
- cálculo do prazo de 7 dias da contingência, incluindo a borda do alerta.

### Feature

Escritos, mas **não executáveis localmente** — não há Postgres nesta
máquina (limitação já documentada no projeto). Dado o precedente da rodada
12 (bug de throttle do Carbon 3 que passou por todas as revisões
individuais e só apareceu na revisão final de branch), **recomenda-se rodar
esses testes num banco dedicado ou CI antes de considerar a rodada
validada**. Nunca em produção: `RefreshDatabase` dropa o banco.

### Homologação (onde está a validação real)

`configuracoes.ambiente_fiscal` já distingue `HOMOLOGACAO`/`PRODUCAO`.
Checklist:

1. NF-e autorizada, cancelada e faixa inutilizada;
2. EPEC forçado apontando para endpoint inalcançável, com a reconciliação
   posterior fechando o ciclo;
3. DANFE comparado visualmente com um DANFE real;
4. NFS-e autorizada no ADN de homologação;
5. DANFSe conferido.

Nada vai para produção antes de passar por aqui.

## Riscos conhecidos

1. **`nfse-nacional/nfse-php` sem suporte confirmado a IBS/CBS** e com
   reescrita de arquitetura anunciada. Não bloqueia a v1 (não preenchemos o
   bloco), mas é o item a monitorar antes de 2027.
2. **`sped-nfe` documenta até a NT v1.20; a NT está na v1.40.** Sem impacto
   para CRT=1; reavaliar antes de atender oficina em Regime Normal.
3. **Adesão de Ilicínea ao ADN não confirmada** — bloqueia a validação em
   homologação da parte NFS-e, não o desenvolvimento.
4. **Layout do DANFE em DomPDF** é a maior incerteza de esforço do plano.
5. **Virar o próprio emissor** transfere para nós a responsabilidade por
   contingência, atualização de schema e nota rejeitada — hoje absorvida
   pelos provedores pagos. É a razão de o NFePHP ser motor **opcional por
   oficina**, nunca substituto obrigatório de Spedy/Focus.
