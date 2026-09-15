# Backlog de Execução

> Lista viva. Cada item vira spec+plano próprio (ou fica "bounded" se for
> pequeno) antes de codar, seguindo `superpowers:brainstorming`. Ordem
> escolhida por risco/dependência crescente, não pela ordem em que foi pedida.

## ✅ CONCLUÍDA 2026-09-14 — FALHA DE SEGURANÇA GRAVE (autorizada e corrigida nesta rodada)

**Token de autenticação armazenado de forma insegura no navegador.**
Achado na auditoria completa do sistema (ver `PROGRESSO.md` seção 14).
Usuário confirmou que é grave e pediu pra registrar, mas **não** autorizou
a correção ainda — aguardar pedido explícito antes de mexer.

- `frontend/hooks/useAuth.ts`: `login()` guarda o token em
  `localStorage.setItem('auth_token', ...)` e também via
  `document.cookie = 'auth_token=...; SameSite=Lax'` (um cookie setado
  assim NUNCA pode ter `HttpOnly` — só o servidor consegue definir essa
  flag via header `Set-Cookie`). O mesmo padrão existe pro token de SaaS
  Admin (`lib/saas-api.ts`, `app/saas-admin/login/page.tsx`) — ainda mais
  privilegiado (acesso a todas as oficinas da plataforma).
- Contradiz a regra já escrita no `CLAUDE.md` do próprio projeto:
  "Cookies httpOnly para tokens (nunca localStorage)".
- **Risco:** qualquer XSS futuro (uma dependência comprometida, um campo
  sem escaping em algum ponto ainda não descoberto) rouba a sessão inteira
  — `localStorage`/`document.cookie` são igualmente legíveis por
  JavaScript malicioso.
- **Correção correta (não pontual):** migrar login pro fluxo real de
  Sanctum SPA — cookie de sessão `HttpOnly`/`Secure`/`SameSite` emitido
  pelo BACKEND via `Set-Cookie` na resposta de login, nunca um token no
  corpo da resposta guardado pelo cliente. Exige mudar:
  1. Backend: endpoint de login parar de devolver o token no JSON, emitir
     o cookie httpOnly em vez disso (Sanctum já suporta esse modo —
     `EnsureFrontendRequestsAreStateful` + cookie de sessão em vez de
     `personal_access_tokens` Bearer).
  2. Frontend: remover os `localStorage.setItem/getItem` e o
     `document.cookie` manual de `useAuth.ts` e `saas-api.ts`; `lib/api.ts`
     já manda `withCredentials: true`, então a mudança é sobretudo parar
     de gerenciar o token manualmente e depender do cookie automático.
  3. Ambos os fluxos de auth (oficina normal via `useAuth` e SaaS Admin via
     `saas-api`) precisam do mesmo tratamento.
**✅ Corrigido 2026-09-14** (usuário autorizou explicitamente: "Sim, corrigir
agora"). Migrado pro fluxo real de Sanctum SPA (commit `298dd50`):
sessão httpOnly (`Auth::guard('web'|'saas')->login()`), sem token nenhum no
corpo da resposta. Achados durante a implementação:
- `config('auth.guards.saas')` precisou virar `driver: session` PRÓPRIO em
  vez de reusar o guard `sanctum` (que compartilha `config('sanctum.guard')`
  entre TODOS os guards da app sem filtrar por provider na checagem de
  sessão — um Usuario logado em 'web' teria autenticado em `auth:saas`).
- 8 chamadas `fetch()` cruas (download de PDF/XML/ZIP/relatórios) liam o
  token manualmente pra montar `Authorization: Bearer` — todas corrigidas
  pra `credentials: 'include'`.
- `handleLogout()` do SaaS Admin nunca chamava o backend.
- **Bug de infra real achado ao vivo, corrigido em commit separado
  (`5182bf6`):** nginx só escuta HTTP puro (TLS termina no Traefik) —
  `proxy_set_header X-Forwarded-Proto $scheme;` sobrescrevia o valor CORRETO
  que o Traefik mandava, fazendo o Laravel achar que toda requisição HTTPS
  era HTTP e anexar `:443` no host resolvido — quebrando a sessão stateful
  em QUALQUER domínio exceto o hardcoded em `SANCTUM_STATEFUL_DOMAINS`
  (`oficina.dlsistemas.com.br`). Sem esse fix, login funcionaria só no
  domínio principal, nunca em `saas.dlsistemas.com.br` nem nos subdomínios
  de tenant (`stuntmotos.dlsistemas.com.br` etc.) — encontrado via rota de
  diagnóstico temporária, removida depois de confirmar a causa raiz.
- Verificado ao vivo em produção com contas descartáveis (criadas e
  apagadas): login → cookie httpOnly → `/auth/me` autenticado → logout →
  sessão de verdade invalidada — nos 3 domínios reais (oficina, saas,
  stuntmotos como subdomínio de tenant).

## ✅ CONCLUÍDA 2026-09-14 — primeira emissão real via NFePHP/NFS-e, autorizada de verdade

Era a pausa registrada aqui (usuário liberou o deploy e pediu pra continuar
testando). 7 bugs reais de schema/regra de negócio corrigidos em sequência
(commits `967b9c6`, `2bf1748`, `a7e03c2`, `6a16ef0`, `ab516c8`, `7d7ee5a`,
`e49c6e0`), todos deployados e testados ao vivo contra o ambiente de
homologação oficial do governo. Resultado final confirmado:
```
status=AUTORIZADA
chave=NFS31305072250388509000121000000000000126093928413131
numero=1
```
Detalhe completo dos 7 bugs em `PROGRESSO.md` seção 12. O último (nº 7) era
um bug real na própria biblioteca vendor (`nfse-nacional/nfse-php`,
`DpsXmlBuilder.php`), contornado sem editar o vendor.

**Sobra conhecida, não urgente:** o código LC116 "14.01" fixo em todo o
sistema (nunca configurável por serviço) é usado como `cTribNac`
implicitamente correto só porque este sistema SÓ atende oficina mecânica —
se um dia outro tipo de serviço for adicionado,
`CodigoTributacaoNacionalResolver::MAPA` precisa ganhar a entrada
correspondente (lança exceção clara em vez de emitir algo errado, então é
seguro, só não é automático).

## ✅ CORRIGIDO 2026-09-15 (Rodada 45) — payload de NFC-e via Spedy usava nomes de campo inexistentes

`montarPayloadNfce()` era um payload INFERIDO por analogia, nunca validado
contra a doc real (o próprio comentário do código admitia isso). Achado
via WebFetch em docs.spedy.com.br: `productCode`/`commercialUnit`/
`unitValue`/`grossValue`/`icmsOrigin`/`icmsTaxSituation`/
`receiver.individualTaxNumber`/`payments[].value`/`method:'cash'` — **nenhum
desses campos existe no schema real**. Reescrito com os nomes corretos
(`code`/`unit`/`unitAmount`/`totalAmount`/`taxes.icms.origin+cst|csosn`/
`receiver.federalTaxNumber`/`payments[].amount`/`mapFormaPagamento()`) +
grupo PIS/COFINS adicionado (mesmo padrão CST 49 zerado da NF-e).

**Confirmado ao vivo em homologação**: payload antigo rejeitava IMEDIATO
(erro de deserialização do campo `method`); payload novo é aceito
(`enqueued`) e só é rejeitado depois por um motivo genuinamente fiscal —
**falta de CSC/TokenId da NFC-e** (credencial que a oficina precisa obter
na SEFAZ do próprio estado). Ver `PROGRESSO.md` Rodada 45.

**Novo bloqueio real, não solucionável só por código:** CSC — mesma classe
de exigência já documentada pro motor NFePHP (ver "sobras" abaixo), agora
confirmada também pro caminho Spedy. A Spedy tem `PUT
/v1/companies/{id}/settings` (bloco `consumerInvoice`, campos
`tokenId`/`csc`) pra configurar isso, mas a estrutura exata não está
documentada em detalhe e não foi implementada — nenhuma oficina tem CSC
real pra testar contra ainda.

## ✅ CONCLUÍDA 2026-09-14 — "erro ao conciliar nota" de entrada (NÃO era o bloqueio antigo da Spedy)

Usuário reportou erro ao tentar conciliar uma nota de entrada. Causa raiz
real (ver `PROGRESSO.md` seção 17): `NfePhpProvider::consultarNotaRecebida()`/
`listarNotasRecebidas()` usavam o `ambiente_fiscal` de EMISSÃO da oficina
(HOMOLOGACAO) pra consultar nota de TERCEIRO — mas Distribuição DFe de
homologação nunca tem nota real de fornecedor. Confirmado ao vivo: mesma
chave, mesmo certificado, `cStat=217` em HOMOLOGACAO vs `COMPLETA` em
PRODUÇÃO. **Não é o mesmo bloqueio antigo da Spedy** (empresa já
cadastrada, sem key recuperável — esse continua bloqueado, ver "PRÓXIMA
TAREFA OBRIGATÓRIA" abaixo, e só afeta quem usa Spedy; stuntmotos usa
NFEPHP pra emissão). Fix escopado só a NFePHP — Spedy/Focus não foram
tocados por falta de evidência empírica equivalente (ver PROGRESSO.md pra
o raciocínio completo de por que não estendi o mesmo fix pra eles sem
prova).

## ✅ CONCLUÍDA 2026-09-14 — rodada de 6 pedidos (label NFePHP, download NFS-e, contingência, botão OS, conciliação, excluir nota)

Ver `PROGRESSO.md` seção 15 pro relato completo. Resumo: 5 bugs reais
corrigidos + 1 bloqueio reconfirmado (não solucionável só por código, ver
acima). Commits `a272b77`, `2152d36`, `9667483`, `89dcbe7` — todos
deployados e verificados ao vivo em produção (exceto o teste isolado do
bug de timezone do vendor, provado por teste dedicado em vez de forçar
uma falha real de SOAP sob demanda).

## ✅ CONCLUÍDA 2026-09-14 — reconciliação de status Spedy (era a PRÓXIMA TAREFA OBRIGATÓRIA)

Ver `PROGRESSO.md` Rodada 40 pro relato completo (implementação + investigação do
pedido do usuário sobre "notas aprovadas mas constam rejeitadas" e "aprovada pra
um cliente, não pra outro"). Resumo: as duas perguntas eram o MESMO bug — não
havia diferença real ligada ao cliente. Corrigido `SpedyProvider` (integrationId
no payload + `consultar()` por filtro + falha de consulta nunca mais vira
REJEITADA). 2 commits, 49 testes Unit (era 48), suíte completa sem regressão
(mesmas 10 falhas pré-existentes de sempre).

**✅ Reconciliação manual concluída 2026-09-14** (usuário confirmou): das 10,
**3 estavam AUTORIZADAS de verdade** (o sistema mentia "REJEITADA") e 7 eram
genuinamente rejeitadas por motivos reais. Ver PROGRESSO.md Rodada 40, seção 6
(inclui 2 erros próprios corrigidos na hora: `numero` e `chave_acesso`
sobrescritos incorretamente pro caso das notas mais antigas).

## PRÓXIMA TAREFA OBRIGATÓRIA (registrada 2026-09-14, causa real já descoberta)

**Investigar e corrigir por que a stuntmotos nunca completou o registro de
emissor na Spedy** (`emissores_fiscais.status = 'ERRO'`, `emissorToken`
vazio) — confirmado 2x nesta sessão (investigação principal + um fork
independente), causando emissão/consulta "normal" da stuntmotos sempre
cair na `masterKey` da plataforma (risco de isolamento multi-tenant) e
bloqueando a reconciliação de `NotaEntrada` pendentes (14 notas).

**✅ Causa real descoberta 2026-09-14 (Rodada 40, seção 10)**, depois de
corrigir o bug de extração de mensagem de erro do `SpedyProvider`
(`mensagemErroDe()` — antes só lia uma chave de nível raiz, escondendo
todo erro real): reexecutando `registrarEmissor()` pra stuntmotos, a
mensagem real é **"O CNPJ já possui uma conta vinculada."** — a empresa
já existe do lado da Spedy (provavelmente cadastrada direto pelo painel
deles, fora do nosso fluxo, em algum momento anterior), e
`registrarEmissor()` sempre tenta um `POST /companies` (criar nova),
nunca detecta/reaproveita uma empresa já existente com o mesmo CNPJ.

**Próximo passo (ainda não implementado):** descobrir se a API da Spedy
tem um endpoint de busca de empresa por CNPJ/documento (não confirmado
ainda — precisa WebFetch na doc ou teste empírico) pra buscar a empresa
existente e recuperar/gerar a API key dela, em vez de tentar criar uma
nova. Se não existir tal endpoint, a alternativa é o usuário pegar a API
key direto no painel web da Spedy (fora do nosso sistema) e colar no
cadastro — mais simples, mas manual.

**✅ Confirmado definitivamente 2026-09-14, via doc oficial (`docs.spedy.com.br`,
`listar-empresas.md`):** não existe esse endpoint. `GET /v1/companies`
lista todas as empresas mas não filtra por CNPJ nem devolve a key
completa; `GET /v1/companies/{id}` devolve a key OBFUSCADA (só a criação
original devolve a key real, uma única vez). **Não é solucionável só por
código.** As duas únicas saídas continuam sendo: (a) usuário pegar a API
key direto no painel da Spedy e colar no cadastro, ou (b) excluir+recriar
a empresa lá (ação arriscada, precisa de autorização explícita do usuário
antes de fazer — não fiz).

<details>
<summary>Texto original da tarefa (referência)</summary>

**Corrigir a reconciliação de status de emissão via Spedy — notas ficam
PROCESSANDO pra sempre mesmo quando a Spedy já autorizou.**

Confirmado em homologação (stuntmotos, 2026-09-10): NFS-e emitida ficou
`PROCESSANDO` no nosso banco por dias, mas consultando a API da Spedy
diretamente (`GET /service-invoices/{id}`) o status real já era
`authorized` desde o primeiro segundo.

**Causa raiz confirmada:** ao emitir, a Spedy devolve o `id` dela própria
(ex. `a5522b3f-...`) no corpo da resposta — `SpedyProvider::resultadoDe()`/
`resultadoNfceDe()` **nunca leem esse `id`**. O sistema só guarda a nossa
`referencia_externa` interna (`nf-<uuid>`). Depois, `consultar()` faz
`GET /{recurso}/{referencia_externa}` — 404 sempre, porque a Spedy não
conhece essa referência. Resultado: nenhuma nota emitida via Spedy jamais
sai de PROCESSANDO por reconciliação automática (nem pelo polling do
frontend, nem pelo comando agendado `nfe:reconciliar-processando`).

**Correção proposta (testada empiricamente, não é suposição):**
1. Mandar a nossa `referencia_externa` como `integrationId` no payload de
   criação nos 4 caminhos de emissão da Spedy (`montarPayloadNfse`,
   `montarPayloadNfce`, `montarPayloadNfe`, `montarPayloadOrder`).
2. Trocar `consultar()` pra filtrar por ele:
   `GET /{recurso}?integrationId={referencia}` em vez de
   `GET /{recurso}/{referencia}`, pegando `items[0]`.
   **Confirmado no sandbox real (2026-09-10):** `?integrationId=X` filtra
   de verdade (retornou `totalCount=0` pra um id inexistente, contra uma
   lista não-vazia sem o filtro) — não é suposição, é comportamento
   observado da API.
3. Cobrir com teste (`SpedyProviderTest`) mockando `Http::fake()` pro novo
   formato de consulta.
4. Reconciliar manualmente (ou via comando) as notas já presas em
   PROCESSANDO na stuntmotos depois do deploy da correção.

**Por que não foi feita agora:** usuário pediu explicitamente pra adiar —
limite semanal de uso perto do fim. Ver [[project-roadmap-fiscal-3-etapas]]
na memória (seção Spedy) e `PROGRESSO.md` Rodada 39 pra todo o contexto de
investigação (incluindo o bug irmão, já corrigido: NF-e/NFC-e não mandavam
endereço do destinatário, commit `c1a5728`).

</details>

## Achados da análise Focus/NFePHP (2026-09-10, registrados — não corrigidos ainda)

Usuário pediu análise dos módulos Focus e NFePHP (config + dados enviados),
mas quer adiar qualquer correção pro próximo momento (limite semanal
perto do fim). Achados, por ordem de risco:

1. **✅ CORRIGIDO 2026-09-14 (commit `4fbdd92`) — `NfePhpProvider::emitir()`
   roteava NFC-e pro motor de NFS-e silenciosamente.** Motor `MotorNfce`
   completo (modelo 65) implementado; `NfePhpProvider::emitir()`/
   `consultar()`/`cancelar()` agora despacham `NFCE` pro motor certo, nunca
   caem no `default` (`MotorNfse`). Testado em `NfePhpProviderTest`
   (`emitir`/`consultar`/`cancelar` com `modelo: 'NFCE'`). Este item ficou
   registrado como pendente por engano — a correção já existia no código,
   só não tinha sido refletida aqui (mesma classe de falha de documentação
   já vista antes neste arquivo).

2. **✅ CORRIGIDO 2026-09-15 (Rodada 44) — `codigo_ibge` do destinatário era
   sempre o da PRÓPRIA oficina, nunca o do cliente.** `clientes.codigo_ibge`
   (migration nova) preenchido pelo ViaCEP no cadastro (campo `ibge` da
   resposta, capturado num input oculto no `ClienteForm`);
   `NfeService::montarNotaData()` agora usa `$cliente?->codigo_ibge ?:
   $codigoIbgeTomador` (prefere o do cliente, cai pro da oficina só quando
   o cliente ainda não tem o dado — retrocompat com cadastros antigos). Os
   3 providers (Spedy, Focus, NFePHP) já liam `$tomador['codigo_ibge']`
   esperando o valor do destinatário — não precisaram de mudança, só
   ninguém populava certo. 2 testes novos em `NfeServiceMontagemTest`. Ver
   `PROGRESSO.md` Rodada 44. **Migration ainda não rodada em produção** —
   pendente de deploy.

3. **Focus não tem NENHUMA credencial cadastrada** (`saas_config`:
   `focus_master_token_producao`/`_homologacao` ambos vazios). Não é bug —
   o código de emissão (`FocusNfeProvider`) está bem documentado e com
   endereço do destinatário correto em NFS-e/NF-e (ao contrário do bug
   que existia na Spedy, já corrigido). Mas está 100% não testado contra
   sandbox real por falta de token — se alguém trocar o provedor pra FOCUS
   hoje, toda emissão falha com 401 até cadastrar o token em SaaS Admin.

4. **NFePHP nunca foi "ativado" pra nenhuma oficina** (`emissores_fiscais`
   só tem 1 registro, SPEDY/ERRO). A config da stuntmotos hoje JÁ atende
   aos requisitos de `NfePhpProvider::registrarEmissor()` (CNPJ, IE, IM,
   CNAE, código IBGE, regime tributário e certificado A1 — todos
   presentes), então dá pra testar de verdade quando alguém decidir usar
   NFePHP em vez de Spedy. Nunca emitida uma NF-e/NFS-e real via NFePHP
   contra a SEFAZ/ADN em produção nem homologação.

Ver `PROGRESSO.md` Rodada 39 pro detalhe completo da investigação
(inclusive as consultas diretas à API da Spedy que confirmaram o item 1 da
seção acima "PRÓXIMA TAREFA OBRIGATÓRIA").

## Achados da Rodada 40 (2026-09-14)

Usuário pediu explicitamente pra checar se as mesmas regras/correções valiam
pros outros 2 motores (Focus, NFePHP) e se já estavam corrigidos neles.
Resultado da varredura nos 3:

- **NFePHP (`MotorNfe`/`MotorNfse`) já estava CORRETO nas duas classes de
  bug** — não precisou de fix. (1) Não tem o problema de referência/ID: fala
  direto com a SEFAZ/ambiente nacional usando a chave de acesso + protocolo
  próprios, sem um ID de terceiro pra se perder. (2) Falha ao consultar já
  virava `EmissaoResultado::erro(...)` (nunca `rejeitada(...)`) — corrigido
  antes, na Rodada 37 (`MotorNfse::consultar()`/`resultadoAposVerificarCancelamento()`).
- **✅ `FocusNfeProvider::consultar()` CORRIGIDO nesta rodada** — tinha a
  mesma falha "consulta falhou ⇒ REJEITADA" do `SpedyProvider` (ver
  `PROGRESSO.md` Rodada 40). **Não** tinha o bug de referência/ID: a Focus já
  recebe a nossa `referenciaExterna` como `ref` na própria criação
  (`POST /v2/nfse?ref=...`) e a usa depois como path (`GET /v2/nfse/{ref}`)
  — arquitetura diferente da Spedy, que nunca teve essa concordância. TDD
  (`FocusNfeProviderTest`: 29→30 testes), suíte Unit completa sem regressão
  (298 testes, mesmas 10 falhas pré-existentes).
- **`SpedyProvider::cancelar()` ainda usa `DELETE /{recurso}/{referencia}`
   por path** (não por filtro `integrationId`) — mesma classe de problema do
   `consultar()` antigo: `referencia_externa` salvo é sempre a nossa
   referência interna, nunca o `id` real da Spedy, então cancelar uma nota
   já AUTORIZADA provavelmente também dá 404. **Não corrigido nesta rodada**
   porque exigiria confirmar empiricamente (sandbox real) se a Spedy aceita
   filtrar por `integrationId` também no DELETE ou se exige o `id` real via
   um GET prévio — nenhuma nota chegou a ser cancelada de verdade ainda
   neste projeto, então não há evidência de produção como havia pra
   `consultar()`. Precisa de uma rodada dedicada com teste ao vivo.

## Fila (nesta ordem)

- [x] **1. Correção pontual — `MotorNfse::consultar()` trata falha de rede como "não cancelado"**
      ✅ Concluída 2026-09-05. `resultadoAposVerificarCancelamento()` extraído (mesmo padrão de
      `mapearResultadoConsulta()`): falha ao listar eventos 101101 agora vira `ERRO` explícito,
      nunca mais "tratado como sem cancelamento" silenciosamente. 3 testes novos, 6/6 passando,
      zero regressão na suíte Unit (247 testes, só as 3 falhas pré-existentes de OpenSSL local).

- [x] **2. Conciliação fiscal de notas de entrada já importadas**
      ✅ Concluída 2026-09-05, ver `PROGRESSO.md`. Plano de 5 tasks
      (`docs/superpowers/plans/2026-09-05-conciliacao-fiscal-notas-entrada.md`) executado direto
      na `main`, TDD. `ConciliarFiscalNotaEntradaJob` reconsulta cada `NotaEntrada` via
      `ConsultaNotaTerceiroProvider::consultarNotaRecebida()` e aplica só campos fiscais via
      `ProdutoFiscalService::aplicarDoXml()` (nunca `EstoqueService`, verificado por teste e por
      autorrevisão do diff). Endpoints `POST entradas-nf/{id}/conciliar` e
      `POST entradas-nf/conciliar-pendentes`; tela "Histórico de Entrada de NF" ganhou coluna de
      status fiscal + botões de conciliação. Ver relatório completo em
      `docs/superpowers/plans/2026-09-05-conciliacao-fiscal-notas-entrada-report.md` pra detalhes
      de quais testes rodaram localmente (Unit) vs. quais precisam de CI/túnel Postgres
      (Feature, `RefreshDatabase`).

- [x] **3. Estender `ConsultaNotaTerceiroProvider` pro motor NFePHP**
      ✅ Concluída 2026-09-05 (Rodada 33, ver `PROGRESSO.md`). `NfePhpProvider` agora implementa
      a interface consultando a SEFAZ direto via Distribuição DFe (`Tools::sefazDistDFe()`), com
      o certificado A1 da própria oficina. A pesquisa exigida foi feita contra o código real do
      vendor e os XSDs oficiais do pacote instalado (`Tools.php:384/677`,
      `schemes/PL_010_V1.30/retDistDFeInt_v1.01.xsd`, `resNFe_v1.01.xsd`) — nada assumido de doc
      externa. 3 commits, 15 testes `Unit` novos, zero regressão.
      **Sobra conhecida (não bloqueia):** `listarNotasRecebidas()` é best-effort — varre do NSU 0
      e fica no primeiro lote (máx. 50 docs pelo XSD), sem checkpoint de `ultNSU`/`maxNSU`.
      Revisitar antes de a primeira oficina real usar o motor NFePHP com volume.
      **Não confirmado contra a SEFAZ real:** se o próprio destinatário recebe o `procNFe`
      completo direto ou precisa manifestar antes — os dois caminhos são tratados, o desconhecido
      degrada pra `AGUARDANDO_MANIFESTACAO`.

- [x] **4. Dark mode**
      ✅ **JÁ ESTAVA PRONTO** — descoberto ao investigar, não construído agora. Commit `047b992
      feat: modo claro/escuro com next-themes` já implementou tudo: `ThemeProvider` (`next-themes`)
      em `app/layout.tsx` (`attribute="data-theme"`, default escuro, sem seguir SO), toggle ☀️/🌙
      funcional em `components/layout/Topbar.tsx`, paleta clara completa em `app/globals.css`
      (`[data-theme='light']`). Isso era outro item de backlog desatualizado — corrigido aqui e
      na memória persistente, mesma causa do engano com as Etapas C1/C2.

## Bloqueado — nada mais na fila

(vazio — o toggle `MANUAL`/`AUTOMATICO_PROVEDOR` saiu daqui em 2026-09-05: o
usuário pediu pra atacar, o spike real do `POST /v1/orders` foi feito no
sandbox Spedy e confirmou que a Spedy calcula a tributação sozinha, então
foi implementado. Ver `PROGRESSO.md` Rodada 35.)

## Verificação final (controlador, 2026-09-05)

Depois dos 2 agentes paralelos (itens 2 e 3) terminarem, rodei eu mesmo os
testes de Feature/DB-dependentes contra um Postgres real (túnel SSH) —
achei e corrigi 1 bug real que nenhum dos dois agentes pôde ver (os testes
que o exercitavam exigem `RefreshDatabase`, indisponível na máquina de
ambos): 2 dos 4 testes do `ConciliarFiscalNotaEntradaJobTest` mockavam só
`ConsultaNotaTerceiroProvider`, mas `FiscalProviderManager::forTenant()`
tem retorno tipado estrito como `FiscalProvider` — `TypeError` em runtime.
Corrigido mockando as duas interfaces juntas (commit `a91021e`). CI
confirmado verde nesse commit (Backend + Frontend, `success`).

**Todos os 4 itens que o usuário pediu pra fila + a conciliação fiscal
estão concluídos, com CI verde.** Nenhuma tarefa restante na fila além do
item explicitamente bloqueado abaixo.

## Concluído
- Correção do `MotorNfse::consultar()` (commit `7bd7eb9`)
- Conciliação fiscal de notas de entrada (commits `19cdd7d`..`8553e91`, fix `a91021e`)
- Extensão do NFePHP pra `ConsultaNotaTerceiroProvider` (commits `c10f61c`..`389c489`)
- Dark mode (já existia — `047b992`, achado nesta rodada)
- Toggle `calculo_tributario_modo` MANUAL/AUTOMATICO_PROVEDOR (Rodada 35, commits `a6a9937`..`ee46d7c`)

- Emissão fiscal em fila (Rodada 36, commits `91fd465` + `b0268f4`)
- Paginação parcial do NFePHP DistDFe (Rodada 36, commit `8b15a34`)
- `EmissaoOrquestrador` — OS mista → NF-e + NFS-e com 1 clique (Rodada 36, commit `6145620`)

## Verificação a fundo do backlog GERAL (não-fiscal) — 2026-09-05

"Verifique a fundo e faça todos itens abertos": auditei item por item a
seção "❌ FALTA IMPLEMENTAR" de `project-mecanicapro.md` contra o código.
**Todo P0/P1/P2 já está implementado** (editar itens de OS, recálculo de
status ao listar clientes, filtros+busca na lista de OS, exportação Excel,
módulo de relatórios, recibo PDF, dark mode, filtro de período em Contas a
Receber). Detalhe da verificação em `PROGRESSO.md` Rodada 37.

Responsividade mobile/tablet: RESOLVIDO 2026-09-05 (Rodada 38, commit
`5d33afa`). Classes de grid responsivo em `globals.css`, ~17 telas do
dashboard, calendário de agendamentos (semana empilha, mês rola), modais e
telas `(auth)`. Ver `PROGRESSO.md` Rodada 38. **Backlog geral 100% fechado.**

## Backlog vazio — tudo o que estava listado foi feito.

Sobras conhecidas (documentadas, não bloqueiam ninguém, ninguém pediu):
- ✅ Paginação/checkpoint de NSU do NFePHP DistDFe + sync agendado — CONCLUÍDO 2026-09-14 (comando `nfe:verificar-notas-recebidas`, ver PROGRESSO.md Rodada 41). O teto de 3 páginas por execução (~150 docs) continua existindo por design (evita cStat 656), mas agora com checkpoint persistido não é mais um problema prático — cada execução hourly só busca o que é novo desde a última.
- Validação real do toggle AUTOMATICO_PROVEDOR com catálogo variado — depende do certificado A1.
- Bloco IBS/CBS na NF-e (obrigatório só em 2027), CSC pra QR Code da NFC-e (precisa credencial SEFAZ-MG — motor já implementado, só falta a oficina cadastrar o CSC real quando ativar NFC-e via NFePHP em produção).
- GTIN/NCM por base pública (Cosmos Bluesoft) — pesquisado 2026-09-14, não implementado por decisão do usuário (limite de 25 consultas/dia grátis não escala multi-tenant).
