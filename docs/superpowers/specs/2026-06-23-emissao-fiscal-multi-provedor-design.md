# Emissão Fiscal Multi-Provedor (Spedy + Focus NFe) — Design

**Data:** 2026-06-23
**Status:** Aprovado (brainstorming) — pronto para plano de implementação

## Objetivo

Implementar a emissão de documentos fiscais (NFS-e e NF-e) no MecânicaPro através de
**dois provedores comerciais** — **Spedy** e **Focus NFe** — atrás de uma camada de
abstração intercambiável. O SaaS-admin escolhe o provedor (global e por oficina) e o
modo de emissão (manual/automático). Cada oficina importa o próprio certificado A1 e
emite em ambiente sandbox/homologação ou produção. Um dashboard no SaaS-admin consolida
o consumo de notas por oficina e por provedor.

## Decisões fechadas (brainstorming)

1. **Entrega:** spec único, implementação **faseada** (3 fases).
2. **Tipos de documento:** **NFS-e** (serviços) **+ NF-e** (venda de peças).
3. **Credenciais:** conta **master/parceira no SaaS-admin**; **token + certificado por oficina**
   (cada oficina é registrada como emissor via API e recebe seu token).
4. **Seleção de provedor:** controlada **somente pelo SaaS-admin** — padrão global +
   override por oficina (a oficina não decide).
5. **Modo de emissão:** **MANUAL por padrão** (botão); configurável por oficina para
   **AUTOMATICO** (emite ao concluir a OS). Controlado pelo super admin.
6. **Dashboard de consumo:** lê do **nosso banco** + **job de reconciliação** contra as
   APIs dos provedores.

## Contexto do código existente (reaproveitado)

- **Multi-tenancy** por `oficina_id` (`App\Tenancy\TenancyContext` + `HasTenantScope`;
  tenant resolvido pelo header `X-Tenant: <slug>`).
- **`SaasConfig`** (singleton de plataforma) já tem o padrão `gateway_preferido` global +
  campos sensíveis cifrados/`$hidden`/máscara (`SaasConfig::mascarar`). É onde entra a
  config fiscal global.
- **`Oficina.gateway`** é o precedente exato de "override por oficina".
- **`Configuracao`** (singleton por oficina) guarda dados fiscais e já tem
  `certificado_pfx_encrypted` e `ambiente_fiscal` (HOMOLOGACAO|PRODUCAO).
- **`NfeService`** hoje é provedor único (estilo nfe.io) com fallback simulado → será
  refatorado para orquestrador que delega ao provedor resolvido.
- **`PlanLimitService`** já conta notas/mês por oficina e cobra excedente
  (`Cobranca` tipo `NOTA_EXCEDENTE`). Reaproveitado no dashboard.
- **`NotaFiscal`** já é tenant-scoped, com `LogsActivity` e statuses
  RASCUNHO/PROCESSANDO/AUTORIZADA/CANCELADA/REJEITADA.

## Descoberta sobre as duas APIs

Ambos os provedores seguem o mesmo modelo, o que justifica a colocação das credenciais:

- **Spedy** (`docs.spedy.com.br`): arquitetura "API-First". A plataforma parceira tem uma
  conta com X-API-Key; registra cada empresa emissora **via API**, vincula certificado A1
  **via API**, e cada empresa recebe sua própria X-API-Key. Ambientes: sandbox
  (`stage-app.spedy.com.br`) / produção (`api.spedy.com.br`). Suporta NFS-e/NF-e/NFC-e.
  Certificado A1 obrigatório, inclusive no sandbox. Backoffice é restrito ao parceiro
  (nós), não aos emissores finais.
- **Focus NFe** (`doc.focusnfe.com.br`): conta da plataforma com token; registra empresas
  via `/v2/empresas` enviando o certificado; cada empresa pode ter token próprio. Auth via
  HTTP Basic (token como usuário). Ambientes separados: homologação
  (`homologacao.focusnfe.com.br`) / produção (`api.focusnfe.com.br`). Emissão de NFS-e/NF-e
  é assíncrona (consulta por referência ou webhook).

## Arquitetura — camada de abstração (Strategy)

### DTOs neutros (`app/Services/Fiscal/Data/`)
- `EmissorData` — dados do emissor (CNPJ, razão social, regime, IE/IM, endereço, CNAE,
  código IBGE) para registro.
- `NotaFiscalData` — payload neutro da nota: `tipo` (NFSE|NFE), tomador, itens, valores,
  natureza da operação, `referencia_externa`. O provedor traduz para seu JSON.
- `EmissaoResultado` — resultado normalizado: `status`, `chave`, `protocolo`, `numero`,
  `xml`, `pdf_url`, `mensagem_erro`.
- `RegistroResultado` — resultado do registro de emissor: `emissor_externo_id`, `token`,
  `status`, `mensagem_erro`.
- `PeriodoData` / `ConsumoResultado` — para reconciliação.

### Interface (`app/Services/Fiscal/Contracts/FiscalProvider.php`)
```php
interface FiscalProvider {
    public function registrarEmissor(EmissorData $e): RegistroResultado;
    public function enviarCertificado(EmissorData $e, string $pfx, string $senha): void;
    public function emitir(NotaFiscalData $nota): EmissaoResultado;     // NFS-e ou NF-e por nota.tipo
    public function consultar(string $referencia): EmissaoResultado;    // status (Focus assíncrono)
    public function cancelar(string $referencia, string $motivo): EmissaoResultado;
    public function consultarConsumo(PeriodoData $p): ConsumoResultado;
}
```

### Implementações (`app/Services/Fiscal/Providers/`)
- `SpedyProvider` — base URL por ambiente, auth `X-API-Key`, mapeamento de payload,
  tradução de erros para `EmissaoResultado`.
- `FocusNfeProvider` — base URL por ambiente, auth HTTP Basic (token), payload Focus,
  emissão assíncrona + `consultar()`.

### Manager (`app/Services/Fiscal/FiscalProviderManager.php`)
Resolve a instância por request. Ordem de resolução do **provedor**:
`Oficina.provedor_fiscal` (override) → senão `SaasConfig.provedor_fiscal_padrao`.
Injeta: credencial **master** (do `SaasConfig`, por ambiente), **token por-oficina**
(de `emissores_fiscais`) e **ambiente** (`Configuracao.ambiente_fiscal`).

### Orquestrador (`NfeService` refatorado)
Mantém: numeração transacional (`lockForUpdate`), persistência da `NotaFiscal`,
`PlanLimitService`, alertas. Em vez de HTTP direto, monta `NotaFiscalData` e delega para
`FiscalProviderManager->forTenant()->emitir(...)`.

### Fluxo de emissão
```
Controller → NfeService (numera, monta NotaFiscalData, persiste RASCUNHO→PROCESSANDO)
           → FiscalProviderManager.resolve(oficina) → Spedy|FocusNfeProvider.emitir()
           → EmissaoResultado normalizado → NfeService grava status/chave/protocolo
           → (PRODUCAO) PlanLimitService + AlertaDispatchService
```

## Modelo de dados (migrations)

### `saas_config` (plataforma) — novos campos (cifrados, `$hidden`, máscara)
- `provedor_fiscal_padrao` — SPEDY|FOCUS (default global)
- `emissao_fiscal_modo_padrao` — MANUAL|AUTOMATICO (default **MANUAL**)
- `spedy_master_key_sandbox`, `spedy_master_key_producao`
- `focus_master_token_homologacao`, `focus_master_token_producao`
- *(base URLs ficam em `config/services.php`, não no banco)*

### `oficinas` — overrides
- `provedor_fiscal` (nullable) — SPEDY|FOCUS|null (null = padrão global)
- `emissao_fiscal_modo` (nullable) — MANUAL|AUTOMATICO|null (null = padrão global)

### `configuracoes` (singleton por oficina) — certificado
- reuso: `certificado_pfx_encrypted`, `ambiente_fiscal`
- novos: `certificado_senha_encrypted`, `certificado_validade` (date),
  `certificado_nome`, `certificado_status`

### `emissores_fiscais` (NOVA) — token por oficina × provedor × ambiente
```
id uuid · oficina_id uuid · provedor · ambiente
emissor_externo_id     -- id da empresa no provedor
token_encrypted        -- X-API-Key (Spedy) / token-empresa (Focus), cifrado
status (PENDENTE|REGISTRADO|ERRO) · registrado_em · ultimo_erro
UNIQUE(oficina_id, provedor, ambiente)
```

### `notas_fiscais` — novos campos
- `provedor` (SPEDY|FOCUS) · `ambiente` (HOMOLOGACAO|PRODUCAO)
- `modelo` passa a aceitar `NFS-e`|`NF-e` (já existe)
- `referencia_externa` — ref única enviada ao provedor (consulta/cancelamento)

### `nota_fiscal_itens` (NOVA) — itens de linha (necessário p/ NF-e)
```
id · nota_fiscal_id · oficina_id · produto_id(null)
tipo (SERVICO|PRODUTO) · descricao · ncm · cfop · unidade
quantidade · valor_unitario · valor_total
```

### `produtos` — campos fiscais (opcionais, defaults sensatos)
- `ncm`, `cfop`, `origem`, `unidade_fiscal`

### `conciliacoes_fiscais` (NOVA) — resultado do job de reconciliação
```
id · oficina_id(null) · provedor · ambiente · mes_referencia
total_local · total_provedor · divergencia · conferido_em
```

Padrões mantidos: UUID via `boot()`; `oficina_id` + `HasTenantScope` nas tabelas tenant;
`saas_config`/`emissores_fiscais`/`conciliacoes_fiscais` na esfera de plataforma.

## Fluxo de onboarding do emissor (por oficina)

1. Oficina, em **Configurações → Empresa** (área do tenant), preenche dados fiscais e faz
   **upload do certificado A1 (.pfx) + senha**.
2. Backend valida o `.pfx` (abre com a senha via `openssl`, extrai **validade**), **cifra
   arquivo e senha (AES-256 / `Crypt`)**, salva em `configuracoes`, `certificado_status=OK`.
3. **Registro no provedor** (ação "Ativar emissão", idempotente): `Manager` resolve o
   provedor → `registrarEmissor()` (master key do ambiente) → `enviarCertificado()` →
   grava `emissores_fiscais` (`token_encrypted`, `emissor_externo_id`, `status=REGISTRADO`).
   Reaproveita registro existente para `(oficina, provedor, ambiente)`.

## Ambiente sandbox vs produção (por oficina)

- Toda oficina nasce em **HOMOLOGACAO** → emite notas de teste livremente.
- Quando liberada na Prefeitura/SEFAZ, o SaaS-admin (ou a oficina) muda
  `ambiente_fiscal → PRODUCAO`, disparando o registro do emissor no ambiente de produção
  do provedor (novo registro em `emissores_fiscais`).
- **Regra:** notas em HOMOLOGACAO **não contam** para limite/excedente do plano e **não
  disparam** alerta real ao cliente (marcadas `ambiente=HOMOLOGACAO`).

## Modo de emissão (manual vs automático)

- **MANUAL (padrão):** nota só sai via botão **"Emitir nota fiscal"** (OS concluída ou tela
  fiscal).
- **AUTOMATICO ("imediato"):** ao **concluir a OS**, o sistema gera e emite automaticamente
  o documento correspondente (NFS-e dos serviços e/ou NF-e das peças, conforme conteúdo da
  OS), sem clique.
- **Controle:** super admin no SaaS-admin, por oficina (fallback no padrão global). A
  oficina não decide. Mesma tela onde define o provedor por oficina.

## Emissão (NFS-e e NF-e)

1. A partir de OS concluída ou avulsa, monta-se a nota (NFS-e = serviços; NF-e = itens de
   peça com NCM/CFOP).
2. `NfeService`: numera (`lockForUpdate`), persiste `RASCUNHO → PROCESSANDO`, monta
   `NotaFiscalData` (com `referencia_externa` única), resolve provider, chama `emitir()`.
3. **Assincronismo:** Focus é assíncrono → nota fica `PROCESSANDO` e o job
   **`ConsultarNotaFiscal`** (fila, retry/backoff) faz polling via `consultar(referencia)`
   até AUTORIZADA/REJEITADA. *(Webhook dos provedores = melhoria futura.)*
4. **AUTORIZADA:** grava chave/protocolo/número/xml/pdf_url → (só PRODUCAO)
   `PlanLimitService.registrarNotaSeExcedente` → (só PRODUCAO) `AlertaDispatchService`
   (`NF_AUTORIZADA`).
5. **Cancelamento:** `cancelar(referencia, motivo)` → `CANCELADA` (modal de motivo já existe).

## Dashboard de consumo (SaaS-admin) + reconciliação

### Backend — `SaaS\FiscalDashboardController` (cross-tenant, `withoutGlobalScopes`)
Agrega `notas_fiscais` de todas as oficinas. Métricas:
- Emitidas (AUTORIZADA) no período — total e **por provedor** (Spedy vs Focus).
- Por modelo (NFS-e vs NF-e) e por ambiente (produção vs homologação separados).
- Canceladas/rejeitadas (taxa de erro por provedor).
- **Faturamento fiscal total** e **ticket médio por nota**.
- Por oficina: emitidas, canceladas, consumo vs limite, excedentes (`Cobranca`
  `NOTA_EXCEDENTE`).
- Filtros: período, **oficina**, provedor, ambiente, modelo.

### Frontend — página no SaaS-admin (super admin), design system existente
- Stat cards: Total emitidas · Por Spedy · Por Focus · Canceladas/Rejeitadas ·
  Excedentes (R$) · Faturamento fiscal · Ticket médio.
- Gráfico de barras (Recharts) — emissões por mês, empilhado por provedor.
- Tabela por oficina: `Oficina · Provedor ativo · Emitidas · Canceladas · Consumo/Limite ·
  Excedente`, com filtro.

### Reconciliação — job `ReconciliarConsumoFiscal` (scheduler, diário/mensal)
- Para cada `(provedor, ambiente)` chama `consultarConsumo(periodo)`, compara com a
  contagem local, grava `conciliacoes_fiscais` (`total_local`, `total_provedor`,
  `divergencia`).
- Provedor sem endpoint de consumo → reconciliação marcada como "não suportada".
- Dashboard mostra **selo de divergência** quando `divergencia != 0`.

## Tratamento de erros e segurança

- **Credenciais (master + token por-oficina):** cifradas (`Crypt`/AES-256), `$hidden`,
  máscara na exibição (`SaasConfig::mascarar`). Nunca retornadas em claro pela API.
- **Certificado `.pfx` + senha:** cifrados em repouso. **Validade monitorada** — alerta
  quando faltar N dias para expirar. Senha/conteúdo nunca logados.
- **Erros do provedor:** normalizados em `EmissaoResultado.mensagem_erro` → toast vermelho
  com a mensagem real da SEFAZ/Prefeitura. Falha de comunicação → `REJEITADA` + mensagem,
  permitindo reemitir.
- **Idempotência:** `referencia_externa` única evita duplicação; `registrarEmissor`
  idempotente; numeração transacional (`lockForUpdate`) mantida.
- **Resiliência:** jobs com retry/backoff; falhas repetidas de um provedor sinalizadas no
  SaaS-admin.
- **Auditoria:** `NotaFiscal` (`LogsActivity`) passa a registrar `provedor`/`ambiente`.
- **Permissões:** emitir/cancelar restrito por role no tenant; configurar provedor/modo
  restrito ao **SuperAdmin** no SaaS-admin.

## Testes

Respeitando o ambiente (localmente só **unit tests** rodam, sem DB/Docker; feature tests
nunca em produção):
- **Unit:** resolução do `FiscalProviderManager` (override vs global); mapeamento de payload
  de cada provider via `Http::fake`; normalização de `EmissaoResultado` + tradução de erros;
  regra "homologação não conta no plano/alerta"; validação do certificado.
- **Feature:** escritos, executados só em ambiente com banco.

## Fases de implementação

- **Fase 1 — Fundação multi-provedor:** migrations base (saas_config, oficinas,
  configuracoes, emissores_fiscais, notas_fiscais); DTOs + `FiscalProvider` + `Manager`;
  refatorar `NfeService`; `SpedyProvider` + `FocusNfeProvider` (registrar emissor +
  **NFS-e em sandbox**); SaaS-admin (provedor + modo, global e por-oficina); upload de
  certificado no tenant.
- **Fase 2 — NF-e + automático + assíncrono:** campos fiscais de produto +
  `nota_fiscal_itens`; emissão **NF-e**; job `ConsultarNotaFiscal` (polling); modo
  **AUTOMATICO** ao concluir OS; cancelamento.
- **Fase 3 — Dashboard + reconciliação:** `FiscalDashboardController` + página SaaS-admin;
  job `ReconciliarConsumoFiscal` + `conciliacoes_fiscais`.

## Fora de escopo (YAGNI / futuro)

- NFC-e (nota ao consumidor / cupom, CSC, contingência).
- Webhooks dos provedores (polling cobre o MVP).
- Failover automático entre provedores em caso de indisponibilidade.
- Reconciliação por oficina quando a API do provedor não suporta granularidade.
