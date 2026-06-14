# SaaS Admin — Design Spec
**Data:** 2026-05-30  
**Projeto:** MecânicaPro  
**Stack:** Next.js 16 + Laravel 12 + PostgreSQL 16 + Redis

---

## Resumo

Implementar módulo SaaS Admin com multi-tenancy row-level para o sistema MecânicaPro. O super-admin gerencia oficinas (tenants), planos e cobranças via painel dedicado em `/saas-admin`. Cada oficina tem seus dados isolados no mesmo banco PostgreSQL via `stancl/tenancy` com escopo automático por `oficina_id`.

---

## 1. Arquitetura

### Multi-tenancy

- **Estratégia:** Row-level tenancy via `stancl/tenancy` (single-database)
- **Identificação do tenant:** Header `X-Tenant: {slug}` em todas as requisições autenticadas da oficina
- **Banco zerado:** migrations recriadas do zero com suporte a tenancy

### Tabelas centrais (não escopadas)

| Tabela | Conteúdo |
|---|---|
| `oficinas` | Tenants: nome, CNPJ, slug, plano_id, status, asaas_ids |
| `planos` | Configuração de planos: nome, preço, limite_usuarios, limite_os_mes |
| `super_admins` | Usuários do painel SaaS (independente de `usuarios`) |
| `cobrancas` | Histórico de pagamentos por oficina |

### Tabelas tenant-scoped (todas com `oficina_id`)

`usuarios`, `clientes`, `produtos`, `ordens_servico`, `os_itens`, `movimentacoes_estoque`, `notas_fiscais`, `configuracoes`, `agendamentos`, `password_reset_tokens`

### Diagrama de fluxo

```
super_admins → POST /api/saas/auth/login → token saas_token
                    ↓
              /api/saas/* (sem tenancy middleware)
                    ↓
              CRUD: oficinas, planos, cobrancas, dashboard

usuarios →    POST /api/auth/login { email, senha, oficina_slug }
                    ↓
              InitializeTenancyByHeader (X-Tenant: slug)
                    ↓
              /api/* (com tenancy middleware → queries escopadas por oficina_id)
```

---

## 2. Modelo de Dados

### `oficinas`
```sql
id UUID PK
nome VARCHAR(150) NOT NULL
cnpj VARCHAR(18) UNIQUE NOT NULL
slug VARCHAR(60) UNIQUE NOT NULL        -- auto-gerado do nome: lowercase + hífens, ex: "oficina-silva"
plano_id UUID REFERENCES planos(id)
status VARCHAR(20) DEFAULT 'ATIVA'      -- ATIVA | INADIMPLENTE | SUSPENSA | CANCELADA
asaas_customer_id VARCHAR(50)
asaas_subscription_id VARCHAR(50)
admin_email VARCHAR(120)                -- e-mail do admin principal
criado_em TIMESTAMPTZ DEFAULT NOW()
atualizado_em TIMESTAMPTZ DEFAULT NOW()
```

### `planos`
```sql
id UUID PK
nome VARCHAR(60) NOT NULL               -- editável pelo super-admin
preco_mensal NUMERIC(10,2) NOT NULL
limite_usuarios INTEGER NOT NULL        -- -1 = ilimitado
limite_os_mes INTEGER NOT NULL          -- -1 = ilimitado
ativo BOOLEAN DEFAULT TRUE
criado_em TIMESTAMPTZ DEFAULT NOW()
```

### `super_admins`
```sql
id UUID PK
nome VARCHAR(120) NOT NULL
email VARCHAR(120) UNIQUE NOT NULL
senha_hash TEXT NOT NULL
criado_em TIMESTAMPTZ DEFAULT NOW()
```

> **Nota de implementação:** `SuperAdmin` usa um guard Sanctum separado (`saas`) para que seus tokens não sejam escopados pelo tenancy middleware. Configurar em `config/auth.php` com guard `saas` apontando para o model `SuperAdmin`.

### `cobrancas`
```sql
id UUID PK
oficina_id UUID REFERENCES oficinas(id)
mes_referencia DATE NOT NULL            -- primeiro dia do mês
valor NUMERIC(10,2) NOT NULL
status VARCHAR(20) DEFAULT 'PENDENTE'   -- PENDENTE | PAGA | VENCIDA | CANCELADA
asaas_payment_id VARCHAR(50)
vencimento DATE
pago_em TIMESTAMPTZ
criado_em TIMESTAMPTZ DEFAULT NOW()
```

### Planos seed (editáveis via painel)
| Nome | Limite Usuários | Limite OS/mês | Preço |
|---|---|---|---|
| Free | 2 | 20 | R$ 0,00 |
| Pro | 10 | 200 | R$ 149,00 |
| Enterprise | -1 (ilimitado) | -1 (ilimitado) | R$ 399,00 |

---

## 3. Autenticação

### Super-admin (`/saas-admin/login`)

- `POST /api/saas/auth/login` → autentica contra `super_admins`
- Token Bearer armazenado em `localStorage` como `saas_token`
- Cookie `saas_token` definido via `document.cookie` para o `proxy.ts` proteger `/saas-admin/*`
- Sem acesso a dados de nenhuma oficina

### Usuário de oficina (`/login`) — mudança mínima

- Campo novo: `oficina_slug` na tela de login
- URL suporta pré-preenchimento: `/login?oficina=minha-oficina`
- Backend resolve tenant pelo slug antes de autenticar
- Frontend salva `oficina_slug` no localStorage e envia `X-Tenant: {slug}` em todas as requisições

### Proteção de rotas (`proxy.ts`)

```
/saas-admin/login → público
/saas-admin/*     → exige cookie saas_token
/login            → público
/*                → exige cookie auth_token (comportamento atual)
```

---

## 4. Backend

### Estrutura de arquivos novos

```
backend/app/
  Http/Controllers/SaaS/
    AuthController.php           -- login/logout super-admin
    OficinaController.php        -- CRUD oficinas + provisionar admin
    PlanoController.php          -- CRUD planos (editáveis)
    CobrancaController.php       -- histórico + webhook Asaas
    DashboardController.php      -- métricas agregadas globais
  Models/
    Oficina.php                  -- TenantModel (stancl/tenancy)
    Plano.php
    SuperAdmin.php
    Cobranca.php
  Services/
    AsaasService.php             -- criar customer, subscription, cancelar
    TenantProvisionService.php   -- cria oficina + admin + config atomicamente
  Middleware/
    InitializeTenancyByHeader.php  -- resolve X-Tenant → oficina_id
```

### Rotas SaaS (`/api/saas/`)

```php
// Público
POST /api/saas/auth/login
POST /api/saas/auth/logout

// Protegido (middleware: auth:saas — guard Sanctum separado para super_admins)
GET    /api/saas/dashboard
GET    /api/saas/oficinas
POST   /api/saas/oficinas
GET    /api/saas/oficinas/{id}
PUT    /api/saas/oficinas/{id}
POST   /api/saas/oficinas/{id}/suspender
POST   /api/saas/oficinas/{id}/reativar

GET    /api/saas/planos
POST   /api/saas/planos
PUT    /api/saas/planos/{id}
DELETE /api/saas/planos/{id}       -- soft delete (ativo = false)

GET    /api/saas/cobrancas
GET    /api/saas/cobrancas/{oficina_id}

POST   /api/saas/webhooks/asaas    -- público, validado por token fixo
```

### Provisionar nova oficina (transacional)

Ao `POST /api/saas/oficinas`, o `TenantProvisionService` executa em `DB::transaction`:
1. Cria registro em `oficinas`
2. Cria `Usuario` admin (role=ADMIN) escopado ao tenant
3. Cria `Configuracao` vazia para o tenant
4. Se plano pago: cria customer + subscription no Asaas
5. Envia e-mail de boas-vindas ao admin com credenciais

### Limites de plano

Verificados no backend antes de criar OS ou usuário:
- `UsuarioController@store`: conta `usuarios` do tenant, compara com `plano.limite_usuarios`
- `OrdemServicoController@store`: conta OS do mês, compara com `plano.limite_os_mes`
- Retorna HTTP 402 com mensagem ao exceder limite

### Webhook Asaas

```
POST /api/saas/webhooks/asaas
```

| Evento | Ação |
|---|---|
| `PAYMENT_CONFIRMED` | `oficinas.status = ATIVA`, salva em `cobrancas` |
| `PAYMENT_OVERDUE` | `oficinas.status = INADIMPLENTE`, e-mail de alerta |
| `PAYMENT_DELETED` / `SUBSCRIPTION_DELETED` | `oficinas.status = CANCELADA` |

---

## 5. Frontend

### Estrutura de rotas novas

```
frontend/app/
  (saas-admin)/
    layout.tsx              -- topbar simples + nav horizontal, sem sidebar
    login/page.tsx          -- login super-admin
    page.tsx                -- dashboard: stat cards + tabela de oficinas
    oficinas/
      page.tsx              -- listagem completa de oficinas
      nova/page.tsx         -- formulário: dados + plano + usuário admin
      [id]/page.tsx         -- detalhe: dados, métricas, cobranças
    planos/
      page.tsx              -- tabela de planos + botão criar + editar inline
    cobrancas/
      page.tsx              -- histórico global
```

### Mudança na tela `/login`

- Adicionar campo "Código da oficina" (slug) acima do campo e-mail
- Suporte a `?oficina=slug` na URL para pré-preencher
- Hook `useAuth` envia `oficina_slug` no body do login e salva no localStorage
- Interceptor axios envia `X-Tenant: {slug}` em todas as requisições

### Dashboard SaaS — widgets

- **4 stat cards:** Total Oficinas · Ativas · MRR (receita mensal recorrente) · Inadimplentes
- **Tabela de oficinas:** Nome · Plano · Status · Usuários · OS este mês · Último acesso · Ações (Suspender/Reativar/Detalhe)
- **Gráfico:** Crescimento de oficinas ativas nos últimos 6 meses (Recharts BarChart)

### Painel de planos

- Tabela com todos os planos (ativos e inativos)
- Cada linha editável inline: nome, preço, limite_usuarios, limite_os_mes
- Botão "+ Novo plano"
- Desativar plano (soft delete) — oficinas existentes no plano não são afetadas

### Design system

Mesmo design system do sistema (CSS variables, Barlow Condensed, JetBrains Mono). Layout `/saas-admin` usa topbar horizontal fixo (sem sidebar), com badge de identificação "SaaS Admin" em âmbar.

---

## 6. Billing — Asaas

### Configuração

```env
ASAAS_API_KEY=
ASAAS_URL=https://sandbox.asaas.com/api/v3   # produção: api.asaas.com
ASAAS_WEBHOOK_TOKEN=                          # token fixo para validar webhook
```

### Fluxo ao cadastrar oficina com plano pago

```
OficinaController@store
  → AsaasService@criarCustomer({ name, cpfCnpj, email })
  → AsaasService@criarSubscription({ customer, billingType: BOLETO, value, nextDueDate })
  → salva asaas_customer_id + asaas_subscription_id em oficinas
```

### Plano Free

Sem criação de assinatura no Asaas. `oficinas.status = ATIVA` imediatamente.

---

## 7. Ordem de implementação

```
1. Backend: stancl/tenancy + migrations centrais (oficinas, planos, super_admins, cobrancas)
2. Backend: migrations tenant-scoped (adicionar oficina_id em todas as tabelas existentes)
3. Backend: seeders (super_admin + 3 planos + oficina demo)
4. Backend: middleware InitializeTenancyByHeader + rotas SaaS
5. Backend: TenantProvisionService + OficinaController
6. Backend: PlanoController + CobrancaController + DashboardController
7. Backend: AsaasService + webhook
8. Frontend: hook useAuth atualizado (oficina_slug + X-Tenant header)
9. Frontend: tela /login com campo slug
10. Frontend: layout + login /saas-admin
11. Frontend: dashboard SaaS + tabela de oficinas
12. Frontend: formulário nova oficina + detalhe
13. Frontend: painel de planos editáveis
14. Frontend: histórico de cobranças
15. Testes + validação de limites de plano
```

---

## 8. Fora do escopo (próximas fases)

- Portal self-service para a oficina gerenciar seu próprio plano/pagamento
- Notificações in-app de inadimplência para o admin da oficina
- Relatórios financeiros detalhados no painel SaaS
- Suporte a múltiplos gateways além de Asaas
