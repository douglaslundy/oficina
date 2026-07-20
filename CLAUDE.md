# 🔧 PROMPT COMPLETO — Sistema de Gestão para Oficina Mecânica
## Para uso no Claude Code (claude code / CLAUDE.md)

---

## CONTEXTO DO PROJETO

Você é um engenheiro full-stack sênior especialista em sistemas de gestão para o setor automotivo brasileiro. Seu objetivo é construir do zero um **sistema SaaS de gestão para oficinas mecânicas**, seguindo estritamente o design system e o fluxo de navegação do protótipo HTML de referência fornecido (`oficina-system.html`).

O sistema deve ser **pronto para produção**, com código limpo, tipado, testável e escalável para até 50 oficinas simultâneas (modelo multi-tenant).

---

## DESIGN SYSTEM — REFERÊNCIA OBRIGATÓRIA

O layout de referência usa as seguintes regras de design que DEVEM ser mantidas em todo o projeto:

### Paleta de cores (CSS Variables)
```css
--bg:        #0e0f11;   /* fundo global */
--surface:   #161719;   /* sidebar, topbar */
--card:      #1c1e21;   /* cards, painéis */
--border:    #2a2d33;
--text:      #e8eaf0;
--muted:     #7a8090;
--accent:    #f5a623;   /* âmbar — cor primária de ação */
--danger:    #e53935;   /* vermelho — dívidas, estoque crítico */
--success:   #43a047;   /* verde — status OK */
--info:      #1e88e5;   /* azul — informativo */
```

### Tipografia
- Display / Títulos: **Barlow Condensed** (700–800)
- Corpo: **Barlow** (400–600)
- Códigos / SKUs / valores numéricos: **JetBrains Mono**

### Regras de status visual (CRÍTICO — nunca violar)
- **Vermelho (`--danger`)**: clientes devedores, estoque abaixo do mínimo, NF cancelada
- **Âmbar (`--accent`)**: estoque baixo (acima do crítico), OS em andamento
- **Verde (`--success`)**: situação regular, NF autorizada, estoque OK
- Linhas de tabela com débito/crítico recebem `background: rgba(229,57,53,.06)` (classe `danger-row`)

### Componentes obrigatórios do design
- Sidebar fixa (230px) com nav-items, badges de alerta e user-pill
- Topbar com breadcrumb dinâmico e botão de ação contextual
- Stat cards com barra colorida superior (2px) e ícone ghost
- Tabelas com `pill` de status colorido
- Barra de progresso de estoque (`stock-bar` + `stock-fill` animado em crítico)
- Toast de confirmação em todas as ações de escrita
- Alert banner dismissível para situações críticas

---

## STACK TECNOLÓGICA

### Frontend
```
Next.js 14+ (App Router)
TypeScript 5+
Tailwind CSS (tokens mapeados para o design system acima)
shadcn/ui (base de componentes, customizado para o tema escuro)
React Hook Form + Zod (formulários com validação)
TanStack Query v5 (cache e estado de servidor)
Recharts (gráficos do dashboard)
next-themes (dark/light toggle futuro)
```

### Backend
```
Laravel 11 (PHP 8.3+, API-only — sem Blade)
Laravel Sanctum (autenticação SPA via httpOnly cookies + tokens)
Eloquent ORM (driver pgsql)
Laravel Migrations (versionamento do banco)
Laravel Queues + Horizon (filas Redis — alertas, e-mails, NPS)
spatie/laravel-permission (RBAC: roles e permissões)
spatie/laravel-activitylog (logs de auditoria RNF-014)
stancl/tenancy (multi-tenant por tenant_id)
NFePHP (integração NF-e / NFS-e com SEFAZ)
barryvdh/laravel-dompdf (geração de PDF — OS, relatórios)
maatwebsite/laravel-excel (exportação Excel/XLSX)
```

### Banco de dados
```
PostgreSQL 16 (principal)
Redis 7 (cache de sessão, filas, rate limiting)
```

### Infraestrutura / DevOps
```
Docker + Docker Compose (desenvolvimento local)
GitHub Actions (CI/CD)
Railway ou Render (deploy inicial recomendado)
Laravel Horizon (monitoramento visual das filas)
Laravel Telescope (debug em desenvolvimento)
```

### Integrações externas
```
NFe.io ou Focus NFe (emissão NF-e / NFS-e) — via NFePHP
SEFAZ (ambiente de produção e homologação)
Resend ou SendGrid (e-mail transacional — alertas de estoque, NF)
ViaCEP (autopreenchimento de endereço)
Asaas / Efí (gateway de pagamento — módulo SaaS Admin)
```

---

## MÓDULOS DO SISTEMA (ordem de desenvolvimento)

### FASE 1 — Fundação

#### 1.1 Autenticação e Sessão

**Telas:** Login · Esqueci minha senha · Redefinir senha

---

##### Layout geral das telas de auth (split-view obrigatório)

```
┌──────────────────────────────┬──────────────────────┐
│  AUTH-LEFT (flex:1)          │  AUTH-RIGHT (480px)  │
│  Painel de marca             │  Formulário ativo    │
│                              │                      │
│  • Logo âmbar (72px, br 18)  │  Alterna entre:      │
│  • "MecânicaPro" 36px 800    │  • panel-login       │
│  • tagline muted             │  • panel-forgot      │
│  • 5 feature bullets         │  • panel-reset       │
│                              │                      │
│  Fundo: --surface            │  Fundo: --bg         │
│  Grid bg: opacity .04        │                      │
│  Radial gradients âmbar      │                      │
└──────────────────────────────┴──────────────────────┘
```

---

##### Tela 1 — Login (`/login`)

**Campos:**
- E-mail (ícone ✉, type=email, autocomplete=email)
- Senha (ícone 🔒, type=password, toggle 👁 visível/oculto)
- Checkbox "Lembrar de mim"
- Link "Esqueci minha senha" → navega para `/forgot-password`

**Botão de submit:** âmbar, full-width, `Barlow Condensed 800 17px`
- Loading state: texto "⟳ Verificando..." + `pointer-events: none`
- Sucesso: texto "✓ Acesso liberado!" + background `--success` por 600ms → redireciona
- Erro: campos recebem classe `.error` (borda `--danger`) + mensagem inline

**Validações (client-side antes do request):**
- E-mail: regex `/^[^\s@]+@[^\s@]+\.[^\s@]+$/`
- Senha: campo obrigatório (não vazio)
- Exibir erros apenas após tentativa de submit

**Erros de credencial inválida:**
- Não indicar qual campo está errado (segurança)
- Mensagem genérica: "E-mail ou senha incorretos. Verifique e tente novamente."

**Atalho:** tecla Enter submete o formulário se foco estiver em qualquer campo

**Quick-access demo** (apenas em ambiente de desenvolvimento):
```
Admin    → admin@mecanicapro.com / admin123
Mecânico → mecanico@mecanicapro.com / mec123
```

---

##### Tela 2 — Esqueci minha senha (`/forgot-password`)

**Header:** botão "← Voltar ao login" (cor `--muted`, sem sublinhado, hover → `--text`)

**Campo:** E-mail cadastrado (ícone ✉, validação igual ao login)

**Fluxo após submit válido:**
1. Loading state no botão (aguarda resposta da API)
2. Esconder o formulário (`display: none`)
3. Exibir `.auth-success-box` com animação `fadeIn`:
   - Ícone 📨 (36px)
   - Título verde "E-mail enviado!"
   - Mensagem com e-mail destacado em âmbar
   - Aviso: link expira em 30 minutos
   - Link "Voltar ao login →"

**Backend — endpoint:** `POST /api/auth/forgot-password`
```php
// Resposta SEMPRE 200 (não revelar se e-mail existe ou não)
// Internamente: se e-mail existe → gerar token UUID + salvar hash no DB
// + dispatch(new EnviarEmailRecuperacao($usuario, $token))
return response()->json([
    'message' => 'Se o e-mail estiver cadastrado, você receberá um link em breve.'
]);
```

**Token de reset:**
- UUID v4 + timestamp de expiração (30min)
- Salvo na tabela `password_reset_tokens(id, usuario_id, token_hash, expires_at, usado)`
- Hash com SHA-256 antes de salvar (nunca salvar token puro)
- Link enviado: `https://app.mecanicapro.com/reset-password?token={token_puro}`

---

##### Tela 3 — Redefinir senha (`/reset-password?token=...`)

**Header:** botão "← Voltar ao login"

**Campos:**
- Nova senha (ícone 🔒, toggle visível/oculto)
  - Indicador de força visual logo abaixo do campo:
    ```
    [seg1][seg2][seg3][seg4]   ← 4 segmentos coloridos
    Força: Fraca / Média / Forte
    ```
    - 1 segmento vermelho = Fraca (< 8 chars)
    - 2 segmentos âmbar = Média (8+ chars ou maiúscula ou número)
    - 3-4 segmentos verdes = Forte (8+ chars + maiúscula + número [+ especial])
- Confirmar nova senha (ícone 🔒)

**Validações:**
- Mínimo 8 caracteres
- Pelo menos 1 letra maiúscula
- Pelo menos 1 número
- Campos devem ser iguais

**Após submit válido:**
- Loading → botão verde "✓ Senha redefinida!" por 800ms → redireciona para login
- Toast: "Senha atualizada! Faça login com a nova senha."

**Backend — endpoint:** `POST /api/auth/reset-password`
```php
// Validar token: buscar pelo hash, checar expires_at > now(), checar usado = false
// Se inválido → 400 {"message": "Token inválido ou expirado."}
// Se válido → atualizar password_hash + marcar token como usado = true
$token = PasswordResetToken::where('token_hash', hash('sha256', $request->token))
    ->where('expires_at', '>', now())
    ->where('usado', false)
    ->firstOrFail();

$token->usuario->update(['password' => Hash::make($request->password)]);
$token->update(['usado' => true]);
```

**Tratamento de token expirado/inválido:**
- Exibir estado de erro (sem formulário):
  - Ícone ⚠ vermelho
  - Título "Link expirado"
  - Mensagem: "Este link de recuperação expirou ou já foi utilizado."
  - Botão âmbar: "Solicitar novo link" → navega para `/forgot-password`

---

##### Componente: `PasswordInput`
Reutilizável em todas as telas que têm campo de senha:
```tsx
interface PasswordInputProps {
  id: string;
  placeholder?: string;
  showStrength?: boolean; // exibe indicador de força — só em reset
  error?: string;
  onChange?: (value: string) => void;
}
```

---

##### Regras de segurança obrigatórias
- Cookies httpOnly para tokens (nunca localStorage)
- Access token: 15 minutos (via Sanctum)
- Refresh token: 7 dias (rotação a cada uso)
- Rate limiting no endpoint de login: 5 tentativas / 15min por IP (Laravel RateLimiter + Redis)
- Rate limiting em `/forgot-password`: 3 tentativas / hora por e-mail
- HTTPS obrigatório em produção (redirect automático)
- Headers de segurança: `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`

---

- Níveis de acesso: `ADMIN | MECANICO | ATENDENTE | FINANCEIRO`
- Middleware de rota no Next.js para proteção por role (frontend)
- Middleware `role:ADMIN` via spatie/laravel-permission no backend
- Armazenar `user.role` e `user.name` no token para uso no sidebar

#### 1.2 Estrutura base do layout
- Sidebar com navegação reativa (ativo = borda esquerda âmbar)
- Badges dinâmicos nos itens "Clientes" (devedores) e "Produtos" (alertas)
- Topbar com título/breadcrumb dinâmico por rota
- Botão de ação contextual no topbar (muda por módulo)
- Sistema de toast global (`useToast` hook)
- Alert banner de estoque crítico (dismissível por sessão)

---

### FASE 2 — Cadastros

#### 2.1 Módulo Usuários
**Telas:** Lista + Formulário (criar/editar)

**Campos do formulário:**
- Nome completo, e-mail, CPF, telefone
- Perfil de acesso (select com roles)
- Status (Ativo/Inativo)
- Senha / Confirmar senha (só em criação; edição = campo opcional)

**Regras:**
- E-mail único no sistema
- CPF com validação de dígito verificador (algoritmo brasileiro)
- Senha mínimo 8 chars, 1 maiúscula, 1 número
- Admin não pode desativar a si mesmo

**Tabela de listagem:** Nome · E-mail · Perfil · Último acesso · Status · Ações (Editar)

#### 2.2 Módulo Clientes
**Telas:** Lista + Formulário (criar/editar) + Detalhe do cliente

**Campos do formulário:**
- Nome/Razão Social, CPF/CNPJ, telefone, e-mail
- CEP (ViaCEP autopreenchimento) → Endereço, Bairro, Cidade, UF
- Veículo principal: modelo, ano, placa, chassi (opcional)

**Regras:**
- CPF/CNPJ com validação de dígito verificador
- Status automático: `REGULAR | DEVEDOR | OS_ABERTA`
  - `DEVEDOR` = tem OS com valor_pago < valor_total
  - Linhas com status DEVEDOR → classe `danger-row` na tabela
- Tela de detalhe: histórico de OS, débitos em aberto (pill vermelho com valor)

**Tabela:** Nome · Telefone · Veículo · Última OS · Situação · Ações

#### 2.3 Módulo Produtos / Estoque
**Telas:** Lista com filtros + Formulário + Controle de limite

**Campos do formulário:**
- Nome, código/SKU (auto-gerado ou manual), categoria
- Quantidade atual, Estoque mínimo, Unidade (Un/L/Par/Cx)
- Preço de custo, Preço de venda

**Categorias padrão:** Filtros · Óleo/Fluidos · Freios · Suspensão · Elétrica · Motor · Outros

**Regras de estoque:**
- `CRITICO`: qty <= 0 ou qty < estoque_minimo × 0.4 → pill vermelho, barra pulsante
- `BAIXO`: qty < estoque_minimo → pill âmbar, barra âmbar
- `NORMAL`: qty >= estoque_minimo → pill verde, barra verde
- Configuração global de limite padrão (salvo em `configuracoes.estoque_limite_padrao`)
- Cada produto pode ter limite individual (sobrepõe o global)
- Ao salvar movimentação que leva qty abaixo do mínimo → `dispatch(new EnviarAlertaEstoque($produto))`

**Movimentação de estoque:**
- Entrada manual (compra de fornecedor)
- Saída automática ao fechar OS com peças vinculadas
- Histórico de movimentações por produto

**Tabela:** Produto · SKU · Categoria · [barra de estoque] Qtd · Mínimo · Preço Venda · Status · Ações

---

### FASE 3 — Operacional

#### 3.1 Ordens de Serviço (OS)
**Telas:** Lista + Criar/Editar + Detalhe + Imprimir

**Campos:**
- Cliente (select com busca)
- Veículo (preenchido automaticamente pelo cliente, editável)
- Problema relatado (textarea)
- Serviços prestados (lista dinâmica: descrição + valor mão de obra)
- Peças utilizadas (select de produtos do estoque + qty + valor unitário)
- Mecânico responsável (select de usuários com role MECANICO)
- Status: `ABERTA | EM_ANDAMENTO | AGUARDANDO_PECAS | CONCLUIDA | CANCELADA`
- Prazo estimado de entrega
- Forma de pagamento
- Valor total (calculado = soma serviços + peças com markup)
- Valor pago / Saldo devedor

**Regras:**
- Ao mudar status para CONCLUIDA: baixa automática de estoque das peças
- Se saldo_devedor > 0 ao fechar → cliente recebe status DEVEDOR
- OS concluída permite gerar NF diretamente

#### 3.2 Agendamento
- Calendário semanal/mensal visual
- Criar agendamento vinculado a cliente + tipo de serviço
- Ao confirmar agendamento → cria rascunho de OS automaticamente

---

### FASE 4 — Fiscal

#### 4.1 Emissão de Nota Fiscal
**Tela:** Formulário em split-view (formulário esquerda + painel lateral direita)

**Painel esquerdo:**
- Select cliente
- Natureza da operação (Prestação de Serviços / Venda / Misto)
- Data de emissão
- Forma de pagamento
- **Itens dinâmicos** (add/remove):
  - Descrição, Qtd, Valor unitário → Total calculado
  - Botão "+ Add" adiciona linha na tabela
  - Botão ✕ remove linha
- Cálculo automático: Subtotal · Desconto · ISS (%) · **TOTAL**
- Campo de observações/dados adicionais
- Pode ser gerada a partir de uma OS selecionada (pré-preenchimento)

**Painel direito (side panel):**
- Box "Dados do Emitente": empresa, CNPJ, IE, município, regime
- Box "Destinatário": nome, CPF/CNPJ, cidade
- Box "Configurações Fiscais": modelo (NFS-e/NF-e), série
- Botão **EMITIR NOTA FISCAL** (verde, destaque máximo)
  - Loading state durante envio para SEFAZ via NFePHP
  - Sucesso → toast verde + atualiza número sequencial
  - Erro → toast vermelho com mensagem da SEFAZ
- Botão secundário: Pré-visualizar PDF (abre em nova aba)

**Estados da NF:** `RASCUNHO | PROCESSANDO | AUTORIZADA | CANCELADA | REJEITADA`

#### 4.2 Histórico de NF
- Tabela: #NF · Cliente · Emissão · Valor · Modelo · Situação · PDF
- Filtros: período, cliente, status
- Ação cancelar NF (modal de confirmação + motivo)
- Download PDF individual ou em lote (ZIP)

---

### FASE 5 — Configurações

#### 5.1 Dados da Empresa
**Campos:**
- Razão Social, Nome Fantasia
- CNPJ (validado), Inscrição Estadual, Inscrição Municipal
- Regime Tributário (Simples Nacional / Lucro Presumido / Lucro Real)
- CEP → Endereço, Bairro, Cidade, UF (ViaCEP)
- Telefone, E-mail
- **Configurações Fiscais:**
  - Ambiente: Produção / Homologação
  - Série NFS-e, Próximo número NF
  - Alíquota ISS (%), CNAE Principal, Código IBGE
  - Certificado digital A1 (upload .pfx + senha — criptografado com AES-256 em repouso)

#### 5.2 Configurações Gerais
- Limite padrão de alerta de estoque (input numérico)
- Notificações por e-mail (on/off + endereço + frequência)
- Tema visual (dark/light — futuro)
- Formato de data, moeda (BRL fixo por ora)

---

### FASE 6 — Dashboard

**Widgets obrigatórios:**
- 4 stat cards: Clientes Ativos · Dívidas em Aberto (vermelho) · Faturamento Mês (verde) · NF Emitidas (azul)
- Alert banner de estoque crítico (lista de itens críticos, link para módulo)
- Gráfico de barras: faturamento mensal (últimos 7 meses) — Recharts BarChart
- Card "Alertas de Estoque": lista top-5 itens mais críticos com qty em vermelho/âmbar
- Tabela "Últimas OS": #OS · Cliente · Veículo · Serviço · Valor · Status

---

## MODELO DE BANCO DE DADOS (PostgreSQL)

```sql
-- Usuários
CREATE TABLE usuarios (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  nome VARCHAR(120) NOT NULL,
  email VARCHAR(120) UNIQUE NOT NULL,
  cpf VARCHAR(11) UNIQUE NOT NULL,
  telefone VARCHAR(15),
  role VARCHAR(20) NOT NULL DEFAULT 'ATENDENTE',
  status VARCHAR(10) NOT NULL DEFAULT 'ATIVO',
  senha_hash TEXT NOT NULL,
  ultimo_acesso TIMESTAMPTZ,
  criado_em TIMESTAMPTZ DEFAULT NOW()
);

-- Clientes
CREATE TABLE clientes (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  nome VARCHAR(150) NOT NULL,
  cpf_cnpj VARCHAR(18) UNIQUE NOT NULL,
  telefone VARCHAR(15),
  email VARCHAR(120),
  cep VARCHAR(9),
  endereco VARCHAR(200),
  bairro VARCHAR(80),
  cidade VARCHAR(80),
  uf CHAR(2),
  veiculo_modelo VARCHAR(80),
  veiculo_ano SMALLINT,
  veiculo_placa VARCHAR(10),
  status VARCHAR(20) DEFAULT 'REGULAR',
  criado_em TIMESTAMPTZ DEFAULT NOW()
);

-- Produtos
CREATE TABLE produtos (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  nome VARCHAR(150) NOT NULL,
  sku VARCHAR(30) UNIQUE NOT NULL,
  categoria VARCHAR(40) NOT NULL,
  unidade VARCHAR(10) DEFAULT 'Un',
  qty_atual INTEGER NOT NULL DEFAULT 0,
  qty_minima INTEGER NOT NULL DEFAULT 5,
  preco_custo NUMERIC(10,2),
  preco_venda NUMERIC(10,2),
  ativo BOOLEAN DEFAULT TRUE,
  criado_em TIMESTAMPTZ DEFAULT NOW()
);

-- Movimentação de estoque
CREATE TABLE movimentacoes_estoque (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  produto_id UUID REFERENCES produtos(id),
  tipo VARCHAR(10) NOT NULL, -- ENTRADA | SAIDA
  quantidade INTEGER NOT NULL,
  motivo VARCHAR(100),
  os_id UUID,
  usuario_id UUID REFERENCES usuarios(id),
  criado_em TIMESTAMPTZ DEFAULT NOW()
);

-- Ordens de Serviço
CREATE TABLE ordens_servico (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  numero SERIAL UNIQUE,
  cliente_id UUID REFERENCES clientes(id),
  mecanico_id UUID REFERENCES usuarios(id),
  veiculo_descricao VARCHAR(100),
  veiculo_placa VARCHAR(10),
  problema_relatado TEXT,
  status VARCHAR(25) DEFAULT 'ABERTA',
  forma_pagamento VARCHAR(30),
  prazo_entrega DATE,
  valor_total NUMERIC(10,2) DEFAULT 0,
  valor_pago NUMERIC(10,2) DEFAULT 0,
  criado_em TIMESTAMPTZ DEFAULT NOW(),
  atualizado_em TIMESTAMPTZ DEFAULT NOW()
);

-- Itens da OS (serviços + peças)
CREATE TABLE os_itens (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  os_id UUID REFERENCES ordens_servico(id) ON DELETE CASCADE,
  tipo VARCHAR(10) NOT NULL, -- SERVICO | PECA
  produto_id UUID REFERENCES produtos(id),
  descricao VARCHAR(200) NOT NULL,
  quantidade NUMERIC(8,2) DEFAULT 1,
  valor_unitario NUMERIC(10,2) NOT NULL,
  valor_total NUMERIC(10,2) GENERATED ALWAYS AS (quantidade * valor_unitario) STORED
);

-- Notas Fiscais
CREATE TABLE notas_fiscais (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  numero INTEGER,
  serie VARCHAR(5) DEFAULT '001',
  modelo VARCHAR(10) DEFAULT 'NFS-e',
  cliente_id UUID REFERENCES clientes(id),
  os_id UUID REFERENCES ordens_servico(id),
  natureza_operacao VARCHAR(50),
  forma_pagamento VARCHAR(30),
  subtotal NUMERIC(10,2),
  desconto NUMERIC(10,2) DEFAULT 0,
  aliquota_iss NUMERIC(5,2) DEFAULT 5.00,
  valor_iss NUMERIC(10,2),
  valor_total NUMERIC(10,2),
  status VARCHAR(15) DEFAULT 'RASCUNHO',
  chave_acesso VARCHAR(50),
  protocolo VARCHAR(30),
  xml_retorno TEXT,
  pdf_url TEXT,
  observacoes TEXT,
  emitido_em TIMESTAMPTZ,
  criado_em TIMESTAMPTZ DEFAULT NOW()
);

-- Configurações da empresa
CREATE TABLE configuracoes (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  razao_social VARCHAR(150),
  nome_fantasia VARCHAR(100),
  cnpj VARCHAR(18),
  inscricao_estadual VARCHAR(30),
  inscricao_municipal VARCHAR(20),
  regime_tributario VARCHAR(30),
  cep VARCHAR(9),
  endereco VARCHAR(200),
  cidade VARCHAR(80),
  uf CHAR(2),
  telefone VARCHAR(15),
  email VARCHAR(120),
  ambiente_fiscal VARCHAR(15) DEFAULT 'HOMOLOGACAO',
  serie_nf VARCHAR(5) DEFAULT '001',
  proximo_numero_nf INTEGER DEFAULT 1,
  aliquota_iss NUMERIC(5,2) DEFAULT 5.00,
  cnae VARCHAR(20),
  codigo_ibge VARCHAR(10),
  estoque_limite_padrao INTEGER DEFAULT 5,
  alertas_email BOOLEAN DEFAULT TRUE,
  email_alertas VARCHAR(120),
  certificado_pfx_encrypted TEXT,  -- AES-256, nunca texto puro
  atualizado_em TIMESTAMPTZ DEFAULT NOW()
);
```

---

## ESTRUTURA DE ARQUIVOS

```
mecanicapro/
├── CLAUDE.md
├── docker-compose.yml
├── .env
│
├── frontend/                          ← Next.js 14
│   ├── app/
│   │   ├── (auth)/
│   │   │   └── login/page.tsx
│   │   ├── (dashboard)/
│   │   │   ├── layout.tsx
│   │   │   ├── page.tsx               ← dashboard
│   │   │   ├── usuarios/page.tsx
│   │   │   ├── clientes/
│   │   │   │   ├── page.tsx
│   │   │   │   ├── novo/page.tsx
│   │   │   │   └── [id]/page.tsx
│   │   │   ├── produtos/page.tsx
│   │   │   ├── os/
│   │   │   │   ├── page.tsx
│   │   │   │   ├── nova/page.tsx
│   │   │   │   └── [id]/page.tsx
│   │   │   ├── fiscal/
│   │   │   │   ├── emitir/page.tsx
│   │   │   │   └── historico/page.tsx
│   │   │   ├── empresa/page.tsx
│   │   │   └── configuracoes/page.tsx
│   ├── components/
│   │   ├── layout/
│   │   │   ├── Sidebar.tsx
│   │   │   ├── Topbar.tsx
│   │   │   └── AlertBanner.tsx
│   │   ├── ui/
│   │   │   ├── StatCard.tsx
│   │   │   ├── DataTable.tsx
│   │   │   ├── StatusPill.tsx
│   │   │   ├── StockBar.tsx
│   │   │   └── Toast.tsx
│   │   ├── forms/
│   │   │   ├── UsuarioForm.tsx
│   │   │   ├── ClienteForm.tsx
│   │   │   ├── ProdutoForm.tsx
│   │   │   ├── OSForm.tsx
│   │   │   └── NotaFiscalForm.tsx
│   │   └── dashboard/
│   │       ├── FaturamentoChart.tsx
│   │       └── EstoqueAlerts.tsx
│   ├── hooks/
│   │   ├── useToast.ts
│   │   ├── useAlertBanner.ts
│   │   └── useEstoqueAlerts.ts
│   ├── lib/
│   │   ├── api.ts                     ← axios instance com interceptors
│   │   ├── validations/               ← schemas Zod por módulo
│   │   └── formatters.ts              ← moeda, CPF, CNPJ, placa
│   └── styles/
│       ├── globals.css
│       └── tailwind.config.ts
│
└── backend/                           ← Laravel 11
    ├── app/
    │   ├── Http/
    │   │   ├── Controllers/
    │   │   │   ├── Auth/
    │   │   │   │   ├── LoginController.php
    │   │   │   │   ├── ForgotPasswordController.php
    │   │   │   │   └── ResetPasswordController.php
    │   │   │   ├── UsuarioController.php
    │   │   │   ├── ClienteController.php
    │   │   │   ├── ProdutoController.php
    │   │   │   ├── EstoqueController.php
    │   │   │   ├── OrdemServicoController.php
    │   │   │   ├── NotaFiscalController.php
    │   │   │   ├── DashboardController.php
    │   │   │   └── ConfiguracaoController.php
    │   │   ├── Middleware/
    │   │   │   └── EnsureTokenIsValid.php
    │   │   └── Resources/             ← API Resources (transforma models → JSON)
    │   │       ├── ClienteResource.php
    │   │       ├── ProdutoResource.php
    │   │       ├── OrdemServicoResource.php
    │   │       └── NotaFiscalResource.php
    │   ├── Models/
    │   │   ├── Usuario.php
    │   │   ├── Cliente.php
    │   │   ├── Produto.php
    │   │   ├── MovimentacaoEstoque.php
    │   │   ├── OrdemServico.php
    │   │   ├── OsItem.php
    │   │   ├── NotaFiscal.php
    │   │   └── Configuracao.php
    │   ├── Services/
    │   │   ├── NfeService.php         ← integração NFePHP / NFe.io
    │   │   ├── EstoqueService.php     ← lógica de baixa e alertas
    │   │   └── ClienteStatusService.php
    │   └── Jobs/                      ← filas (substitui Celery)
    │       ├── EnviarAlertaEstoque.php
    │       ├── EnviarEmailRecuperacao.php
    │       └── EnviarNpsCliente.php
    ├── routes/
    │   └── api.php
    └── database/
        └── migrations/
```

---

## REGRAS DE NEGÓCIO CRÍTICAS

### Estoque
```php
// app/Services/EstoqueService.php

public function getStatusEstoque(int $qtyAtual, int $qtyMinima): string
{
    if ($qtyAtual <= 0)                      return 'SEM_ESTOQUE';
    if ($qtyAtual < $qtyMinima * 0.4)        return 'CRITICO';
    if ($qtyAtual < $qtyMinima)              return 'BAIXO';
    return 'NORMAL';
}

public function baixarEstoqueOs(OrdemServico $os): void
{
    DB::transaction(function () use ($os) {
        foreach ($os->itens()->where('tipo', 'PECA')->get() as $item) {
            $produto = Produto::lockForUpdate()->find($item->produto_id);

            if ($produto->qty_atual < $item->quantidade) {
                throw new \Exception("Estoque insuficiente para: {$produto->nome}");
            }

            $produto->decrement('qty_atual', $item->quantidade);

            MovimentacaoEstoque::create([
                'produto_id'  => $produto->id,
                'tipo'        => 'SAIDA',
                'quantidade'  => $item->quantidade,
                'motivo'      => 'Baixa automática OS #' . $os->numero,
                'os_id'       => $os->id,
                'usuario_id'  => auth()->id(),
            ]);

            if ($produto->qty_atual < $produto->qty_minima) {
                EnviarAlertaEstoque::dispatch($produto);
            }
        }
    });
}
```

### Status de cliente
```php
// app/Services/ClienteStatusService.php

public function recalcular(string $clienteId): string
{
    $temDebito = OrdemServico::where('cliente_id', $clienteId)
        ->whereColumn('valor_pago', '<', 'valor_total')
        ->exists();

    if ($temDebito) {
        Cliente::where('id', $clienteId)->update(['status' => 'DEVEDOR']);
        return 'DEVEDOR';
    }

    $temOsAberta = OrdemServico::where('cliente_id', $clienteId)
        ->whereIn('status', ['ABERTA', 'EM_ANDAMENTO'])
        ->exists();

    $status = $temOsAberta ? 'OS_ABERTA' : 'REGULAR';
    Cliente::where('id', $clienteId)->update(['status' => $status]);
    return $status;
}
```

### Numeração de NF (transacional — evita duplicatas)
```php
// app/Services/NfeService.php

public function proximoNumeroNf(): int
{
    return DB::transaction(function () {
        $config = Configuracao::lockForUpdate()->first();
        $numero = $config->proximo_numero_nf;
        $config->increment('proximo_numero_nf');
        return $numero;
    });
}
```

### Integração NFePHP / NFe.io
```php
// app/Services/NfeService.php

use Illuminate\Support\Facades\Http;

public function emitirNfse(array $notaData): array
{
    $response = Http::withBasicAuth(config('services.nfeio.api_key'), '')
        ->post(config('services.nfeio.url') . '/serviceinvoices', $notaData);

    if ($response->failed()) {
        throw new \Exception('Erro na SEFAZ: ' . $response->json('message'));
    }

    return $response->json();
}
```

---

## VALIDAÇÕES BRASILEIRAS OBRIGATÓRIAS

```typescript
// lib/validations/br.ts

export function validarCPF(cpf: string): boolean {
  // algoritmo módulo 11 — implementar completo
}

export function validarCNPJ(cnpj: string): boolean {
  // algoritmo módulo 11 — implementar completo
}

export function formatarCPF(cpf: string): string {
  return cpf.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4');
}

export function formatarPlaca(placa: string): string {
  // aceitar tanto ABC-1234 quanto BRA2E19 (Mercosul)
}

export function formatarMoeda(valor: number): string {
  return new Intl.NumberFormat('pt-BR', {
    style: 'currency', currency: 'BRL'
  }).format(valor);
}
```

```php
// app/Rules/Cpf.php e app/Rules/Cnpj.php
// Implementar como Laravel Custom Rules para validação server-side
// Algoritmo módulo 11 idêntico ao frontend
```

---

## APIS EXTERNAS — INTEGRAÇÃO

### ViaCEP (frontend — preenchimento automático de endereço)
```typescript
async function buscarCEP(cep: string) {
  const res = await fetch(`https://viacep.com.br/ws/${cep}/json/`);
  const data = await res.json();
  if (data.erro) throw new Error('CEP não encontrado');
  return { endereco: data.logradouro, bairro: data.bairro,
           cidade: data.localidade, uf: data.uf };
}
```

### NFePHP (backend — emissão NFS-e / NF-e via SEFAZ)
```php
// Usar NFePHP para comunicação direta com SEFAZ quando necessário
// Usar NFe.io como abstração de API quando preferível
// Configurar ambiente em config/services.php:
// 'nfeio' => ['api_key' => env('NFEIO_API_KEY'), 'url' => env('NFEIO_URL')]
```

---

## INSTRUÇÕES DE IMPLEMENTAÇÃO

### Ordem de implementação (seguir rigorosamente)

```
PASSO 1: Setup do projeto
  - Inicializar Next.js 14 com TypeScript + Tailwind
  - Configurar CSS variables do design system em globals.css
  - Mapear variáveis no tailwind.config.ts
  - Instalar e configurar shadcn/ui com tema escuro
  - Setup Laravel 11 API: composer create-project laravel/laravel backend
  - Instalar pacotes: sanctum, spatie/permission, spatie/activitylog,
    stancl/tenancy, horizon, telescope (dev), dompdf, laravel-excel
  - Setup Docker Compose (postgres + redis)
  - Configurar .env (DB, Redis, queues, mail)

PASSO 2: Autenticação
  - Backend: POST /api/auth/login → Sanctum token + httpOnly cookie
  - Backend: POST /api/auth/logout, /api/auth/me
  - Backend: POST /api/auth/forgot-password + reset-password
  - Frontend: tela de login + middleware de rota Next.js

PASSO 3: Layout base
  - Sidebar.tsx com navegação e badges dinâmicos
  - Topbar.tsx com breadcrumb reativo
  - Sistema de toast (useToast hook)
  - AlertBanner.tsx (estoque crítico)

PASSO 4: Módulo Clientes
  - Migration + Model + Resource + Controller (CRUD completo)
  - Listagem com danger-row para devedores
  - Formulário com validação CPF/CNPJ + ViaCEP
  - Tela de detalhe com histórico

PASSO 5: Módulo Produtos/Estoque
  - Migration + Model + Resource + Controller
  - EstoqueService (lógica de status)
  - StockBar component com animação pulse
  - Job EnviarAlertaEstoque (queue)
  - Configuração de limite global/individual

PASSO 6: Módulo OS
  - Formulário com itens dinâmicos
  - Baixa automática de estoque ao concluir (EstoqueService)
  - Recálculo de status do cliente (ClienteStatusService)

PASSO 7: Módulo Fiscal
  - Formulário de NF com split-view
  - NfeService com NFePHP / NFe.io (ambiente homologação primeiro)
  - Histórico com filtros e download PDF (DomPDF)

PASSO 8: Dashboard
  - Queries de agregação no DashboardController
  - Recharts BarChart para faturamento
  - Stat cards com dados reais

PASSO 9: Configurações
  - Formulário empresa com upload de certificado (criptografar AES-256)
  - Configurações fiscais (série, alíquota, ambiente)
  - Configurações de alerta de estoque

PASSO 10: Polimento e testes
  - Feature tests (PHPUnit) nas regras de negócio críticas
  - Responsividade (tablet mínimo)
  - Loading states em todas as ações assíncronas
  - Error boundaries nas páginas Next.js
```

---

## CONSTRAINTS DE QUALIDADE

- **TypeScript strict mode** — sem `any` explícito no frontend
- **PHP 8.3 tipagem estrita** — `declare(strict_types=1)` em todos os arquivos PHP
- **Sem dados fake no código** — usar seeders separados para demo
- **Todas as mutations** exibem toast de sucesso ou erro
- **Toda tabela** tem estado de loading (skeleton) e empty state
- **Formulários** bloqueiam submit duplo (loading no botão)
- **Datas** sempre em `pt-BR` (`DD/MM/AAAA`)
- **Valores monetários** sempre formatados como `R$ X.XXX,XX`
- **CPF/CNPJ** sempre mascarados na exibição
- **Rotas protegidas** por role — middleware Sanctum + spatie/permission no backend,
  middleware Next.js no frontend
- **Mutations de estoque** são transacionais (`DB::transaction`)
- **Número de NF** gerado com `lockForUpdate()` para evitar duplicatas
- **Certificados A1** nunca armazenados sem criptografia AES-256
- **Filas Redis** para toda operação assíncrona — nunca bloquear o request principal

---

## REFERÊNCIA DE DESIGN HTML

O arquivo `oficina-system.html` contém o protótipo navegável completo com:
- Todas as telas implementadas em HTML/CSS/JS puro
- Paleta, tipografia e componentes finalizados
- Fluxo de navegação com transições
- Comportamentos de alerta de estoque e status

**Use este arquivo como fonte única de verdade para decisões visuais.**
Todo componente React deve produzir saída idêntica ao equivalente HTML do protótipo.

---

*MecânicaPro — Sistema SaaS de Gestão para Oficinas*
*Stack: Next.js 14 + Laravel 11 + PostgreSQL 16 + Redis 7*

---

## Regras de Gerenciamento de Contexto e Memória

> Prioridade máxima. Executar em TODA sessão, sem exceção, antes de qualquer outra ação.

---

### 1. Ao iniciar qualquer ação nesta sessão

- Antes de fazer qualquer outra coisa, leia o arquivo `PROGRESSO.md` na raiz do projeto.
  - Se não existir, crie-o.
- Se ele já tiver uma seção "PRÓXIMA TAREFA", trate-a como o ponto de retomada.
  - Não peça ao usuário para reexplicar o que já está registrado ali.
- Leia somente os arquivos indicados na seção "Contexto necessário" daquela tarefa.
  - Não abra o restante do repositório "por segurança".

---

### 2. Durante a execução de uma tarefa

A cada passo importante concluído (função implementada, bug corrigido, decisão de arquitetura tomada), atualize `PROGRESSO.md` imediatamente com:

- O que foi feito (1–3 linhas, direto ao ponto)
- Arquivos alterados
- Decisões que não sejam óbvias só de olhar o código (ex: "optei por X em vez de Y porque Z")

Não espere terminar a tarefa inteira para salvar. Se a energia acabar no meio, o próximo passo precisa ser recuperável.

---

### 3. Ao concluir uma tarefa

1. Marque a tarefa como concluída em `PROGRESSO.md`.
2. Leia a próxima tarefa em `TAREFAS.md` (ou backlog equivalente).
3. Reescreva a seção "Contexto necessário" contendo apenas o que é relevante para a próxima tarefa (arquivos, decisões, dependências).
   - Descarte explicitamente tudo que pertencia só à tarefa anterior.
4. Se a plataforma permitir (ex: Claude Code), recomende ativamente ao usuário rodar /compact ou /clear neste ponto — explicando que a tarefa anterior já está 100% documentada em disco e pode ser removida da conversa sem perda de informação.

---

### 4. Economia de tokens (regra contínua)

- Nunca recarregue um arquivo inteiro se só uma função/trecho é relevante — use leitura parcial ou busca (grep).
- Nunca repita no chat o conteúdo de arquivos já lidos; referencie por nome/caminho.
- Prefira resumos curtos a transcrições completas de código já existente.
- Se um arquivo de contexto crescer demais, resuma-o em `PROGRESSO.md` em vez de mantê-lo verboso.

---

### 5. Recuperação após interrupção

Se o usuário disser "continue de onde parou" (ou similar):

1. Ler `PROGRESSO.md`.
2. Identificar a última tarefa marcada como "em andamento" ou a "PRÓXIMA TAREFA".
3. Carregar apenas o contexto indicado nela.
4. Retomar sem pedir esclarecimentos adicionais, a menos que estritamente necessário.

---

### 6. Persistência desta regra

Esta regra deve permanecer salva neste arquivo (`CLAUDE.md`) e ser tratada como instrução permanente — não deve ser repetida pelo usuário a cada sessão.

---

#### Estrutura de referência

    projeto/
    ├── CLAUDE.md         ← esta regra (lida automaticamente pelo Claude Code)
    ├── PROGRESSO.md       ← estado atual, decisões, próxima tarefa
    └── TAREFAS.md         ← backlog / lista de tarefas

#### Modelo sugerido para PROGRESSO.md

    # Progresso do Projeto

    ## Última atualização
    [data e hora]

    ## Tarefa em andamento
    [descrição da tarefa atual]

    ## Contexto necessário
    - [arquivos relevantes]
    - [decisões importantes]

    ## Concluído
    - [x] [tarefas já finalizadas]

    ## Próxima tarefa
    [descrição da próxima tarefa]
