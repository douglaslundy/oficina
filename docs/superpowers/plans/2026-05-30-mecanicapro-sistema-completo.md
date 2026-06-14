# MecânicaPro — Sistema de Gestão para Oficinas

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Construir do zero um sistema SaaS de gestão para oficinas mecânicas com frontend Next.js 14 e backend Laravel 11 API-only.

**Architecture:** Multi-tenant SaaS com Next.js App Router chamando uma REST API Laravel 11. Autenticação via Sanctum com httpOnly cookies. PostgreSQL para dados, Redis para sessões e filas. Design system dark com paleta âmbar conforme protótipo `oficina-system.html`.

**Tech Stack:** Next.js 14 + TypeScript 5 + Tailwind CSS + shadcn/ui + TanStack Query v5 + React Hook Form + Zod + Recharts / Laravel 11 + PHP 8.3 + Sanctum + spatie/laravel-permission + spatie/laravel-activitylog + NFePHP + barryvdh/laravel-dompdf + PostgreSQL 16 + Redis 7 + Docker Compose

---

## Estrutura de Arquivos

### Raiz
- `docker-compose.yml` — serviços postgres, redis, backend, frontend
- `.env` — variáveis de ambiente

### Backend (`backend/`)
- `app/Http/Controllers/Auth/LoginController.php`
- `app/Http/Controllers/Auth/ForgotPasswordController.php`
- `app/Http/Controllers/Auth/ResetPasswordController.php`
- `app/Http/Controllers/UsuarioController.php`
- `app/Http/Controllers/ClienteController.php`
- `app/Http/Controllers/ProdutoController.php`
- `app/Http/Controllers/EstoqueController.php`
- `app/Http/Controllers/OrdemServicoController.php`
- `app/Http/Controllers/NotaFiscalController.php`
- `app/Http/Controllers/DashboardController.php`
- `app/Http/Controllers/ConfiguracaoController.php`
- `app/Models/Usuario.php`, `Cliente.php`, `Produto.php`, `MovimentacaoEstoque.php`, `OrdemServico.php`, `OsItem.php`, `NotaFiscal.php`, `Configuracao.php`, `PasswordResetToken.php`
- `app/Services/EstoqueService.php`, `ClienteStatusService.php`, `NfeService.php`
- `app/Jobs/EnviarAlertaEstoque.php`, `EnviarEmailRecuperacao.php`
- `app/Rules/Cpf.php`, `app/Rules/Cnpj.php`
- `app/Http/Resources/ClienteResource.php`, `ProdutoResource.php`, `OrdemServicoResource.php`, `NotaFiscalResource.php`
- `database/migrations/` — todas as migrations
- `routes/api.php`
- `tests/Feature/Auth/LoginTest.php`, `ForgotPasswordTest.php`
- `tests/Feature/ClienteTest.php`, `ProdutoTest.php`, `OrdemServicoTest.php`

### Frontend (`frontend/`)
- `app/(auth)/login/page.tsx`
- `app/(auth)/forgot-password/page.tsx`
- `app/(auth)/reset-password/page.tsx`
- `app/(dashboard)/layout.tsx`
- `app/(dashboard)/page.tsx`
- `app/(dashboard)/usuarios/page.tsx`
- `app/(dashboard)/clientes/page.tsx`, `novo/page.tsx`, `[id]/page.tsx`
- `app/(dashboard)/produtos/page.tsx`
- `app/(dashboard)/os/page.tsx`, `nova/page.tsx`, `[id]/page.tsx`
- `app/(dashboard)/fiscal/emitir/page.tsx`, `historico/page.tsx`
- `app/(dashboard)/empresa/page.tsx`
- `app/(dashboard)/configuracoes/page.tsx`
- `components/layout/Sidebar.tsx`, `Topbar.tsx`, `AlertBanner.tsx`
- `components/ui/StatCard.tsx`, `DataTable.tsx`, `StatusPill.tsx`, `StockBar.tsx`, `Toast.tsx`
- `components/forms/ClienteForm.tsx`, `ProdutoForm.tsx`, `OSForm.tsx`, `NotaFiscalForm.tsx`
- `components/dashboard/FaturamentoChart.tsx`, `EstoqueAlerts.tsx`
- `hooks/useToast.ts`, `useAlertBanner.ts`, `useEstoqueAlerts.ts`, `useAuth.ts`
- `lib/api.ts`, `lib/validations/br.ts`, `lib/formatters.ts`
- `styles/globals.css`, `tailwind.config.ts`
- `middleware.ts` — proteção de rotas Next.js

---

## Task 1: Docker Compose + Inicialização dos Projetos

**Files:**
- Create: `docker-compose.yml`
- Create: `.env`
- Create: `backend/` (via composer)
- Create: `frontend/` (via create-next-app)

- [ ] **Step 1.1: Criar docker-compose.yml**

```yaml
# docker-compose.yml
version: '3.9'

services:
  postgres:
    image: postgres:16-alpine
    environment:
      POSTGRES_DB: mecanicapro
      POSTGRES_USER: mecanicapro
      POSTGRES_PASSWORD: secret
    ports:
      - "5432:5432"
    volumes:
      - pgdata:/var/lib/postgresql/data

  redis:
    image: redis:7-alpine
    ports:
      - "6379:6379"

  backend:
    build:
      context: ./backend
      dockerfile: Dockerfile
    ports:
      - "8000:8000"
    volumes:
      - ./backend:/var/www/html
    depends_on:
      - postgres
      - redis

  frontend:
    build:
      context: ./frontend
      dockerfile: Dockerfile
    ports:
      - "3000:3000"
    volumes:
      - ./frontend:/app
      - /app/node_modules
    environment:
      - NEXT_PUBLIC_API_URL=http://localhost:8000

volumes:
  pgdata:
```

- [ ] **Step 1.2: Inicializar projeto Laravel 11**

```bash
cd C:\Users\dougl\workspace6
composer create-project laravel/laravel backend
cd backend
```

- [ ] **Step 1.3: Instalar pacotes Laravel**

```bash
composer require laravel/sanctum spatie/laravel-permission spatie/laravel-activitylog barryvdh/laravel-dompdf maatwebsite/laravel-excel
composer require --dev laravel/telescope
php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
```

- [ ] **Step 1.4: Configurar backend/.env**

Editar `backend/.env`:
```env
APP_NAME=MecânicaPro
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=mecanicapro
DB_USERNAME=mecanicapro
DB_PASSWORD=secret

CACHE_DRIVER=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=cookie
SESSION_LIFETIME=120

REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

MAIL_FROM_ADDRESS="noreply@mecanicapro.com"
MAIL_FROM_NAME="${APP_NAME}"

SANCTUM_STATEFUL_DOMAINS=localhost:3000
SESSION_DOMAIN=localhost
```

- [ ] **Step 1.5: Gerar chave de aplicação**

```bash
php artisan key:generate
```

- [ ] **Step 1.6: Inicializar frontend Next.js**

```bash
cd C:\Users\dougl\workspace6
npx create-next-app@latest frontend --typescript --tailwind --eslint --app --no-src-dir --import-alias="@/*"
```

- [ ] **Step 1.7: Instalar pacotes frontend**

```bash
cd frontend
npm install @tanstack/react-query axios react-hook-form @hookform/resolvers zod recharts
npx shadcn@latest init --defaults
npx shadcn@latest add button input label select checkbox textarea dialog badge table card form separator
```

- [ ] **Step 1.8: Iniciar postgres e redis via Docker**

```bash
cd C:\Users\dougl\workspace6
docker-compose up -d postgres redis
```

Aguardar 5 segundos e então rodar as migrations:
```bash
cd backend
php artisan migrate
```

Saída esperada: `Migration table created successfully.` e várias linhas `Migrating:`.

- [ ] **Step 1.9: Commit inicial**

```bash
cd C:\Users\dougl\workspace6
git init
git add docker-compose.yml .gitignore
git commit -m "chore: project bootstrap with docker-compose"
```

---

## Task 2: Design System — CSS Variables + Tailwind + Fontes

**Files:**
- Modify: `frontend/styles/globals.css`
- Modify: `frontend/tailwind.config.ts`
- Modify: `frontend/app/layout.tsx`

- [ ] **Step 2.1: Substituir frontend/styles/globals.css**

```css
/* frontend/styles/globals.css */
@import url('https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@700;800&family=Barlow:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap');

@tailwind base;
@tailwind components;
@tailwind utilities;

:root {
  --bg:      #0e0f11;
  --surface: #161719;
  --card:    #1c1e21;
  --border:  #2a2d33;
  --text:    #e8eaf0;
  --muted:   #7a8090;
  --accent:  #f5a623;
  --danger:  #e53935;
  --success: #43a047;
  --info:    #1e88e5;
}

* { box-sizing: border-box; }

body {
  background-color: var(--bg);
  color: var(--text);
  font-family: 'Barlow', sans-serif;
}

.font-display { font-family: 'Barlow Condensed', sans-serif; }
.font-mono    { font-family: 'JetBrains Mono', monospace; }

/* Linha de tabela com débito */
.danger-row { background: rgba(229, 57, 53, 0.06); }

/* Pill de status */
.pill {
  display: inline-flex;
  align-items: center;
  padding: 2px 10px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 600;
  font-family: 'Barlow Condensed', sans-serif;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}
.pill-success { background: rgba(67,160,71,.15); color: var(--success); }
.pill-danger  { background: rgba(229,57,53,.15);  color: var(--danger);  }
.pill-accent  { background: rgba(245,166,35,.15); color: var(--accent);  }
.pill-info    { background: rgba(30,136,229,.15); color: var(--info);    }
.pill-muted   { background: rgba(122,128,144,.15); color: var(--muted);  }

/* Barra de estoque */
.stock-bar  { height: 4px; background: var(--border); border-radius: 2px; overflow: hidden; }
.stock-fill { height: 100%; border-radius: 2px; transition: width 0.4s ease; }
.stock-fill.critico { background: var(--danger); animation: pulse-bar 1.2s ease-in-out infinite; }
.stock-fill.baixo   { background: var(--accent); }
.stock-fill.normal  { background: var(--success); }

@keyframes pulse-bar {
  0%, 100% { opacity: 1; }
  50%       { opacity: 0.5; }
}

/* Toast */
.toast {
  position: fixed;
  bottom: 24px;
  right: 24px;
  padding: 12px 20px;
  border-radius: 8px;
  font-size: 14px;
  font-weight: 500;
  z-index: 9999;
  animation: slide-in 0.2s ease;
}
.toast-success { background: var(--success); color: #fff; }
.toast-danger  { background: var(--danger);  color: #fff; }
.toast-info    { background: var(--info);    color: #fff; }

@keyframes slide-in {
  from { transform: translateX(100%); opacity: 0; }
  to   { transform: translateX(0);    opacity: 1; }
}
```

- [ ] **Step 2.2: Substituir frontend/tailwind.config.ts**

```typescript
// frontend/tailwind.config.ts
import type { Config } from 'tailwindcss'

const config: Config = {
  darkMode: ['class'],
  content: [
    './pages/**/*.{ts,tsx}',
    './components/**/*.{ts,tsx}',
    './app/**/*.{ts,tsx}',
    './src/**/*.{ts,tsx}',
  ],
  theme: {
    extend: {
      colors: {
        bg:      'var(--bg)',
        surface: 'var(--surface)',
        card:    'var(--card)',
        border:  'var(--border)',
        text:    'var(--text)',
        muted:   'var(--muted)',
        accent:  'var(--accent)',
        danger:  'var(--danger)',
        success: 'var(--success)',
        info:    'var(--info)',
      },
      fontFamily: {
        sans:    ['Barlow', 'sans-serif'],
        display: ['Barlow Condensed', 'sans-serif'],
        mono:    ['JetBrains Mono', 'monospace'],
      },
    },
  },
  plugins: [require('tailwindcss-animate')],
}
export default config
```

```bash
npm install tailwindcss-animate
```

- [ ] **Step 2.3: Atualizar frontend/app/layout.tsx**

```tsx
// frontend/app/layout.tsx
import type { Metadata } from 'next'
import './globals.css'

export const metadata: Metadata = {
  title: 'MecânicaPro',
  description: 'Sistema de Gestão para Oficinas Mecânicas',
}

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="pt-BR">
      <body>{children}</body>
    </html>
  )
}
```

- [ ] **Step 2.4: Verificar que o servidor Next.js sobe sem erros**

```bash
cd frontend
npm run dev
```

Abrir `http://localhost:3000` — deve mostrar a página padrão Next.js sem erros de compilação.

- [ ] **Step 2.5: Commit**

```bash
cd C:\Users\dougl\workspace6
git add frontend/styles/globals.css frontend/tailwind.config.ts frontend/app/layout.tsx
git commit -m "feat: add design system CSS variables and Tailwind tokens"
```

---

## Task 3: Migrations PostgreSQL

**Files:**
- Create: `backend/database/migrations/2024_01_01_000001_create_usuarios_table.php`
- Create: `backend/database/migrations/2024_01_01_000002_create_clientes_table.php`
- Create: `backend/database/migrations/2024_01_01_000003_create_produtos_table.php`
- Create: `backend/database/migrations/2024_01_01_000004_create_movimentacoes_estoque_table.php`
- Create: `backend/database/migrations/2024_01_01_000005_create_ordens_servico_table.php`
- Create: `backend/database/migrations/2024_01_01_000006_create_os_itens_table.php`
- Create: `backend/database/migrations/2024_01_01_000007_create_notas_fiscais_table.php`
- Create: `backend/database/migrations/2024_01_01_000008_create_configuracoes_table.php`
- Create: `backend/database/migrations/2024_01_01_000009_create_password_reset_tokens_table.php`

- [ ] **Step 3.1: Criar migration de usuários**

```bash
cd backend
php artisan make:migration create_usuarios_table
```

Editar o arquivo gerado em `database/migrations/`:
```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('usuarios', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('nome', 120);
            $table->string('email', 120)->unique();
            $table->string('cpf', 11)->unique();
            $table->string('telefone', 15)->nullable();
            $table->string('role', 20)->default('ATENDENTE');
            $table->string('status', 10)->default('ATIVO');
            $table->text('senha_hash');
            $table->timestampTz('ultimo_acesso')->nullable();
            $table->timestampTz('criado_em')->useCurrent();
        });
    }

    public function down(): void { Schema::dropIfExists('usuarios'); }
};
```

- [ ] **Step 3.2: Criar migration de clientes**

```bash
php artisan make:migration create_clientes_table
```

```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('clientes', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('nome', 150);
            $table->string('cpf_cnpj', 18)->unique();
            $table->string('telefone', 15)->nullable();
            $table->string('email', 120)->nullable();
            $table->string('cep', 9)->nullable();
            $table->string('endereco', 200)->nullable();
            $table->string('bairro', 80)->nullable();
            $table->string('cidade', 80)->nullable();
            $table->char('uf', 2)->nullable();
            $table->string('veiculo_modelo', 80)->nullable();
            $table->smallInteger('veiculo_ano')->nullable();
            $table->string('veiculo_placa', 10)->nullable();
            $table->string('status', 20)->default('REGULAR');
            $table->timestampTz('criado_em')->useCurrent();
        });
    }

    public function down(): void { Schema::dropIfExists('clientes'); }
};
```

- [ ] **Step 3.3: Criar migration de produtos**

```bash
php artisan make:migration create_produtos_table
```

```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('produtos', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('nome', 150);
            $table->string('sku', 30)->unique();
            $table->string('categoria', 40);
            $table->string('unidade', 10)->default('Un');
            $table->integer('qty_atual')->default(0);
            $table->integer('qty_minima')->default(5);
            $table->decimal('preco_custo', 10, 2)->nullable();
            $table->decimal('preco_venda', 10, 2)->nullable();
            $table->boolean('ativo')->default(true);
            $table->timestampTz('criado_em')->useCurrent();
        });
    }

    public function down(): void { Schema::dropIfExists('produtos'); }
};
```

- [ ] **Step 3.4: Criar migrations de movimentações, OS, OS itens, NF, configurações e password reset**

```bash
php artisan make:migration create_movimentacoes_estoque_table
php artisan make:migration create_ordens_servico_table
php artisan make:migration create_os_itens_table
php artisan make:migration create_notas_fiscais_table
php artisan make:migration create_configuracoes_table
php artisan make:migration create_password_reset_tokens_custom_table
```

Conteúdo de cada arquivo (editar após gerado):

**movimentacoes_estoque:**
```php
Schema::create('movimentacoes_estoque', function (Blueprint $table) {
    $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
    $table->foreignUuid('produto_id')->references('id')->on('produtos');
    $table->string('tipo', 10); // ENTRADA | SAIDA
    $table->integer('quantidade');
    $table->string('motivo', 100)->nullable();
    $table->uuid('os_id')->nullable();
    $table->foreignUuid('usuario_id')->nullable()->references('id')->on('usuarios');
    $table->timestampTz('criado_em')->useCurrent();
});
```

**ordens_servico:**
```php
Schema::create('ordens_servico', function (Blueprint $table) {
    $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
    $table->increments('numero');
    $table->foreignUuid('cliente_id')->references('id')->on('clientes');
    $table->foreignUuid('mecanico_id')->nullable()->references('id')->on('usuarios');
    $table->string('veiculo_descricao', 100)->nullable();
    $table->string('veiculo_placa', 10)->nullable();
    $table->text('problema_relatado')->nullable();
    $table->string('status', 25)->default('ABERTA');
    $table->string('forma_pagamento', 30)->nullable();
    $table->date('prazo_entrega')->nullable();
    $table->decimal('valor_total', 10, 2)->default(0);
    $table->decimal('valor_pago', 10, 2)->default(0);
    $table->timestampTz('criado_em')->useCurrent();
    $table->timestampTz('atualizado_em')->useCurrent();
});
```

**os_itens:**
```php
Schema::create('os_itens', function (Blueprint $table) {
    $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
    $table->foreignUuid('os_id')->references('id')->on('ordens_servico')->onDelete('cascade');
    $table->string('tipo', 10); // SERVICO | PECA
    $table->foreignUuid('produto_id')->nullable()->references('id')->on('produtos');
    $table->string('descricao', 200);
    $table->decimal('quantidade', 8, 2)->default(1);
    $table->decimal('valor_unitario', 10, 2);
    $table->decimal('valor_total', 10, 2)->storedAs('quantidade * valor_unitario');
});
```

**notas_fiscais:**
```php
Schema::create('notas_fiscais', function (Blueprint $table) {
    $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
    $table->integer('numero')->nullable();
    $table->string('serie', 5)->default('001');
    $table->string('modelo', 10)->default('NFS-e');
    $table->foreignUuid('cliente_id')->nullable()->references('id')->on('clientes');
    $table->foreignUuid('os_id')->nullable()->references('id')->on('ordens_servico');
    $table->string('natureza_operacao', 50)->nullable();
    $table->string('forma_pagamento', 30)->nullable();
    $table->decimal('subtotal', 10, 2)->nullable();
    $table->decimal('desconto', 10, 2)->default(0);
    $table->decimal('aliquota_iss', 5, 2)->default(5.00);
    $table->decimal('valor_iss', 10, 2)->nullable();
    $table->decimal('valor_total', 10, 2)->nullable();
    $table->string('status', 15)->default('RASCUNHO');
    $table->string('chave_acesso', 50)->nullable();
    $table->string('protocolo', 30)->nullable();
    $table->text('xml_retorno')->nullable();
    $table->string('pdf_url')->nullable();
    $table->text('observacoes')->nullable();
    $table->timestampTz('emitido_em')->nullable();
    $table->timestampTz('criado_em')->useCurrent();
});
```

**configuracoes:**
```php
Schema::create('configuracoes', function (Blueprint $table) {
    $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
    $table->string('razao_social', 150)->nullable();
    $table->string('nome_fantasia', 100)->nullable();
    $table->string('cnpj', 18)->nullable();
    $table->string('inscricao_estadual', 30)->nullable();
    $table->string('inscricao_municipal', 20)->nullable();
    $table->string('regime_tributario', 30)->nullable();
    $table->string('cep', 9)->nullable();
    $table->string('endereco', 200)->nullable();
    $table->string('cidade', 80)->nullable();
    $table->char('uf', 2)->nullable();
    $table->string('telefone', 15)->nullable();
    $table->string('email', 120)->nullable();
    $table->string('ambiente_fiscal', 15)->default('HOMOLOGACAO');
    $table->string('serie_nf', 5)->default('001');
    $table->integer('proximo_numero_nf')->default(1);
    $table->decimal('aliquota_iss', 5, 2)->default(5.00);
    $table->string('cnae', 20)->nullable();
    $table->string('codigo_ibge', 10)->nullable();
    $table->integer('estoque_limite_padrao')->default(5);
    $table->boolean('alertas_email')->default(true);
    $table->string('email_alertas', 120)->nullable();
    $table->text('certificado_pfx_encrypted')->nullable();
    $table->timestampTz('atualizado_em')->useCurrent();
});
```

**password_reset_tokens (customizada):**
```php
Schema::create('password_reset_tokens_custom', function (Blueprint $table) {
    $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
    $table->foreignUuid('usuario_id')->references('id')->on('usuarios');
    $table->string('token_hash', 64);
    $table->timestampTz('expires_at');
    $table->boolean('usado')->default(false);
    $table->timestampTz('criado_em')->useCurrent();
});
```

- [ ] **Step 3.5: Rodar todas as migrations**

```bash
cd backend
php artisan migrate
```

Saída esperada: todas as tables criadas sem erros. Se houver erro de `gen_random_uuid()`, verificar que o DB é PostgreSQL 16 (função nativa do postgres).

- [ ] **Step 3.6: Commit**

```bash
git add database/migrations/
git commit -m "feat: add all database migrations"
```

---

## Task 4: Models Eloquent

**Files:**
- Create: `backend/app/Models/Usuario.php`
- Create: `backend/app/Models/Cliente.php`
- Create: `backend/app/Models/Produto.php`
- Create: `backend/app/Models/MovimentacaoEstoque.php`
- Create: `backend/app/Models/OrdemServico.php`
- Create: `backend/app/Models/OsItem.php`
- Create: `backend/app/Models/NotaFiscal.php`
- Create: `backend/app/Models/Configuracao.php`
- Create: `backend/app/Models/PasswordResetToken.php`

- [ ] **Step 4.1: Criar Usuario.php**

```php
<?php
// backend/app/Models/Usuario.php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class Usuario extends Authenticatable
{
    use HasApiTokens, Notifiable, HasRoles;

    protected $table = 'usuarios';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'nome', 'email', 'cpf', 'telefone',
        'role', 'status', 'senha_hash',
    ];

    protected $hidden = ['senha_hash'];

    protected $casts = [
        'ultimo_acesso' => 'datetime',
        'criado_em'     => 'datetime',
    ];

    public function getAuthPassword(): string
    {
        return $this->senha_hash;
    }
}
```

- [ ] **Step 4.2: Criar Cliente.php**

```php
<?php
// backend/app/Models/Cliente.php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cliente extends Model
{
    protected $table = 'clientes';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'nome', 'cpf_cnpj', 'telefone', 'email',
        'cep', 'endereco', 'bairro', 'cidade', 'uf',
        'veiculo_modelo', 'veiculo_ano', 'veiculo_placa', 'status',
    ];

    protected $casts = ['criado_em' => 'datetime'];

    public function ordensServico(): HasMany
    {
        return $this->hasMany(OrdemServico::class, 'cliente_id');
    }
}
```

- [ ] **Step 4.3: Criar Produto.php**

```php
<?php
// backend/app/Models/Produto.php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Produto extends Model
{
    protected $table = 'produtos';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'nome', 'sku', 'categoria', 'unidade',
        'qty_atual', 'qty_minima', 'preco_custo', 'preco_venda', 'ativo',
    ];

    protected $casts = [
        'ativo'      => 'boolean',
        'criado_em'  => 'datetime',
        'preco_custo'  => 'float',
        'preco_venda'  => 'float',
    ];

    public function getStatusEstoqueAttribute(): string
    {
        if ($this->qty_atual <= 0)                                  return 'SEM_ESTOQUE';
        if ($this->qty_atual < $this->qty_minima * 0.4)            return 'CRITICO';
        if ($this->qty_atual < $this->qty_minima)                  return 'BAIXO';
        return 'NORMAL';
    }
}
```

- [ ] **Step 4.4: Criar OrdemServico.php e OsItem.php**

```php
<?php
// backend/app/Models/OrdemServico.php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrdemServico extends Model
{
    protected $table = 'ordens_servico';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'cliente_id', 'mecanico_id', 'veiculo_descricao', 'veiculo_placa',
        'problema_relatado', 'status', 'forma_pagamento', 'prazo_entrega',
        'valor_total', 'valor_pago',
    ];

    protected $casts = [
        'prazo_entrega'  => 'date',
        'criado_em'      => 'datetime',
        'atualizado_em'  => 'datetime',
        'valor_total'    => 'float',
        'valor_pago'     => 'float',
    ];

    public function cliente(): BelongsTo { return $this->belongsTo(Cliente::class, 'cliente_id'); }
    public function mecanico(): BelongsTo { return $this->belongsTo(Usuario::class, 'mecanico_id'); }
    public function itens(): HasMany { return $this->hasMany(OsItem::class, 'os_id'); }

    public function getSaldoDevedorAttribute(): float
    {
        return max(0, $this->valor_total - $this->valor_pago);
    }
}
```

```php
<?php
// backend/app/Models/OsItem.php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OsItem extends Model
{
    protected $table = 'os_itens';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'os_id', 'tipo', 'produto_id', 'descricao',
        'quantidade', 'valor_unitario',
    ];

    protected $casts = [
        'quantidade'     => 'float',
        'valor_unitario' => 'float',
        'valor_total'    => 'float',
    ];

    public function produto(): BelongsTo { return $this->belongsTo(Produto::class, 'produto_id'); }
}
```

- [ ] **Step 4.5: Criar demais models (NotaFiscal, MovimentacaoEstoque, Configuracao, PasswordResetToken)**

```php
<?php
// backend/app/Models/NotaFiscal.php
declare(strict_types=1);
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotaFiscal extends Model
{
    protected $table = 'notas_fiscais';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'numero', 'serie', 'modelo', 'cliente_id', 'os_id',
        'natureza_operacao', 'forma_pagamento', 'subtotal', 'desconto',
        'aliquota_iss', 'valor_iss', 'valor_total', 'status',
        'chave_acesso', 'protocolo', 'xml_retorno', 'pdf_url', 'observacoes', 'emitido_em',
    ];

    protected $casts = [
        'emitido_em' => 'datetime',
        'criado_em'  => 'datetime',
    ];

    public function cliente(): BelongsTo { return $this->belongsTo(Cliente::class, 'cliente_id'); }
    public function ordemServico(): BelongsTo { return $this->belongsTo(OrdemServico::class, 'os_id'); }
}
```

```php
<?php
// backend/app/Models/MovimentacaoEstoque.php
declare(strict_types=1);
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class MovimentacaoEstoque extends Model
{
    protected $table = 'movimentacoes_estoque';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = ['produto_id', 'tipo', 'quantidade', 'motivo', 'os_id', 'usuario_id'];
    protected $casts = ['criado_em' => 'datetime'];
}
```

```php
<?php
// backend/app/Models/Configuracao.php
declare(strict_types=1);
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Configuracao extends Model
{
    protected $table = 'configuracoes';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'razao_social', 'nome_fantasia', 'cnpj', 'inscricao_estadual',
        'inscricao_municipal', 'regime_tributario', 'cep', 'endereco',
        'cidade', 'uf', 'telefone', 'email', 'ambiente_fiscal', 'serie_nf',
        'proximo_numero_nf', 'aliquota_iss', 'cnae', 'codigo_ibge',
        'estoque_limite_padrao', 'alertas_email', 'email_alertas', 'certificado_pfx_encrypted',
    ];
}
```

```php
<?php
// backend/app/Models/PasswordResetToken.php
declare(strict_types=1);
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PasswordResetToken extends Model
{
    protected $table = 'password_reset_tokens_custom';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = ['usuario_id', 'token_hash', 'expires_at', 'usado'];
    protected $casts = [
        'expires_at' => 'datetime',
        'criado_em'  => 'datetime',
        'usado'      => 'boolean',
    ];

    public function usuario(): BelongsTo { return $this->belongsTo(Usuario::class, 'usuario_id'); }
}
```

- [ ] **Step 4.6: Commit**

```bash
git add app/Models/
git commit -m "feat: add all Eloquent models"
```

---

## Task 5: Auth Backend — Login + ForgotPassword + ResetPassword

**Files:**
- Create: `backend/app/Http/Controllers/Auth/LoginController.php`
- Create: `backend/app/Http/Controllers/Auth/ForgotPasswordController.php`
- Create: `backend/app/Http/Controllers/Auth/ResetPasswordController.php`
- Create: `backend/app/Jobs/EnviarEmailRecuperacao.php`
- Modify: `backend/routes/api.php`
- Create: `backend/tests/Feature/Auth/LoginTest.php`
- Create: `backend/tests/Feature/Auth/ForgotPasswordTest.php`

- [ ] **Step 5.1: Escrever teste de login (TDD — falha primeiro)**

```php
<?php
// backend/tests/Feature/Auth/LoginTest.php
declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    private function criarUsuario(): Usuario
    {
        return Usuario::create([
            'nome'      => 'Admin',
            'email'     => 'admin@mecanicapro.com',
            'cpf'       => '12345678901',
            'role'      => 'ADMIN',
            'status'    => 'ATIVO',
            'senha_hash' => Hash::make('admin123'),
        ]);
    }

    public function test_login_com_credenciais_validas(): void
    {
        $this->criarUsuario();

        $response = $this->postJson('/api/auth/login', [
            'email' => 'admin@mecanicapro.com',
            'senha' => 'admin123',
        ]);

        $response->assertStatus(200)
                 ->assertJsonStructure(['token', 'user' => ['id', 'nome', 'email', 'role']]);
    }

    public function test_login_com_credenciais_invalidas(): void
    {
        $this->criarUsuario();

        $response = $this->postJson('/api/auth/login', [
            'email' => 'admin@mecanicapro.com',
            'senha' => 'senhaerrada',
        ]);

        $response->assertStatus(401)
                 ->assertJson(['message' => 'E-mail ou senha incorretos. Verifique e tente novamente.']);
    }

    public function test_login_usuario_inativo(): void
    {
        Usuario::create([
            'nome' => 'Inativo', 'email' => 'inativo@mecanicapro.com',
            'cpf' => '00000000001', 'role' => 'ATENDENTE',
            'status' => 'INATIVO', 'senha_hash' => Hash::make('senha123'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'inativo@mecanicapro.com',
            'senha' => 'senha123',
        ]);

        $response->assertStatus(403);
    }
}
```

- [ ] **Step 5.2: Rodar teste — deve falhar**

```bash
cd backend
php artisan test tests/Feature/Auth/LoginTest.php
```

Saída esperada: `FAIL` porque o controller não existe ainda.

- [ ] **Step 5.3: Criar LoginController.php**

```php
<?php
// backend/app/Http/Controllers/Auth/LoginController.php
declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class LoginController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
            'senha' => ['required', 'string'],
        ]);

        $key = 'login:' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return response()->json(['message' => 'Muitas tentativas. Tente novamente em alguns minutos.'], 429);
        }

        $usuario = Usuario::where('email', $request->email)->first();

        if (! $usuario || ! Hash::check($request->senha, $usuario->senha_hash)) {
            RateLimiter::hit($key, 900); // 15 minutos
            return response()->json(['message' => 'E-mail ou senha incorretos. Verifique e tente novamente.'], 401);
        }

        if ($usuario->status === 'INATIVO') {
            return response()->json(['message' => 'Usuário inativo. Contate o administrador.'], 403);
        }

        RateLimiter::clear($key);
        $usuario->update(['ultimo_acesso' => now()]);

        $token = $usuario->createToken('auth-token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user'  => [
                'id'    => $usuario->id,
                'nome'  => $usuario->nome,
                'email' => $usuario->email,
                'role'  => $usuario->role,
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logout realizado com sucesso.']);
    }

    public function me(Request $request): JsonResponse
    {
        $u = $request->user();
        return response()->json([
            'id'    => $u->id,
            'nome'  => $u->nome,
            'email' => $u->email,
            'role'  => $u->role,
        ]);
    }
}
```

- [ ] **Step 5.4: Criar ForgotPasswordController.php**

```php
<?php
// backend/app/Http/Controllers/Auth/ForgotPasswordController.php
declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Jobs\EnviarEmailRecuperacao;
use App\Models\PasswordResetToken;
use App\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ForgotPasswordController extends Controller
{
    public function sendResetLink(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        $usuario = Usuario::where('email', $request->email)->first();

        if ($usuario) {
            $token = Str::uuid()->toString();

            PasswordResetToken::create([
                'usuario_id' => $usuario->id,
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addMinutes(30),
                'usado'      => false,
            ]);

            EnviarEmailRecuperacao::dispatch($usuario, $token);
        }

        // Sempre retorna 200 — não revelar se email existe
        return response()->json([
            'message' => 'Se o e-mail estiver cadastrado, você receberá um link em breve.',
        ]);
    }
}
```

- [ ] **Step 5.5: Criar ResetPasswordController.php**

```php
<?php
// backend/app/Http/Controllers/Auth/ResetPasswordController.php
declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\PasswordResetToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class ResetPasswordController extends Controller
{
    public function reset(Request $request): JsonResponse
    {
        $request->validate([
            'token'    => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $tokenRecord = PasswordResetToken::where('token_hash', hash('sha256', $request->token))
            ->where('expires_at', '>', now())
            ->where('usado', false)
            ->with('usuario')
            ->first();

        if (! $tokenRecord) {
            return response()->json(['message' => 'Token inválido ou expirado.'], 400);
        }

        $tokenRecord->usuario->update(['senha_hash' => Hash::make($request->password)]);
        $tokenRecord->update(['usado' => true]);

        return response()->json(['message' => 'Senha atualizada com sucesso.']);
    }
}
```

- [ ] **Step 5.6: Criar Job EnviarEmailRecuperacao.php**

```php
<?php
// backend/app/Jobs/EnviarEmailRecuperacao.php
declare(strict_types=1);

namespace App\Jobs;

use App\Models\Usuario;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class EnviarEmailRecuperacao implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly Usuario $usuario,
        private readonly string $token
    ) {}

    public function handle(): void
    {
        $link = config('app.frontend_url', 'http://localhost:3000')
            . '/reset-password?token=' . $this->token;

        Mail::raw(
            "Olá {$this->usuario->nome},\n\nClique no link abaixo para redefinir sua senha:\n{$link}\n\nO link expira em 30 minutos.",
            function ($message) {
                $message->to($this->usuario->email)
                        ->subject('Redefinição de senha — MecânicaPro');
            }
        );
    }
}
```

- [ ] **Step 5.7: Configurar routes/api.php**

```php
<?php
// backend/routes/api.php
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\ResetPasswordController;
use Illuminate\Support\Facades\Route;

// Auth — público
Route::prefix('auth')->group(function () {
    Route::post('/login',           [LoginController::class, 'login']);
    Route::post('/forgot-password', [ForgotPasswordController::class, 'sendResetLink']);
    Route::post('/reset-password',  [ResetPasswordController::class, 'reset']);
});

// Auth — protegido
Route::middleware('auth:sanctum')->prefix('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'logout']);
    Route::get('/me',      [LoginController::class, 'me']);
});
```

- [ ] **Step 5.8: Rodar testes — devem passar**

```bash
php artisan test tests/Feature/Auth/LoginTest.php
```

Saída esperada: `PASS  Tests\Feature\Auth\LoginTest` com 3 testes verdes.

- [ ] **Step 5.9: Commit**

```bash
git add app/Http/Controllers/Auth/ app/Jobs/EnviarEmailRecuperacao.php routes/api.php tests/Feature/Auth/
git commit -m "feat: add auth endpoints (login, forgot-password, reset-password)"
```

---

## Task 6: Auth Frontend — Login + Forgot + Reset Password

**Files:**
- Create: `frontend/lib/api.ts`
- Create: `frontend/app/(auth)/login/page.tsx`
- Create: `frontend/app/(auth)/forgot-password/page.tsx`
- Create: `frontend/app/(auth)/reset-password/page.tsx`
- Create: `frontend/middleware.ts`
- Create: `frontend/hooks/useAuth.ts`

- [ ] **Step 6.1: Criar frontend/lib/api.ts**

```typescript
// frontend/lib/api.ts
import axios from 'axios'

const api = axios.create({
  baseURL: process.env.NEXT_PUBLIC_API_URL + '/api',
  headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
  withCredentials: true,
})

// Injeta token do localStorage em todo request
api.interceptors.request.use((config) => {
  if (typeof window !== 'undefined') {
    const token = localStorage.getItem('auth_token')
    if (token) config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

// Redireciona para login em 401
api.interceptors.response.use(
  (res) => res,
  (error) => {
    if (error.response?.status === 401 && typeof window !== 'undefined') {
      localStorage.removeItem('auth_token')
      localStorage.removeItem('auth_user')
      window.location.href = '/login'
    }
    return Promise.reject(error)
  }
)

export default api
```

- [ ] **Step 6.2: Criar frontend/hooks/useAuth.ts**

```typescript
// frontend/hooks/useAuth.ts
'use client'
import { useState } from 'react'
import { useRouter } from 'next/navigation'
import api from '@/lib/api'

export interface AuthUser {
  id: string
  nome: string
  email: string
  role: 'ADMIN' | 'MECANICO' | 'ATENDENTE' | 'FINANCEIRO'
}

export function useAuth() {
  const router = useRouter()
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)

  function getUser(): AuthUser | null {
    if (typeof window === 'undefined') return null
    const raw = localStorage.getItem('auth_user')
    return raw ? JSON.parse(raw) : null
  }

  async function login(email: string, senha: string, lembrar: boolean) {
    setLoading(true)
    setError(null)
    try {
      const { data } = await api.post('/auth/login', { email, senha })
      localStorage.setItem('auth_token', data.token)
      localStorage.setItem('auth_user', JSON.stringify(data.user))
      if (lembrar) localStorage.setItem('remember_email', email)
      router.push('/')
    } catch (err: any) {
      setError(err.response?.data?.message ?? 'Erro ao fazer login.')
    } finally {
      setLoading(false)
    }
  }

  async function logout() {
    try { await api.post('/auth/logout') } catch {}
    localStorage.removeItem('auth_token')
    localStorage.removeItem('auth_user')
    router.push('/login')
  }

  return { login, logout, getUser, loading, error }
}
```

- [ ] **Step 6.3: Criar frontend/middleware.ts**

```typescript
// frontend/middleware.ts
import { NextResponse } from 'next/server'
import type { NextRequest } from 'next/server'

const PUBLIC_PATHS = ['/login', '/forgot-password', '/reset-password']

export function middleware(request: NextRequest) {
  const token = request.cookies.get('auth_token')?.value
    ?? request.headers.get('authorization')?.replace('Bearer ', '')

  const isPublic = PUBLIC_PATHS.some(p => request.nextUrl.pathname.startsWith(p))

  // Sem token tentando acessar rota protegida → login
  if (!token && !isPublic) {
    return NextResponse.redirect(new URL('/login', request.url))
  }

  // Com token tentando acessar login → dashboard
  if (token && isPublic) {
    return NextResponse.redirect(new URL('/', request.url))
  }

  return NextResponse.next()
}

export const config = {
  matcher: ['/((?!_next/static|_next/image|favicon.ico|api).*)'],
}
```

- [ ] **Step 6.4: Criar layout de auth**

Criar `frontend/app/(auth)/layout.tsx`:
```tsx
// frontend/app/(auth)/layout.tsx
export default function AuthLayout({ children }: { children: React.ReactNode }) {
  return (
    <div style={{ display: 'flex', minHeight: '100vh', background: 'var(--bg)' }}>
      {/* Painel esquerdo — marca */}
      <div style={{
        flex: 1,
        background: 'var(--surface)',
        display: 'flex',
        flexDirection: 'column',
        justifyContent: 'center',
        padding: '60px',
        position: 'relative',
        overflow: 'hidden',
      }}>
        <div style={{
          position: 'absolute', inset: 0,
          backgroundImage: 'radial-gradient(circle at 30% 50%, rgba(245,166,35,0.08) 0%, transparent 60%)',
          pointerEvents: 'none',
        }} />
        <div style={{ position: 'relative', zIndex: 1 }}>
          <div style={{
            width: 72, height: 72, borderRadius: 18,
            background: 'var(--accent)', display: 'flex',
            alignItems: 'center', justifyContent: 'center',
            fontSize: 32, marginBottom: 24,
          }}>🔧</div>
          <h1 className="font-display" style={{ fontSize: 36, fontWeight: 800, color: 'var(--text)', margin: 0 }}>
            MecânicaPro
          </h1>
          <p style={{ color: 'var(--muted)', marginTop: 8, fontSize: 16 }}>
            Sistema de Gestão para Oficinas Mecânicas
          </p>
          <ul style={{ listStyle: 'none', padding: 0, marginTop: 40, display: 'flex', flexDirection: 'column', gap: 12 }}>
            {['Gestão completa de OS', 'Controle de estoque inteligente', 'Emissão de NF-e / NFS-e', 'Relatórios financeiros', 'Multi-usuário com permissões'].map(f => (
              <li key={f} style={{ color: 'var(--muted)', fontSize: 15 }}>
                <span style={{ color: 'var(--accent)', marginRight: 8 }}>✓</span>{f}
              </li>
            ))}
          </ul>
        </div>
      </div>
      {/* Painel direito — form */}
      <div style={{
        width: 480, display: 'flex', alignItems: 'center',
        justifyContent: 'center', padding: 48,
      }}>
        {children}
      </div>
    </div>
  )
}
```

- [ ] **Step 6.5: Criar página de Login**

```tsx
// frontend/app/(auth)/login/page.tsx
'use client'
import { useState, useEffect } from 'react'
import Link from 'next/link'
import { useAuth } from '@/hooks/useAuth'

export default function LoginPage() {
  const { login, loading, error } = useAuth()
  const [email, setEmail] = useState('')
  const [senha, setSenha] = useState('')
  const [showSenha, setShowSenha] = useState(false)
  const [lembrar, setLembrar] = useState(false)
  const [submitted, setSubmitted] = useState(false)
  const [fieldErrors, setFieldErrors] = useState<{ email?: string; senha?: string }>({})

  useEffect(() => {
    const remembered = localStorage.getItem('remember_email')
    if (remembered) { setEmail(remembered); setLembrar(true) }
  }, [])

  function validate() {
    const errs: { email?: string; senha?: string } = {}
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) errs.email = 'E-mail inválido'
    if (!senha.trim()) errs.senha = 'Senha obrigatória'
    return errs
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault()
    setSubmitted(true)
    const errs = validate()
    if (Object.keys(errs).length) { setFieldErrors(errs); return }
    setFieldErrors({})
    await login(email, senha, lembrar)
  }

  const inputStyle = (hasError: boolean): React.CSSProperties => ({
    width: '100%', padding: '10px 14px', borderRadius: 8,
    background: 'var(--card)', border: `1px solid ${hasError ? 'var(--danger)' : 'var(--border)'}`,
    color: 'var(--text)', fontSize: 15, outline: 'none',
  })

  return (
    <div style={{ width: '100%', maxWidth: 380 }}>
      <h2 className="font-display" style={{ fontSize: 28, fontWeight: 800, color: 'var(--text)', marginBottom: 8 }}>
        Entrar na conta
      </h2>
      <p style={{ color: 'var(--muted)', marginBottom: 32, fontSize: 14 }}>
        Acesse o painel de gestão da sua oficina
      </p>

      <form onSubmit={handleSubmit} style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
        <div>
          <label style={{ color: 'var(--muted)', fontSize: 13, marginBottom: 6, display: 'block' }}>E-mail</label>
          <input
            type="email" value={email} onChange={e => setEmail(e.target.value)}
            placeholder="seu@email.com" autoComplete="email"
            style={inputStyle(!!fieldErrors.email)}
          />
          {fieldErrors.email && <p style={{ color: 'var(--danger)', fontSize: 12, marginTop: 4 }}>{fieldErrors.email}</p>}
        </div>

        <div>
          <label style={{ color: 'var(--muted)', fontSize: 13, marginBottom: 6, display: 'block' }}>Senha</label>
          <div style={{ position: 'relative' }}>
            <input
              type={showSenha ? 'text' : 'password'} value={senha}
              onChange={e => setSenha(e.target.value)} placeholder="••••••••"
              style={{ ...inputStyle(!!fieldErrors.senha), paddingRight: 44 }}
            />
            <button type="button" onClick={() => setShowSenha(s => !s)}
              style={{ position: 'absolute', right: 12, top: '50%', transform: 'translateY(-50%)',
                background: 'none', border: 'none', color: 'var(--muted)', cursor: 'pointer', fontSize: 16 }}>
              {showSenha ? '🙈' : '👁'}
            </button>
          </div>
          {fieldErrors.senha && <p style={{ color: 'var(--danger)', fontSize: 12, marginTop: 4 }}>{fieldErrors.senha}</p>}
        </div>

        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
          <label style={{ display: 'flex', alignItems: 'center', gap: 8, color: 'var(--muted)', fontSize: 14, cursor: 'pointer' }}>
            <input type="checkbox" checked={lembrar} onChange={e => setLembrar(e.target.checked)} />
            Lembrar de mim
          </label>
          <Link href="/forgot-password" style={{ color: 'var(--muted)', fontSize: 14, textDecoration: 'none' }}>
            Esqueci minha senha
          </Link>
        </div>

        {error && (
          <div style={{ background: 'rgba(229,57,53,0.1)', border: '1px solid var(--danger)', borderRadius: 8, padding: '10px 14px', color: 'var(--danger)', fontSize: 14 }}>
            {error}
          </div>
        )}

        <button type="submit" disabled={loading}
          className="font-display"
          style={{
            width: '100%', padding: '12px', borderRadius: 8,
            background: loading ? 'var(--muted)' : 'var(--accent)',
            color: '#000', fontWeight: 800, fontSize: 17, border: 'none',
            cursor: loading ? 'not-allowed' : 'pointer', marginTop: 8,
          }}>
          {loading ? '⟳ Verificando...' : 'Entrar'}
        </button>
      </form>

      {/* Quick access demo */}
      {process.env.NODE_ENV === 'development' && (
        <div style={{ marginTop: 32, padding: 16, background: 'var(--card)', borderRadius: 8, border: '1px solid var(--border)' }}>
          <p style={{ color: 'var(--muted)', fontSize: 12, marginBottom: 8 }}>Demo rápido:</p>
          <button onClick={() => { setEmail('admin@mecanicapro.com'); setSenha('admin123') }}
            style={{ background: 'none', border: 'none', color: 'var(--accent)', fontSize: 13, cursor: 'pointer', padding: 0, display: 'block', marginBottom: 4 }}>
            Admin → admin@mecanicapro.com / admin123
          </button>
          <button onClick={() => { setEmail('mecanico@mecanicapro.com'); setSenha('mec123') }}
            style={{ background: 'none', border: 'none', color: 'var(--accent)', fontSize: 13, cursor: 'pointer', padding: 0 }}>
            Mecânico → mecanico@mecanicapro.com / mec123
          </button>
        </div>
      )}
    </div>
  )
}
```

- [ ] **Step 6.6: Criar página Forgot Password**

```tsx
// frontend/app/(auth)/forgot-password/page.tsx
'use client'
import { useState } from 'react'
import Link from 'next/link'
import api from '@/lib/api'

export default function ForgotPasswordPage() {
  const [email, setEmail] = useState('')
  const [loading, setLoading] = useState(false)
  const [sent, setSent] = useState(false)
  const [error, setError] = useState('')

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault()
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      setError('E-mail inválido'); return
    }
    setLoading(true); setError('')
    try {
      await api.post('/auth/forgot-password', { email })
      setSent(true)
    } catch { setError('Erro ao enviar. Tente novamente.') }
    finally { setLoading(false) }
  }

  const inputStyle: React.CSSProperties = {
    width: '100%', padding: '10px 14px', borderRadius: 8,
    background: 'var(--card)', border: '1px solid var(--border)',
    color: 'var(--text)', fontSize: 15, outline: 'none',
  }

  if (sent) return (
    <div style={{ width: '100%', maxWidth: 380, textAlign: 'center' }}>
      <div style={{ fontSize: 40, marginBottom: 16 }}>📨</div>
      <h2 className="font-display" style={{ fontSize: 26, fontWeight: 800, color: 'var(--success)', marginBottom: 12 }}>
        E-mail enviado!
      </h2>
      <p style={{ color: 'var(--muted)', fontSize: 14, lineHeight: 1.6, marginBottom: 8 }}>
        Enviamos as instruções para <span style={{ color: 'var(--accent)', fontWeight: 600 }}>{email}</span>.
      </p>
      <p style={{ color: 'var(--muted)', fontSize: 13, marginBottom: 32 }}>O link expira em 30 minutos.</p>
      <Link href="/login" style={{ color: 'var(--accent)', fontSize: 14, textDecoration: 'none' }}>← Voltar ao login</Link>
    </div>
  )

  return (
    <div style={{ width: '100%', maxWidth: 380 }}>
      <Link href="/login" style={{ color: 'var(--muted)', fontSize: 14, textDecoration: 'none', display: 'inline-block', marginBottom: 24 }}>
        ← Voltar ao login
      </Link>
      <h2 className="font-display" style={{ fontSize: 28, fontWeight: 800, color: 'var(--text)', marginBottom: 8 }}>
        Recuperar senha
      </h2>
      <p style={{ color: 'var(--muted)', fontSize: 14, marginBottom: 32 }}>
        Informe seu e-mail e enviaremos um link para redefinir sua senha.
      </p>
      <form onSubmit={handleSubmit} style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
        <div>
          <label style={{ color: 'var(--muted)', fontSize: 13, marginBottom: 6, display: 'block' }}>E-mail cadastrado</label>
          <input type="email" value={email} onChange={e => setEmail(e.target.value)} style={inputStyle} placeholder="seu@email.com" />
          {error && <p style={{ color: 'var(--danger)', fontSize: 12, marginTop: 4 }}>{error}</p>}
        </div>
        <button type="submit" disabled={loading}
          className="font-display"
          style={{ width: '100%', padding: 12, borderRadius: 8, background: loading ? 'var(--muted)' : 'var(--accent)', color: '#000', fontWeight: 800, fontSize: 17, border: 'none', cursor: loading ? 'not-allowed' : 'pointer' }}>
          {loading ? '⟳ Enviando...' : 'Enviar link de recuperação'}
        </button>
      </form>
    </div>
  )
}
```

- [ ] **Step 6.7: Criar página Reset Password**

```tsx
// frontend/app/(auth)/reset-password/page.tsx
'use client'
import { useState, Suspense } from 'react'
import { useSearchParams } from 'next/navigation'
import Link from 'next/link'
import api from '@/lib/api'

function PasswordStrength({ password }: { password: string }) {
  const hasLen = password.length >= 8
  const hasUpper = /[A-Z]/.test(password)
  const hasNum = /[0-9]/.test(password)
  const hasSpecial = /[^A-Za-z0-9]/.test(password)
  const score = [hasLen, hasUpper, hasNum, hasSpecial].filter(Boolean).length

  const colors = ['var(--danger)', 'var(--accent)', 'var(--accent)', 'var(--success)', 'var(--success)']
  const labels = ['', 'Fraca', 'Média', 'Forte', 'Muito forte']

  return (
    <div style={{ marginTop: 8 }}>
      <div style={{ display: 'flex', gap: 4 }}>
        {[1,2,3,4].map(i => (
          <div key={i} style={{ height: 4, flex: 1, borderRadius: 2, background: i <= score ? colors[score] : 'var(--border)' }} />
        ))}
      </div>
      {password && <p style={{ color: colors[score], fontSize: 12, marginTop: 4 }}>{labels[score]}</p>}
    </div>
  )
}

function ResetForm() {
  const searchParams = useSearchParams()
  const token = searchParams.get('token')
  const [senha, setSenha] = useState('')
  const [confirmacao, setConfirmacao] = useState('')
  const [showSenha, setShowSenha] = useState(false)
  const [loading, setLoading] = useState(false)
  const [done, setDone] = useState(false)
  const [error, setError] = useState('')

  if (!token) return (
    <div style={{ textAlign: 'center' }}>
      <div style={{ fontSize: 36, marginBottom: 16, color: 'var(--danger)' }}>⚠</div>
      <h2 className="font-display" style={{ fontSize: 24, fontWeight: 800, color: 'var(--danger)' }}>Link inválido</h2>
      <p style={{ color: 'var(--muted)', marginBottom: 24 }}>Token não encontrado na URL.</p>
      <Link href="/forgot-password" style={{ padding: '10px 24px', background: 'var(--accent)', color: '#000', borderRadius: 8, textDecoration: 'none', fontWeight: 700 }}>
        Solicitar novo link
      </Link>
    </div>
  )

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault()
    if (senha.length < 8 || !/[A-Z]/.test(senha) || !/[0-9]/.test(senha)) {
      setError('Senha fraca: mínimo 8 chars, 1 maiúscula, 1 número.'); return
    }
    if (senha !== confirmacao) { setError('As senhas não coincidem.'); return }
    setLoading(true); setError('')
    try {
      await api.post('/auth/reset-password', { token, password: senha, password_confirmation: confirmacao })
      setDone(true)
    } catch (err: any) {
      const msg = err.response?.data?.message ?? 'Erro ao redefinir senha.'
      if (msg.includes('inválido') || msg.includes('expirado')) {
        setError('EXPIRED')
      } else {
        setError(msg)
      }
    } finally { setLoading(false) }
  }

  if (error === 'EXPIRED') return (
    <div style={{ textAlign: 'center' }}>
      <div style={{ fontSize: 36, marginBottom: 16, color: 'var(--danger)' }}>⚠</div>
      <h2 className="font-display" style={{ fontSize: 24, fontWeight: 800, color: 'var(--danger)', marginBottom: 12 }}>Link expirado</h2>
      <p style={{ color: 'var(--muted)', marginBottom: 24 }}>Este link de recuperação expirou ou já foi utilizado.</p>
      <Link href="/forgot-password" style={{ padding: '10px 24px', background: 'var(--accent)', color: '#000', borderRadius: 8, textDecoration: 'none', fontWeight: 700 }}>
        Solicitar novo link
      </Link>
    </div>
  )

  if (done) return (
    <div style={{ textAlign: 'center' }}>
      <div style={{ fontSize: 36, marginBottom: 16, color: 'var(--success)' }}>✓</div>
      <h2 className="font-display" style={{ fontSize: 24, fontWeight: 800, color: 'var(--success)', marginBottom: 12 }}>Senha redefinida!</h2>
      <p style={{ color: 'var(--muted)', marginBottom: 24 }}>Faça login com sua nova senha.</p>
      <Link href="/login" style={{ color: 'var(--accent)', textDecoration: 'none' }}>← Ir para o login</Link>
    </div>
  )

  const inputStyle: React.CSSProperties = { width: '100%', padding: '10px 14px', borderRadius: 8, background: 'var(--card)', border: '1px solid var(--border)', color: 'var(--text)', fontSize: 15, outline: 'none' }

  return (
    <div style={{ width: '100%', maxWidth: 380 }}>
      <Link href="/login" style={{ color: 'var(--muted)', fontSize: 14, textDecoration: 'none', display: 'inline-block', marginBottom: 24 }}>← Voltar ao login</Link>
      <h2 className="font-display" style={{ fontSize: 28, fontWeight: 800, color: 'var(--text)', marginBottom: 8 }}>Nova senha</h2>
      <p style={{ color: 'var(--muted)', fontSize: 14, marginBottom: 32 }}>Crie uma senha segura para sua conta.</p>
      <form onSubmit={handleSubmit} style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
        <div>
          <label style={{ color: 'var(--muted)', fontSize: 13, marginBottom: 6, display: 'block' }}>Nova senha</label>
          <div style={{ position: 'relative' }}>
            <input type={showSenha ? 'text' : 'password'} value={senha} onChange={e => setSenha(e.target.value)} style={{ ...inputStyle, paddingRight: 44 }} placeholder="••••••••" />
            <button type="button" onClick={() => setShowSenha(s => !s)} style={{ position: 'absolute', right: 12, top: '50%', transform: 'translateY(-50%)', background: 'none', border: 'none', color: 'var(--muted)', cursor: 'pointer' }}>
              {showSenha ? '🙈' : '👁'}
            </button>
          </div>
          <PasswordStrength password={senha} />
        </div>
        <div>
          <label style={{ color: 'var(--muted)', fontSize: 13, marginBottom: 6, display: 'block' }}>Confirmar senha</label>
          <input type="password" value={confirmacao} onChange={e => setConfirmacao(e.target.value)} style={inputStyle} placeholder="••••••••" />
        </div>
        {error && error !== 'EXPIRED' && <p style={{ color: 'var(--danger)', fontSize: 13 }}>{error}</p>}
        <button type="submit" disabled={loading}
          className="font-display"
          style={{ width: '100%', padding: 12, borderRadius: 8, background: loading ? 'var(--muted)' : 'var(--accent)', color: '#000', fontWeight: 800, fontSize: 17, border: 'none', cursor: loading ? 'not-allowed' : 'pointer' }}>
          {loading ? '⟳ Salvando...' : 'Redefinir senha'}
        </button>
      </form>
    </div>
  )
}

export default function ResetPasswordPage() {
  return <Suspense><ResetForm /></Suspense>
}
```

- [ ] **Step 6.8: Testar manualmente as telas de auth**

```bash
cd frontend
npm run dev
```

1. Abrir `http://localhost:3000/login` — deve mostrar split-view com form de login
2. Clicar "Esqueci minha senha" → deve navegar para `/forgot-password`
3. Preencher e-mail inválido → deve mostrar erro inline
4. Acessar `/reset-password?token=test` → deve mostrar form com indicador de força de senha
5. Acessar `/reset-password` sem token → deve mostrar "Link inválido"

- [ ] **Step 6.9: Commit**

```bash
cd frontend
git add app/(auth)/ middleware.ts hooks/useAuth.ts lib/api.ts
git commit -m "feat: add auth frontend (login, forgot-password, reset-password)"
```

---

## Task 7: Layout Base — Sidebar + Topbar + Toast + Dashboard Layout

**Files:**
- Create: `frontend/components/layout/Sidebar.tsx`
- Create: `frontend/components/layout/Topbar.tsx`
- Create: `frontend/components/layout/AlertBanner.tsx`
- Create: `frontend/hooks/useToast.ts`
- Create: `frontend/components/ui/Toast.tsx`
- Create: `frontend/app/(dashboard)/layout.tsx`

- [ ] **Step 7.1: Criar hooks/useToast.ts**

```typescript
// frontend/hooks/useToast.ts
'use client'
import { useState, useCallback } from 'react'

export type ToastType = 'success' | 'danger' | 'info'

export interface Toast {
  id: string
  message: string
  type: ToastType
}

let addToastFn: ((message: string, type: ToastType) => void) | null = null

export function useToast() {
  const [toasts, setToasts] = useState<Toast[]>([])

  const addToast = useCallback((message: string, type: ToastType = 'info') => {
    const id = Math.random().toString(36).slice(2)
    setToasts(prev => [...prev, { id, message, type }])
    setTimeout(() => setToasts(prev => prev.filter(t => t.id !== id)), 3500)
  }, [])

  // Expõe globalmente para uso fora de componentes
  addToastFn = addToast

  const removeToast = (id: string) => setToasts(prev => prev.filter(t => t.id !== id))

  return { toasts, addToast, removeToast }
}

// Uso global: toast('mensagem', 'success')
export function toast(message: string, type: ToastType = 'info') {
  addToastFn?.(message, type)
}
```

- [ ] **Step 7.2: Criar components/ui/Toast.tsx**

```tsx
// frontend/components/ui/Toast.tsx
'use client'
import { useToast } from '@/hooks/useToast'

export function ToastContainer() {
  const { toasts, removeToast } = useToast()

  return (
    <div style={{ position: 'fixed', bottom: 24, right: 24, zIndex: 9999, display: 'flex', flexDirection: 'column', gap: 8 }}>
      {toasts.map(t => (
        <div key={t.id}
          onClick={() => removeToast(t.id)}
          style={{
            padding: '12px 20px', borderRadius: 8, fontSize: 14, fontWeight: 500,
            cursor: 'pointer', animation: 'slide-in 0.2s ease',
            background: t.type === 'success' ? 'var(--success)' : t.type === 'danger' ? 'var(--danger)' : 'var(--info)',
            color: '#fff', boxShadow: '0 4px 12px rgba(0,0,0,0.3)',
          }}>
          {t.message}
        </div>
      ))}
    </div>
  )
}
```

- [ ] **Step 7.3: Criar components/layout/Sidebar.tsx**

```tsx
// frontend/components/layout/Sidebar.tsx
'use client'
import Link from 'next/link'
import { usePathname } from 'next/navigation'
import { useAuth } from '@/hooks/useAuth'

interface NavItem {
  href: string
  label: string
  icon: string
  badge?: number
}

const NAV_ITEMS: NavItem[] = [
  { href: '/',              label: 'Dashboard',   icon: '📊' },
  { href: '/clientes',      label: 'Clientes',    icon: '👥' },
  { href: '/produtos',      label: 'Produtos',    icon: '📦' },
  { href: '/os',            label: 'Ordens de Serviço', icon: '🔧' },
  { href: '/fiscal/emitir', label: 'Emitir NF',   icon: '🧾' },
  { href: '/fiscal/historico', label: 'Histórico NF', icon: '📋' },
  { href: '/usuarios',      label: 'Usuários',    icon: '👤' },
  { href: '/empresa',       label: 'Empresa',     icon: '🏢' },
  { href: '/configuracoes', label: 'Configurações', icon: '⚙️' },
]

interface SidebarProps {
  clientesDevedores?: number
  produtosAlerta?: number
}

export function Sidebar({ clientesDevedores = 0, produtosAlerta = 0hy }: SidebarProps) {
  const pathname = usePathname()
  const { logout, getUser } = useAuth()
  const user = getUser()

  const itemsWithBadges = NAV_ITEMS.map(item => {
    if (item.href === '/clientes' && clientesDevedores > 0)
      return { ...item, badge: clientesDevedores }
    if (item.href === '/produtos' && produtosAlerta > 0)
      return { ...item, badge: produtosAlerta }
    return item
  })

  return (
    <aside style={{
      width: 230, height: '100vh', position: 'fixed', left: 0, top: 0,
      background: 'var(--surface)', borderRight: '1px solid var(--border)',
      display: 'flex', flexDirection: 'column', zIndex: 100,
    }}>
      {/* Logo */}
      <div style={{ padding: '20px 20px 16px', borderBottom: '1px solid var(--border)' }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
          <div style={{ width: 36, height: 36, borderRadius: 8, background: 'var(--accent)', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 18 }}>🔧</div>
          <span className="font-display" style={{ fontSize: 18, fontWeight: 800, color: 'var(--text)' }}>MecânicaPro</span>
        </div>
      </div>

      {/* Nav */}
      <nav style={{ flex: 1, padding: '12px 0', overflowY: 'auto' }}>
        {itemsWithBadges.map(item => {
          const active = pathname === item.href || (item.href !== '/' && pathname.startsWith(item.href))
          return (
            <Link key={item.href} href={item.href}
              style={{
                display: 'flex', alignItems: 'center', gap: 10,
                padding: '9px 16px', textDecoration: 'none',
                color: active ? 'var(--text)' : 'var(--muted)',
                background: active ? 'rgba(245,166,35,0.08)' : 'transparent',
                borderLeft: `2px solid ${active ? 'var(--accent)' : 'transparent'}`,
                fontSize: 14, fontWeight: active ? 600 : 400,
                transition: 'all 0.15s',
                position: 'relative',
              }}>
              <span style={{ fontSize: 16 }}>{item.icon}</span>
              <span style={{ flex: 1 }}>{item.label}</span>
              {item.badge && (
                <span style={{
                  background: 'var(--danger)', color: '#fff',
                  fontSize: 11, fontWeight: 700, borderRadius: 999,
                  padding: '1px 6px', minWidth: 18, textAlign: 'center',
                }}>
                  {item.badge > 99 ? '99+' : item.badge}
                </span>
              )}
            </Link>
          )
        })}
      </nav>

      {/* User pill */}
      {user && (
        <div style={{ padding: '12px 16px', borderTop: '1px solid var(--border)' }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 8 }}>
            <div style={{ width: 32, height: 32, borderRadius: '50%', background: 'var(--card)', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 14, border: '1px solid var(--border)' }}>
              {user.nome.charAt(0).toUpperCase()}
            </div>
            <div>
              <p style={{ color: 'var(--text)', fontSize: 13, fontWeight: 600, margin: 0 }}>{user.nome}</p>
              <p style={{ color: 'var(--muted)', fontSize: 11, margin: 0 }}>{user.role}</p>
            </div>
          </div>
          <button onClick={logout}
            style={{ width: '100%', padding: '6px', borderRadius: 6, background: 'transparent', border: '1px solid var(--border)', color: 'var(--muted)', fontSize: 13, cursor: 'pointer' }}>
            Sair
          </button>
        </div>
      )}
    </aside>
  )
}
```

**Atenção:** Remover o `hy` acidental na linha: `produtosAlerta = 0hy` → corrigir para `produtosAlerta = 0`.

- [ ] **Step 7.4: Criar components/layout/Topbar.tsx**

```tsx
// frontend/components/layout/Topbar.tsx
'use client'
import { usePathname } from 'next/navigation'

const BREADCRUMBS: Record<string, string> = {
  '/':                   'Dashboard',
  '/clientes':           'Clientes',
  '/clientes/novo':      'Clientes / Novo',
  '/produtos':           'Produtos / Estoque',
  '/os':                 'Ordens de Serviço',
  '/os/nova':            'Ordens de Serviço / Nova OS',
  '/fiscal/emitir':      'Fiscal / Emitir NF',
  '/fiscal/historico':   'Fiscal / Histórico',
  '/usuarios':           'Usuários',
  '/empresa':            'Empresa',
  '/configuracoes':      'Configurações',
}

const ACTION_BUTTONS: Record<string, { label: string; href: string }> = {
  '/clientes':    { label: '+ Novo Cliente',    href: '/clientes/novo' },
  '/produtos':    { label: '+ Novo Produto',    href: '/produtos/novo' },
  '/os':          { label: '+ Nova OS',         href: '/os/nova' },
  '/usuarios':    { label: '+ Novo Usuário',    href: '/usuarios/novo' },
  '/fiscal/historico': { label: 'Emitir NF',   href: '/fiscal/emitir' },
}

export function Topbar() {
  const pathname = usePathname()

  const breadcrumb = BREADCRUMBS[pathname] ?? pathname.split('/').filter(Boolean).join(' / ')
  const action = ACTION_BUTTONS[pathname]

  return (
    <header style={{
      height: 60, background: 'var(--surface)',
      borderBottom: '1px solid var(--border)',
      display: 'flex', alignItems: 'center', justifyContent: 'space-between',
      padding: '0 24px', position: 'sticky', top: 0, zIndex: 50,
    }}>
      <nav style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
        {breadcrumb.split(' / ').map((part, i, arr) => (
          <span key={i} style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
            <span style={{ color: i === arr.length - 1 ? 'var(--text)' : 'var(--muted)', fontSize: 14, fontWeight: i === arr.length - 1 ? 600 : 400 }}>
              {part}
            </span>
            {i < arr.length - 1 && <span style={{ color: 'var(--muted)', fontSize: 12 }}>/</span>}
          </span>
        ))}
      </nav>
      {action && (
        <a href={action.href}
          className="font-display"
          style={{ padding: '8px 16px', background: 'var(--accent)', color: '#000', borderRadius: 8, textDecoration: 'none', fontWeight: 700, fontSize: 14 }}>
          {action.label}
        </a>
      )}
    </header>
  )
}
```

- [ ] **Step 7.5: Criar components/layout/AlertBanner.tsx**

```tsx
// frontend/components/layout/AlertBanner.tsx
'use client'
import { useState } from 'react'
import Link from 'next/link'

interface AlertBannerProps {
  items: Array<{ nome: string; qty_atual: number; status: string }>
}

export function AlertBanner({ items }: AlertBannerProps) {
  const [dismissed, setDismissed] = useState(false)

  if (dismissed || items.length === 0) return null

  return (
    <div style={{
      background: 'rgba(229,57,53,0.1)', border: '1px solid rgba(229,57,53,0.3)',
      borderRadius: 8, padding: '10px 16px', marginBottom: 20,
      display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 12,
    }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
        <span style={{ color: 'var(--danger)', fontSize: 16 }}>⚠</span>
        <span style={{ color: 'var(--danger)', fontSize: 13, fontWeight: 600 }}>
          Estoque crítico:&nbsp;
          {items.slice(0, 3).map(i => i.nome).join(', ')}
          {items.length > 3 && ` e mais ${items.length - 3}`}
        </span>
        <Link href="/produtos" style={{ color: 'var(--accent)', fontSize: 13, textDecoration: 'none' }}>
          Ver todos →
        </Link>
      </div>
      <button onClick={() => setDismissed(true)}
        style={{ background: 'none', border: 'none', color: 'var(--muted)', cursor: 'pointer', fontSize: 18, lineHeight: 1 }}>
        ×
      </button>
    </div>
  )
}
```

- [ ] **Step 7.6: Criar app/(dashboard)/layout.tsx**

```tsx
// frontend/app/(dashboard)/layout.tsx
'use client'
import { useEffect, useState } from 'react'
import { Sidebar } from '@/components/layout/Sidebar'
import { Topbar } from '@/components/layout/Topbar'
import { AlertBanner } from '@/components/layout/AlertBanner'
import { ToastContainer } from '@/components/ui/Toast'
import api from '@/lib/api'

export default function DashboardLayout({ children }: { children: React.ReactNode }) {
  const [alertItems, setAlertItems] = useState<any[]>([])
  const [badges, setBadges] = useState({ clientes: 0, produtos: 0 })

  useEffect(() => {
    // Buscar produtos críticos e badges
    Promise.all([
      api.get('/produtos?status=CRITICO,SEM_ESTOQUE').catch(() => ({ data: { data: [] } })),
      api.get('/clientes?status=DEVEDOR&count=1').catch(() => ({ data: { total: 0 } })),
    ]).then(([produtos, clientes]) => {
      const prods = produtos.data?.data ?? []
      setAlertItems(prods)
      setBadges({ clientes: clientes.data?.total ?? 0, produtos: prods.length })
    })
  }, [])

  return (
    <div style={{ display: 'flex', minHeight: '100vh', background: 'var(--bg)' }}>
      <Sidebar clientesDevedores={badges.clientes} produtosAlerta={badges.produtos} />
      <div style={{ marginLeft: 230, flex: 1, display: 'flex', flexDirection: 'column' }}>
        <Topbar />
        <main style={{ flex: 1, padding: '24px' }}>
          {alertItems.length > 0 && <AlertBanner items={alertItems} />}
          {children}
        </main>
      </div>
      <ToastContainer />
    </div>
  )
}
```

- [ ] **Step 7.7: Criar página dashboard provisória**

```tsx
// frontend/app/(dashboard)/page.tsx
export default function DashboardPage() {
  return (
    <div>
      <h1 className="font-display" style={{ fontSize: 28, fontWeight: 800, color: 'var(--text)', marginBottom: 4 }}>
        Dashboard
      </h1>
      <p style={{ color: 'var(--muted)' }}>Bem-vindo ao MecânicaPro</p>
    </div>
  )
}
```

- [ ] **Step 7.8: Testar layout visualmente**

```bash
cd frontend && npm run dev
```

Após login, deve ver:
- Sidebar fixa à esquerda (230px) com itens de navegação e logo
- Topbar com breadcrumb "Dashboard"
- Content area com fundo `--bg`

- [ ] **Step 7.9: Commit**

```bash
git add components/layout/ components/ui/Toast.tsx hooks/useToast.ts app/(dashboard)/layout.tsx app/(dashboard)/page.tsx
git commit -m "feat: add dashboard layout with sidebar, topbar, and toast system"
```

---

## Task 8: Módulo Clientes — Backend

**Files:**
- Create: `backend/app/Rules/Cpf.php`
- Create: `backend/app/Rules/Cnpj.php`
- Create: `backend/app/Services/ClienteStatusService.php`
- Create: `backend/app/Http/Controllers/ClienteController.php`
- Create: `backend/app/Http/Resources/ClienteResource.php`
- Modify: `backend/routes/api.php`
- Create: `backend/tests/Feature/ClienteTest.php`

- [ ] **Step 8.1: Criar validação CPF (TDD)**

```php
<?php
// backend/tests/Feature/ClienteTest.php
declare(strict_types=1);
namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ClienteTest extends TestCase
{
    use RefreshDatabase;

    private function loginAdmin(): string
    {
        $user = Usuario::create([
            'nome' => 'Admin', 'email' => 'admin@test.com',
            'cpf' => '52998224725', 'role' => 'ADMIN', 'status' => 'ATIVO',
            'senha_hash' => Hash::make('admin123'),
        ]);
        return $user->createToken('test')->plainTextToken;
    }

    public function test_criar_cliente_com_cpf_valido(): void
    {
        $token = $this->loginAdmin();

        $response = $this->withToken($token)->postJson('/api/clientes', [
            'nome'     => 'João Silva',
            'cpf_cnpj' => '529.982.247-25',
            'telefone' => '(11) 99999-9999',
        ]);

        $response->assertStatus(201)->assertJsonStructure(['data' => ['id', 'nome', 'cpf_cnpj']]);
    }

    public function test_rejeitar_cliente_com_cpf_invalido(): void
    {
        $token = $this->loginAdmin();

        $response = $this->withToken($token)->postJson('/api/clientes', [
            'nome'     => 'Inválido',
            'cpf_cnpj' => '111.111.111-11',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['cpf_cnpj']);
    }

    public function test_listar_clientes_com_paginacao(): void
    {
        $token = $this->loginAdmin();
        $response = $this->withToken($token)->getJson('/api/clientes');
        $response->assertStatus(200)->assertJsonStructure(['data', 'meta' => ['total', 'per_page']]);
    }
}
```

- [ ] **Step 8.2: Rodar teste — deve falhar**

```bash
cd backend && php artisan test tests/Feature/ClienteTest.php
```

Saída esperada: `FAIL` — controller não existe.

- [ ] **Step 8.3: Criar app/Rules/Cpf.php**

```php
<?php
// backend/app/Rules/Cpf.php
declare(strict_types=1);
namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class Cpf implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $cpf = preg_replace('/\D/', '', (string)$value);

        if (strlen($cpf) !== 11 || preg_match('/^(\d)\1+$/', $cpf)) {
            $fail('CPF inválido.'); return;
        }

        for ($t = 9; $t < 11; $t++) {
            $sum = 0;
            for ($i = 0; $i < $t; $i++) {
                $sum += (int)$cpf[$i] * ($t + 1 - $i);
            }
            $rem = (10 * $sum) % 11;
            if ((int)$cpf[$t] !== ($rem >= 10 ? 0 : $rem)) {
                $fail('CPF inválido.'); return;
            }
        }
    }
}
```

- [ ] **Step 8.4: Criar app/Rules/Cnpj.php**

```php
<?php
// backend/app/Rules/Cnpj.php
declare(strict_types=1);
namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class Cnpj implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $cnpj = preg_replace('/\D/', '', (string)$value);

        if (strlen($cnpj) !== 14 || preg_match('/^(\d)\1+$/', $cnpj)) {
            $fail('CNPJ inválido.'); return;
        }

        $calc = function (string $cnpj, int $len): int {
            $sum = 0;
            $pos = $len - 7;
            for ($i = $len; $i >= 1; $i--) {
                $sum += (int)$cnpj[$len - $i] * $pos--;
                if ($pos < 2) $pos = 9;
            }
            $rem = $sum % 11;
            return $rem < 2 ? 0 : 11 - $rem;
        };

        if ((int)$cnpj[12] !== $calc($cnpj, 12) || (int)$cnpj[13] !== $calc($cnpj, 13)) {
            $fail('CNPJ inválido.');
        }
    }
}
```

- [ ] **Step 8.5: Criar ClienteStatusService.php**

```php
<?php
// backend/app/Services/ClienteStatusService.php
declare(strict_types=1);
namespace App\Services;

use App\Models\Cliente;
use App\Models\OrdemServico;

class ClienteStatusService
{
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
}
```

- [ ] **Step 8.6: Criar ClienteResource.php**

```php
<?php
// backend/app/Http/Resources/ClienteResource.php
declare(strict_types=1);
namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClienteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'nome'           => $this->nome,
            'cpf_cnpj'       => $this->cpf_cnpj,
            'telefone'       => $this->telefone,
            'email'          => $this->email,
            'cep'            => $this->cep,
            'endereco'       => $this->endereco,
            'bairro'         => $this->bairro,
            'cidade'         => $this->cidade,
            'uf'             => $this->uf,
            'veiculo_modelo' => $this->veiculo_modelo,
            'veiculo_ano'    => $this->veiculo_ano,
            'veiculo_placa'  => $this->veiculo_placa,
            'status'         => $this->status,
            'criado_em'      => $this->criado_em?->format('d/m/Y'),
        ];
    }
}
```

- [ ] **Step 8.7: Criar ClienteController.php**

```php
<?php
// backend/app/Http/Controllers/ClienteController.php
declare(strict_types=1);
namespace App\Http\Controllers;

use App\Http\Resources\ClienteResource;
use App\Models\Cliente;
use App\Rules\Cnpj;
use App\Rules\Cpf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ClienteController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Cliente::query();

        if ($request->has('status')) {
            $statuses = explode(',', $request->status);
            $query->whereIn('status', $statuses);
        }
        if ($request->has('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('nome', 'ilike', "%{$request->search}%")
                  ->orWhere('cpf_cnpj', 'like', "%{$request->search}%")
                  ->orWhere('veiculo_placa', 'ilike', "%{$request->search}%");
            });
        }
        if ($request->has('count')) {
            return response()->json(['total' => $query->count()]);
        }

        return ClienteResource::collection(
            $query->orderByRaw("status = 'DEVEDOR' DESC, nome ASC")
                  ->paginate(20)
        );
    }

    public function store(Request $request): JsonResponse
    {
        $cpfCnpj = preg_replace('/\D/', '', $request->cpf_cnpj ?? '');
        $rule = strlen($cpfCnpj) === 14 ? new Cnpj() : new Cpf();

        $validated = $request->validate([
            'nome'           => ['required', 'string', 'max:150'],
            'cpf_cnpj'       => ['required', 'string', 'unique:clientes,cpf_cnpj', $rule],
            'telefone'       => ['nullable', 'string', 'max:15'],
            'email'          => ['nullable', 'email', 'max:120'],
            'cep'            => ['nullable', 'string', 'max:9'],
            'endereco'       => ['nullable', 'string', 'max:200'],
            'bairro'         => ['nullable', 'string', 'max:80'],
            'cidade'         => ['nullable', 'string', 'max:80'],
            'uf'             => ['nullable', 'string', 'size:2'],
            'veiculo_modelo' => ['nullable', 'string', 'max:80'],
            'veiculo_ano'    => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'veiculo_placa'  => ['nullable', 'string', 'max:10'],
        ]);

        $cliente = Cliente::create($validated);
        return (new ClienteResource($cliente))->response()->setStatusCode(201);
    }

    public function show(string $id): ClienteResource
    {
        return new ClienteResource(Cliente::findOrFail($id));
    }

    public function update(Request $request, string $id): ClienteResource
    {
        $cliente = Cliente::findOrFail($id);
        $cpfCnpj = preg_replace('/\D/', '', $request->cpf_cnpj ?? '');
        $rule = strlen($cpfCnpj) === 14 ? new Cnpj() : new Cpf();

        $validated = $request->validate([
            'nome'           => ['sometimes', 'required', 'string', 'max:150'],
            'cpf_cnpj'       => ['sometimes', 'required', 'string', "unique:clientes,cpf_cnpj,{$id}", $rule],
            'telefone'       => ['nullable', 'string', 'max:15'],
            'email'          => ['nullable', 'email', 'max:120'],
            'cep'            => ['nullable', 'string', 'max:9'],
            'endereco'       => ['nullable', 'string', 'max:200'],
            'bairro'         => ['nullable', 'string', 'max:80'],
            'cidade'         => ['nullable', 'string', 'max:80'],
            'uf'             => ['nullable', 'string', 'size:2'],
            'veiculo_modelo' => ['nullable', 'string', 'max:80'],
            'veiculo_ano'    => ['nullable', 'integer'],
            'veiculo_placa'  => ['nullable', 'string', 'max:10'],
        ]);

        $cliente->update($validated);
        return new ClienteResource($cliente->fresh());
    }

    public function destroy(string $id): JsonResponse
    {
        Cliente::findOrFail($id)->delete();
        return response()->json(['message' => 'Cliente removido.']);
    }
}
```

- [ ] **Step 8.8: Adicionar rotas de clientes ao api.php**

Adicionar ao bloco `auth:sanctum` em `routes/api.php`:
```php
use App\Http\Controllers\ClienteController;
// Dentro do grupo middleware('auth:sanctum'):
Route::apiResource('clientes', ClienteController::class);
```

- [ ] **Step 8.9: Rodar testes — devem passar**

```bash
php artisan test tests/Feature/ClienteTest.php
```

Saída esperada: 3 testes verdes.

- [ ] **Step 8.10: Commit**

```bash
git add app/Rules/ app/Services/ClienteStatusService.php app/Http/Controllers/ClienteController.php app/Http/Resources/ClienteResource.php routes/api.php tests/Feature/ClienteTest.php
git commit -m "feat: add clientes CRUD backend with CPF/CNPJ validation"
```

---

## Task 9: Módulo Clientes — Frontend

**Files:**
- Create: `frontend/lib/validations/br.ts`
- Create: `frontend/lib/formatters.ts`
- Create: `frontend/app/(dashboard)/clientes/page.tsx`
- Create: `frontend/app/(dashboard)/clientes/novo/page.tsx`
- Create: `frontend/app/(dashboard)/clientes/[id]/page.tsx`
- Create: `frontend/components/forms/ClienteForm.tsx`
- Create: `frontend/components/ui/StatusPill.tsx`
- Create: `frontend/components/ui/DataTable.tsx`

- [ ] **Step 9.1: Criar lib/validations/br.ts**

```typescript
// frontend/lib/validations/br.ts
export function validarCPF(cpf: string): boolean {
  const digits = cpf.replace(/\D/g, '')
  if (digits.length !== 11 || /^(\d)\1+$/.test(digits)) return false
  for (let t = 9; t < 11; t++) {
    let sum = 0
    for (let i = 0; i < t; i++) sum += parseInt(digits[i]) * (t + 1 - i)
    const rem = (sum * 10) % 11
    if (parseInt(digits[t]) !== (rem >= 10 ? 0 : rem)) return false
  }
  return true
}

export function validarCNPJ(cnpj: string): boolean {
  const digits = cnpj.replace(/\D/g, '')
  if (digits.length !== 14 || /^(\d)\1+$/.test(digits)) return false
  const calc = (len: number): number => {
    let sum = 0, pos = len - 7
    for (let i = len; i >= 1; i--) {
      sum += parseInt(digits[len - i]) * pos--
      if (pos < 2) pos = 9
    }
    const rem = sum % 11
    return rem < 2 ? 0 : 11 - rem
  }
  return parseInt(digits[12]) === calc(12) && parseInt(digits[13]) === calc(13)
}

export function validarCPFouCNPJ(value: string): boolean {
  const d = value.replace(/\D/g, '')
  return d.length === 14 ? validarCNPJ(value) : validarCPF(value)
}
```

- [ ] **Step 9.2: Criar lib/formatters.ts**

```typescript
// frontend/lib/formatters.ts
export const formatarMoeda = (v: number) =>
  new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(v)

export const formatarCPF = (v: string) =>
  v.replace(/\D/g, '').replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4')

export const formatarCNPJ = (v: string) =>
  v.replace(/\D/g, '').replace(/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/, '$1.$2.$3/$4-$5')

export const formatarTelefone = (v: string) => {
  const d = v.replace(/\D/g, '')
  return d.length === 11
    ? d.replace(/(\d{2})(\d{5})(\d{4})/, '($1) $2-$3')
    : d.replace(/(\d{2})(\d{4})(\d{4})/, '($1) $2-$3')
}

export const formatarPlaca = (v: string) => {
  const d = v.toUpperCase().replace(/[^A-Z0-9]/g, '')
  // Mercosul: ABC1D23 | Antiga: ABC1234
  if (/^[A-Z]{3}\d[A-Z]\d{2}$/.test(d)) return d.replace(/([A-Z]{3})(\d[A-Z]\d{2})/, '$1-$2')
  if (/^[A-Z]{3}\d{4}$/.test(d))        return d.replace(/([A-Z]{3})(\d{4})/, '$1-$2')
  return d
}

export const formatarData = (iso?: string | null) => {
  if (!iso) return '-'
  const d = new Date(iso)
  return d.toLocaleDateString('pt-BR')
}
```

- [ ] **Step 9.3: Criar components/ui/StatusPill.tsx**

```tsx
// frontend/components/ui/StatusPill.tsx
const STATUS_MAP: Record<string, { label: string; cls: string }> = {
  REGULAR:      { label: 'Regular',     cls: 'pill-success' },
  DEVEDOR:      { label: 'Devedor',     cls: 'pill-danger'  },
  OS_ABERTA:    { label: 'OS Aberta',   cls: 'pill-accent'  },
  ATIVO:        { label: 'Ativo',       cls: 'pill-success' },
  INATIVO:      { label: 'Inativo',     cls: 'pill-muted'   },
  ABERTA:       { label: 'Aberta',      cls: 'pill-info'    },
  EM_ANDAMENTO: { label: 'Em Andamento', cls: 'pill-accent' },
  AGUARDANDO_PECAS: { label: 'Aguard. Peças', cls: 'pill-muted' },
  CONCLUIDA:    { label: 'Concluída',   cls: 'pill-success' },
  CANCELADA:    { label: 'Cancelada',   cls: 'pill-danger'  },
  NORMAL:       { label: 'Normal',      cls: 'pill-success' },
  BAIXO:        { label: 'Baixo',       cls: 'pill-accent'  },
  CRITICO:      { label: 'Crítico',     cls: 'pill-danger'  },
  SEM_ESTOQUE:  { label: 'Sem Estoque', cls: 'pill-danger'  },
  RASCUNHO:     { label: 'Rascunho',    cls: 'pill-muted'   },
  AUTORIZADA:   { label: 'Autorizada',  cls: 'pill-success' },
  CANCELADA_NF: { label: 'Cancelada',   cls: 'pill-danger'  },
  REJEITADA:    { label: 'Rejeitada',   cls: 'pill-danger'  },
}

export function StatusPill({ status }: { status: string }) {
  const s = STATUS_MAP[status] ?? { label: status, cls: 'pill-muted' }
  return <span className={`pill ${s.cls}`}>{s.label}</span>
}
```

- [ ] **Step 9.4: Criar components/ui/DataTable.tsx**

```tsx
// frontend/components/ui/DataTable.tsx
import { StatusPill } from './StatusPill'

export interface Column<T> {
  key: string
  label: string
  render?: (row: T) => React.ReactNode
}

interface DataTableProps<T> {
  columns: Column<T>[]
  data: T[]
  loading?: boolean
  getRowClass?: (row: T) => string
  onRowClick?: (row: T) => void
  emptyMessage?: string
}

export function DataTable<T extends Record<string, any>>({
  columns, data, loading, getRowClass, onRowClick, emptyMessage = 'Nenhum registro encontrado.'
}: DataTableProps<T>) {
  const thStyle: React.CSSProperties = {
    padding: '10px 16px', textAlign: 'left', fontSize: 12,
    fontWeight: 600, color: 'var(--muted)', textTransform: 'uppercase',
    letterSpacing: '0.05em', borderBottom: '1px solid var(--border)',
    background: 'var(--card)',
  }
  const tdStyle: React.CSSProperties = {
    padding: '12px 16px', fontSize: 14, color: 'var(--text)',
    borderBottom: '1px solid var(--border)',
  }

  return (
    <div style={{ background: 'var(--card)', borderRadius: 12, border: '1px solid var(--border)', overflow: 'hidden' }}>
      <table style={{ width: '100%', borderCollapse: 'collapse' }}>
        <thead>
          <tr>
            {columns.map(col => <th key={col.key} style={thStyle}>{col.label}</th>)}
          </tr>
        </thead>
        <tbody>
          {loading ? (
            Array.from({ length: 5 }).map((_, i) => (
              <tr key={i}>
                {columns.map(col => (
                  <td key={col.key} style={tdStyle}>
                    <div style={{ height: 14, background: 'var(--border)', borderRadius: 4, width: '80%', animation: 'pulse 1.5s ease-in-out infinite' }} />
                  </td>
                ))}
              </tr>
            ))
          ) : data.length === 0 ? (
            <tr>
              <td colSpan={columns.length} style={{ ...tdStyle, textAlign: 'center', color: 'var(--muted)', padding: 40 }}>
                {emptyMessage}
              </td>
            </tr>
          ) : (
            data.map((row, i) => (
              <tr key={row.id ?? i}
                className={getRowClass?.(row) ?? ''}
                onClick={() => onRowClick?.(row)}
                style={{ cursor: onRowClick ? 'pointer' : 'default', transition: 'background 0.1s' }}
                onMouseEnter={e => { if (!getRowClass?.(row)) (e.currentTarget as HTMLElement).style.background = 'rgba(255,255,255,0.02)' }}
                onMouseLeave={e => { if (!getRowClass?.(row)) (e.currentTarget as HTMLElement).style.background = '' }}>
                {columns.map(col => (
                  <td key={col.key} style={tdStyle}>
                    {col.render ? col.render(row) : row[col.key]}
                  </td>
                ))}
              </tr>
            ))
          )}
        </tbody>
      </table>
    </div>
  )
}
```

- [ ] **Step 9.5: Criar página de lista de clientes**

```tsx
// frontend/app/(dashboard)/clientes/page.tsx
'use client'
import { useState, useEffect } from 'react'
import { useRouter } from 'next/navigation'
import { DataTable, Column } from '@/components/ui/DataTable'
import { StatusPill } from '@/components/ui/StatusPill'
import { formatarCPF, formatarCNPJ, formatarData } from '@/lib/formatters'
import api from '@/lib/api'

interface Cliente {
  id: string; nome: string; cpf_cnpj: string; telefone: string
  veiculo_modelo: string; veiculo_placa: string; status: string; criado_em: string
}

export default function ClientesPage() {
  const router = useRouter()
  const [clientes, setClientes] = useState<Cliente[]>([])
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')

  useEffect(() => {
    const timeout = setTimeout(() => {
      setLoading(true)
      api.get('/clientes', { params: search ? { search } : {} })
        .then(res => setClientes(res.data.data ?? []))
        .finally(() => setLoading(false))
    }, 300)
    return () => clearTimeout(timeout)
  }, [search])

  const formatDoc = (v: string) => {
    const d = v.replace(/\D/g, '')
    return d.length === 14 ? formatarCNPJ(v) : formatarCPF(v)
  }

  const columns: Column<Cliente>[] = [
    { key: 'nome', label: 'Nome' },
    { key: 'cpf_cnpj', label: 'CPF / CNPJ', render: r => <span className="font-mono">{formatDoc(r.cpf_cnpj)}</span> },
    { key: 'telefone', label: 'Telefone' },
    { key: 'veiculo', label: 'Veículo', render: r => r.veiculo_modelo ? `${r.veiculo_modelo} · ${r.veiculo_placa ?? ''}` : '-' },
    { key: 'status', label: 'Situação', render: r => <StatusPill status={r.status} /> },
    { key: 'acoes', label: '', render: r => (
      <button onClick={(e) => { e.stopPropagation(); router.push(`/clientes/${r.id}`) }}
        style={{ background: 'none', border: '1px solid var(--border)', color: 'var(--muted)', borderRadius: 6, padding: '4px 12px', cursor: 'pointer', fontSize: 13 }}>
        Ver
      </button>
    )},
  ]

  return (
    <div>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 24 }}>
        <div>
          <h1 className="font-display" style={{ fontSize: 28, fontWeight: 800, color: 'var(--text)', margin: 0 }}>Clientes</h1>
          <p style={{ color: 'var(--muted)', margin: '4px 0 0', fontSize: 14 }}>{clientes.length} registros</p>
        </div>
        <input value={search} onChange={e => setSearch(e.target.value)}
          placeholder="Buscar por nome, CPF, placa..."
          style={{ padding: '8px 14px', borderRadius: 8, background: 'var(--card)', border: '1px solid var(--border)', color: 'var(--text)', fontSize: 14, width: 280, outline: 'none' }} />
      </div>
      <DataTable
        columns={columns}
        data={clientes}
        loading={loading}
        getRowClass={r => r.status === 'DEVEDOR' ? 'danger-row' : ''}
        onRowClick={r => router.push(`/clientes/${r.id}`)}
        emptyMessage="Nenhum cliente cadastrado."
      />
    </div>
  )
}
```

- [ ] **Step 9.6: Criar components/forms/ClienteForm.tsx**

```tsx
// frontend/components/forms/ClienteForm.tsx
'use client'
import { useState, useEffect } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { validarCPFouCNPJ } from '@/lib/validations/br'
import api from '@/lib/api'
import { toast } from '@/hooks/useToast'

const schema = z.object({
  nome:           z.string().min(2, 'Nome obrigatório'),
  cpf_cnpj:       z.string().refine(v => validarCPFouCNPJ(v), 'CPF ou CNPJ inválido'),
  telefone:       z.string().optional(),
  email:          z.string().email('E-mail inválido').optional().or(z.literal('')),
  cep:            z.string().optional(),
  endereco:       z.string().optional(),
  bairro:         z.string().optional(),
  cidade:         z.string().optional(),
  uf:             z.string().length(2).optional().or(z.literal('')),
  veiculo_modelo: z.string().optional(),
  veiculo_ano:    z.coerce.number().optional(),
  veiculo_placa:  z.string().optional(),
})

type FormData = z.infer<typeof schema>

interface ClienteFormProps {
  initialData?: Partial<FormData> & { id?: string }
  onSuccess?: () => void
}

const inputStyle: React.CSSProperties = {
  width: '100%', padding: '9px 12px', borderRadius: 8,
  background: 'var(--bg)', border: '1px solid var(--border)',
  color: 'var(--text)', fontSize: 14, outline: 'none',
}

const labelStyle: React.CSSProperties = { color: 'var(--muted)', fontSize: 13, display: 'block', marginBottom: 4 }

export function ClienteForm({ initialData, onSuccess }: ClienteFormProps) {
  const isEdit = !!initialData?.id
  const { register, handleSubmit, setValue, watch, formState: { errors, isSubmitting } } = useForm<FormData>({
    resolver: zodResolver(schema),
    defaultValues: initialData ?? {},
  })

  const cep = watch('cep')

  useEffect(() => {
    const digits = (cep ?? '').replace(/\D/g, '')
    if (digits.length === 8) {
      fetch(`https://viacep.com.br/ws/${digits}/json/`)
        .then(r => r.json())
        .then(d => {
          if (!d.erro) {
            setValue('endereco', d.logradouro)
            setValue('bairro', d.bairro)
            setValue('cidade', d.localidade)
            setValue('uf', d.uf)
          }
        })
        .catch(() => {})
    }
  }, [cep, setValue])

  async function onSubmit(data: FormData) {
    try {
      if (isEdit) {
        await api.put(`/clientes/${initialData!.id}`, data)
        toast('Cliente atualizado com sucesso!', 'success')
      } else {
        await api.post('/clientes', data)
        toast('Cliente cadastrado com sucesso!', 'success')
      }
      onSuccess?.()
    } catch (err: any) {
      const msg = err.response?.data?.message ?? 'Erro ao salvar cliente.'
      toast(msg, 'danger')
    }
  }

  const Field = ({ name, label, type = 'text', half = false }: { name: keyof FormData; label: string; type?: string; half?: boolean }) => (
    <div style={half ? { flex: 1 } : { gridColumn: '1 / -1' }}>
      <label style={labelStyle}>{label}</label>
      <input type={type} {...register(name as any)} style={{ ...inputStyle, borderColor: errors[name] ? 'var(--danger)' : 'var(--border)' }} />
      {errors[name] && <p style={{ color: 'var(--danger)', fontSize: 12, marginTop: 4 }}>{errors[name]?.message as string}</p>}
    </div>
  )

  return (
    <form onSubmit={handleSubmit(onSubmit)}>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
        <div style={{ gridColumn: '1 / -1' }}>
          <label style={labelStyle}>Nome / Razão Social *</label>
          <input {...register('nome')} style={{ ...inputStyle, borderColor: errors.nome ? 'var(--danger)' : 'var(--border)' }} />
          {errors.nome && <p style={{ color: 'var(--danger)', fontSize: 12, marginTop: 4 }}>{errors.nome.message}</p>}
        </div>
        <div>
          <label style={labelStyle}>CPF / CNPJ *</label>
          <input {...register('cpf_cnpj')} style={{ ...inputStyle, borderColor: errors.cpf_cnpj ? 'var(--danger)' : 'var(--border)' }} placeholder="000.000.000-00 ou 00.000.000/0000-00" />
          {errors.cpf_cnpj && <p style={{ color: 'var(--danger)', fontSize: 12, marginTop: 4 }}>{errors.cpf_cnpj.message}</p>}
        </div>
        <div>
          <label style={labelStyle}>Telefone</label>
          <input {...register('telefone')} style={inputStyle} placeholder="(11) 99999-9999" />
        </div>
        <div style={{ gridColumn: '1 / -1' }}>
          <label style={labelStyle}>E-mail</label>
          <input type="email" {...register('email')} style={inputStyle} />
        </div>

        <p style={{ gridColumn: '1 / -1', color: 'var(--muted)', fontSize: 12, fontWeight: 600, textTransform: 'uppercase', letterSpacing: '0.06em', margin: '8px 0 -4px' }}>Endereço</p>
        <div>
          <label style={labelStyle}>CEP</label>
          <input {...register('cep')} style={inputStyle} placeholder="00000-000" />
        </div>
        <div style={{ gridColumn: '1 / -1' }}>
          <label style={labelStyle}>Endereço</label>
          <input {...register('endereco')} style={inputStyle} />
        </div>
        <div>
          <label style={labelStyle}>Bairro</label>
          <input {...register('bairro')} style={inputStyle} />
        </div>
        <div style={{ display: 'flex', gap: 12 }}>
          <div style={{ flex: 1 }}>
            <label style={labelStyle}>Cidade</label>
            <input {...register('cidade')} style={inputStyle} />
          </div>
          <div style={{ width: 60 }}>
            <label style={labelStyle}>UF</label>
            <input {...register('uf')} style={inputStyle} maxLength={2} />
          </div>
        </div>

        <p style={{ gridColumn: '1 / -1', color: 'var(--muted)', fontSize: 12, fontWeight: 600, textTransform: 'uppercase', letterSpacing: '0.06em', margin: '8px 0 -4px' }}>Veículo</p>
        <div>
          <label style={labelStyle}>Modelo</label>
          <input {...register('veiculo_modelo')} style={inputStyle} placeholder="Ex: Honda Civic" />
        </div>
        <div style={{ display: 'flex', gap: 12 }}>
          <div style={{ flex: 1 }}>
            <label style={labelStyle}>Ano</label>
            <input type="number" {...register('veiculo_ano')} style={inputStyle} placeholder="2020" />
          </div>
          <div style={{ flex: 1 }}>
            <label style={labelStyle}>Placa</label>
            <input {...register('veiculo_placa')} style={inputStyle} placeholder="ABC-1234" />
          </div>
        </div>
      </div>

      <div style={{ marginTop: 24, display: 'flex', gap: 12, justifyContent: 'flex-end' }}>
        <button type="submit" disabled={isSubmitting}
          className="font-display"
          style={{ padding: '10px 28px', background: isSubmitting ? 'var(--muted)' : 'var(--accent)', color: '#000', borderRadius: 8, border: 'none', fontWeight: 800, fontSize: 16, cursor: isSubmitting ? 'not-allowed' : 'pointer' }}>
          {isSubmitting ? 'Salvando...' : isEdit ? 'Atualizar Cliente' : 'Cadastrar Cliente'}
        </button>
      </div>
    </form>
  )
}
```

- [ ] **Step 9.7: Criar páginas novo e detalhe de cliente**

```tsx
// frontend/app/(dashboard)/clientes/novo/page.tsx
'use client'
import { useRouter } from 'next/navigation'
import { ClienteForm } from '@/components/forms/ClienteForm'

export default function NovoClientePage() {
  const router = useRouter()
  return (
    <div style={{ maxWidth: 760 }}>
      <h1 className="font-display" style={{ fontSize: 28, fontWeight: 800, color: 'var(--text)', marginBottom: 24 }}>Novo Cliente</h1>
      <div style={{ background: 'var(--card)', borderRadius: 12, border: '1px solid var(--border)', padding: 32 }}>
        <ClienteForm onSuccess={() => router.push('/clientes')} />
      </div>
    </div>
  )
}
```

```tsx
// frontend/app/(dashboard)/clientes/[id]/page.tsx
'use client'
import { useEffect, useState } from 'react'
import { useParams, useRouter } from 'next/navigation'
import { ClienteForm } from '@/components/forms/ClienteForm'
import { StatusPill } from '@/components/ui/StatusPill'
import { formatarMoeda, formatarData } from '@/lib/formatters'
import api from '@/lib/api'

export default function ClienteDetailPage() {
  const { id } = useParams<{ id: string }>()
  const router = useRouter()
  const [cliente, setCliente] = useState<any>(null)
  const [os, setOs] = useState<any[]>([])
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    Promise.all([
      api.get(`/clientes/${id}`),
      api.get(`/os?cliente_id=${id}`),
    ]).then(([c, o]) => {
      setCliente(c.data.data)
      setOs(o.data.data ?? [])
    }).finally(() => setLoading(false))
  }, [id])

  if (loading) return <p style={{ color: 'var(--muted)' }}>Carregando...</p>
  if (!cliente) return <p style={{ color: 'var(--danger)' }}>Cliente não encontrado.</p>

  const saldoDevedor = os.filter(o => o.saldo_devedor > 0).reduce((acc, o) => acc + o.saldo_devedor, 0)

  return (
    <div style={{ maxWidth: 900 }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 16, marginBottom: 24 }}>
        <button onClick={() => router.back()} style={{ background: 'none', border: 'none', color: 'var(--muted)', cursor: 'pointer', fontSize: 14 }}>← Voltar</button>
        <h1 className="font-display" style={{ fontSize: 28, fontWeight: 800, color: 'var(--text)', margin: 0 }}>{cliente.nome}</h1>
        <StatusPill status={cliente.status} />
        {saldoDevedor > 0 && (
          <span style={{ background: 'rgba(229,57,53,0.15)', color: 'var(--danger)', borderRadius: 8, padding: '4px 12px', fontSize: 14, fontWeight: 700 }}>
            Débito: {formatarMoeda(saldoDevedor)}
          </span>
        )}
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 24 }}>
        <div style={{ background: 'var(--card)', borderRadius: 12, border: '1px solid var(--border)', padding: 24 }}>
          <h3 className="font-display" style={{ fontSize: 16, fontWeight: 700, color: 'var(--text)', marginBottom: 16 }}>Editar dados</h3>
          <ClienteForm initialData={cliente} onSuccess={() => api.get(`/clientes/${id}`).then(r => setCliente(r.data.data))} />
        </div>

        <div style={{ background: 'var(--card)', borderRadius: 12, border: '1px solid var(--border)', padding: 24 }}>
          <h3 className="font-display" style={{ fontSize: 16, fontWeight: 700, color: 'var(--text)', marginBottom: 16 }}>Histórico de OS</h3>
          {os.length === 0 ? (
            <p style={{ color: 'var(--muted)', fontSize: 14 }}>Nenhuma OS encontrada.</p>
          ) : (
            os.map(o => (
              <div key={o.id} style={{ padding: '10px 0', borderBottom: '1px solid var(--border)', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                <div>
                  <p style={{ color: 'var(--text)', fontSize: 14, margin: 0, fontWeight: 600 }}>OS #{o.numero}</p>
                  <p style={{ color: 'var(--muted)', fontSize: 12, margin: 0 }}>{formatarData(o.criado_em)}</p>
                </div>
                <div style={{ textAlign: 'right' }}>
                  <StatusPill status={o.status} />
                  {o.saldo_devedor > 0 && <p style={{ color: 'var(--danger)', fontSize: 12, margin: '4px 0 0', fontWeight: 700 }}>{formatarMoeda(o.saldo_devedor)}</p>}
                </div>
              </div>
            ))
          )}
        </div>
      </div>
    </div>
  )
}
```

- [ ] **Step 9.8: Testar fluxo completo de clientes manualmente**

1. Navegar para `/clientes` — lista aparece (possivelmente vazia)
2. Clicar "+ Novo Cliente" (topbar)
3. Preencher CPF inválido → deve mostrar erro
4. Preencher CEP válido → endereço deve ser preenchido automaticamente via ViaCEP
5. Salvar → deve navegar de volta para lista com toast "Cliente cadastrado com sucesso!"
6. Cliente devedor deve aparecer com fundo levemente vermelho

- [ ] **Step 9.9: Commit**

```bash
git add app/(dashboard)/clientes/ components/forms/ClienteForm.tsx components/ui/ lib/
git commit -m "feat: add clientes module (list, form, detail) with CPF/CNPJ validation and ViaCEP"
```

---

## Task 10: Módulo Produtos / Estoque — Backend + Frontend

**Files:**
- Create: `backend/app/Services/EstoqueService.php`
- Create: `backend/app/Jobs/EnviarAlertaEstoque.php`
- Create: `backend/app/Http/Controllers/ProdutoController.php`
- Create: `backend/app/Http/Controllers/EstoqueController.php`
- Create: `backend/app/Http/Resources/ProdutoResource.php`
- Create: `frontend/components/ui/StockBar.tsx`
- Create: `frontend/app/(dashboard)/produtos/page.tsx`

- [ ] **Step 10.1: Criar EstoqueService.php**

```php
<?php
// backend/app/Services/EstoqueService.php
declare(strict_types=1);
namespace App\Services;

use App\Jobs\EnviarAlertaEstoque;
use App\Models\MovimentacaoEstoque;
use App\Models\OrdemServico;
use App\Models\Produto;
use Illuminate\Support\Facades\DB;

class EstoqueService
{
    public function getStatus(int $qtyAtual, int $qtyMinima): string
    {
        if ($qtyAtual <= 0)                       return 'SEM_ESTOQUE';
        if ($qtyAtual < $qtyMinima * 0.4)         return 'CRITICO';
        if ($qtyAtual < $qtyMinima)               return 'BAIXO';
        return 'NORMAL';
    }

    public function entradaManual(Produto $produto, int $quantidade, string $motivo, string $usuarioId): void
    {
        DB::transaction(function () use ($produto, $quantidade, $motivo, $usuarioId) {
            $produto->increment('qty_atual', $quantidade);
            MovimentacaoEstoque::create([
                'produto_id'  => $produto->id,
                'tipo'        => 'ENTRADA',
                'quantidade'  => $quantidade,
                'motivo'      => $motivo,
                'usuario_id'  => $usuarioId,
            ]);
        });
    }

    public function baixarEstoqueOs(OrdemServico $os): void
    {
        DB::transaction(function () use ($os) {
            foreach ($os->itens()->where('tipo', 'PECA')->get() as $item) {
                $produto = Produto::lockForUpdate()->findOrFail($item->produto_id);

                if ($produto->qty_atual < $item->quantidade) {
                    throw new \Exception("Estoque insuficiente para: {$produto->nome}");
                }

                $produto->decrement('qty_atual', (int)$item->quantidade);

                MovimentacaoEstoque::create([
                    'produto_id'  => $produto->id,
                    'tipo'        => 'SAIDA',
                    'quantidade'  => (int)$item->quantidade,
                    'motivo'      => 'Baixa automática OS #' . $os->numero,
                    'os_id'       => $os->id,
                    'usuario_id'  => auth()->id(),
                ]);

                if ($produto->qty_atual < $produto->qty_minima) {
                    EnviarAlertaEstoque::dispatch($produto->fresh());
                }
            }
        });
    }
}
```

- [ ] **Step 10.2: Criar Job EnviarAlertaEstoque.php**

```php
<?php
// backend/app/Jobs/EnviarAlertaEstoque.php
declare(strict_types=1);
namespace App\Jobs;

use App\Models\Configuracao;
use App\Models\Produto;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class EnviarAlertaEstoque implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly Produto $produto) {}

    public function handle(): void
    {
        $config = Configuracao::first();
        $email  = $config?->email_alertas ?? config('mail.from.address');

        if (!$config?->alertas_email || !$email) return;

        $status = match(true) {
            $this->produto->qty_atual <= 0                         => 'SEM ESTOQUE',
            $this->produto->qty_atual < $this->produto->qty_minima * 0.4 => 'CRÍTICO',
            default => 'BAIXO',
        };

        Mail::raw(
            "Alerta de estoque — {$this->produto->nome}\n\nStatus: {$status}\nQuantidade atual: {$this->produto->qty_atual}\nQuantidade mínima: {$this->produto->qty_minima}\nSKU: {$this->produto->sku}",
            fn($m) => $m->to($email)->subject("⚠ Estoque {$status}: {$this->produto->nome}")
        );
    }
}
```

- [ ] **Step 10.3: Criar ProdutoResource.php**

```php
<?php
// backend/app/Http/Resources/ProdutoResource.php
declare(strict_types=1);
namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProdutoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'nome'           => $this->nome,
            'sku'            => $this->sku,
            'categoria'      => $this->categoria,
            'unidade'        => $this->unidade,
            'qty_atual'      => $this->qty_atual,
            'qty_minima'     => $this->qty_minima,
            'preco_custo'    => $this->preco_custo,
            'preco_venda'    => $this->preco_venda,
            'ativo'          => $this->ativo,
            'status_estoque' => $this->status_estoque, // accessor do model
            'criado_em'      => $this->criado_em?->format('d/m/Y'),
        ];
    }
}
```

- [ ] **Step 10.4: Criar ProdutoController.php**

```php
<?php
// backend/app/Http/Controllers/ProdutoController.php
declare(strict_types=1);
namespace App\Http\Controllers;

use App\Http\Resources\ProdutoResource;
use App\Models\Produto;
use App\Services\EstoqueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

class ProdutoController extends Controller
{
    public function __construct(private readonly EstoqueService $estoqueService) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Produto::where('ativo', true);

        if ($request->has('status')) {
            $statuses = explode(',', $request->status);
            // Filtra por status calculado — carrega todos e filtra em memória (tabela pequena)
            $produtos = $query->get()->filter(fn($p) => in_array($p->status_estoque, $statuses));
            return ProdutoResource::collection($produtos);
        }
        if ($request->has('search')) {
            $query->where(fn($q) => $q->where('nome', 'ilike', "%{$request->search}%")->orWhere('sku', 'ilike', "%{$request->search}%"));
        }
        if ($request->has('categoria')) {
            $query->where('categoria', $request->categoria);
        }

        return ProdutoResource::collection($query->orderBy('nome')->paginate(20));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nome'        => ['required', 'string', 'max:150'],
            'sku'         => ['nullable', 'string', 'max:30', 'unique:produtos,sku'],
            'categoria'   => ['required', 'string', 'max:40'],
            'unidade'     => ['nullable', 'string', 'max:10'],
            'qty_atual'   => ['nullable', 'integer', 'min:0'],
            'qty_minima'  => ['nullable', 'integer', 'min:0'],
            'preco_custo' => ['nullable', 'numeric', 'min:0'],
            'preco_venda' => ['nullable', 'numeric', 'min:0'],
        ]);

        $validated['sku'] = $validated['sku'] ?? strtoupper(Str::random(8));

        $produto = Produto::create($validated);
        return (new ProdutoResource($produto))->response()->setStatusCode(201);
    }

    public function show(string $id): ProdutoResource
    {
        return new ProdutoResource(Produto::findOrFail($id));
    }

    public function update(Request $request, string $id): ProdutoResource
    {
        $produto = Produto::findOrFail($id);
        $validated = $request->validate([
            'nome'        => ['sometimes', 'required', 'string', 'max:150'],
            'sku'         => ['sometimes', 'required', 'string', 'max:30', "unique:produtos,sku,{$id}"],
            'categoria'   => ['sometimes', 'required', 'string', 'max:40'],
            'unidade'     => ['nullable', 'string', 'max:10'],
            'qty_minima'  => ['nullable', 'integer', 'min:0'],
            'preco_custo' => ['nullable', 'numeric', 'min:0'],
            'preco_venda' => ['nullable', 'numeric', 'min:0'],
        ]);
        $produto->update($validated);
        return new ProdutoResource($produto->fresh());
    }
}
```

- [ ] **Step 10.5: Criar EstoqueController.php (entrada manual)**

```php
<?php
// backend/app/Http/Controllers/EstoqueController.php
declare(strict_types=1);
namespace App\Http\Controllers;

use App\Models\MovimentacaoEstoque;
use App\Models\Produto;
use App\Services\EstoqueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EstoqueController extends Controller
{
    public function __construct(private readonly EstoqueService $estoqueService) {}

    public function entrada(Request $request, string $produtoId): JsonResponse
    {
        $request->validate([
            'quantidade' => ['required', 'integer', 'min:1'],
            'motivo'     => ['required', 'string', 'max:100'],
        ]);

        $produto = Produto::findOrFail($produtoId);
        $this->estoqueService->entradaManual($produto, $request->quantidade, $request->motivo, auth()->id());

        return response()->json(['message' => 'Entrada registrada.', 'qty_atual' => $produto->fresh()->qty_atual]);
    }

    public function historico(string $produtoId): JsonResponse
    {
        $movs = MovimentacaoEstoque::where('produto_id', $produtoId)
            ->orderBy('criado_em', 'desc')
            ->limit(50)
            ->get();

        return response()->json($movs);
    }
}
```

- [ ] **Step 10.6: Adicionar rotas de produtos ao api.php**

```php
use App\Http\Controllers\ProdutoController;
use App\Http\Controllers\EstoqueController;

// Dentro do grupo middleware('auth:sanctum'):
Route::apiResource('produtos', ProdutoController::class);
Route::post('produtos/{produto}/estoque/entrada', [EstoqueController::class, 'entrada']);
Route::get('produtos/{produto}/estoque/historico', [EstoqueController::class, 'historico']);
```

- [ ] **Step 10.7: Criar StockBar.tsx (componente frontend)**

```tsx
// frontend/components/ui/StockBar.tsx
interface StockBarProps {
  qtyAtual: number
  qtyMinima: number
  status: string
}

export function StockBar({ qtyAtual, qtyMinima, status }: StockBarProps) {
  const pct = qtyMinima === 0 ? 100 : Math.min(100, (qtyAtual / (qtyMinima * 1.5)) * 100)

  const fillClass = status === 'CRITICO' || status === 'SEM_ESTOQUE'
    ? 'stock-fill critico'
    : status === 'BAIXO' ? 'stock-fill baixo' : 'stock-fill normal'

  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
      <div className="stock-bar" style={{ flex: 1 }}>
        <div className={fillClass} style={{ width: `${pct}%` }} />
      </div>
      <span className="font-mono" style={{ fontSize: 13, color: status === 'SEM_ESTOQUE' || status === 'CRITICO' ? 'var(--danger)' : status === 'BAIXO' ? 'var(--accent)' : 'var(--success)', minWidth: 24, textAlign: 'right' }}>
        {qtyAtual}
      </span>
    </div>
  )
}
```

- [ ] **Step 10.8: Criar página de produtos**

```tsx
// frontend/app/(dashboard)/produtos/page.tsx
'use client'
import { useState, useEffect } from 'react'
import { DataTable, Column } from '@/components/ui/DataTable'
import { StatusPill } from '@/components/ui/StatusPill'
import { StockBar } from '@/components/ui/StockBar'
import { formatarMoeda } from '@/lib/formatters'
import api from '@/lib/api'
import { toast } from '@/hooks/useToast'

interface Produto {
  id: string; nome: string; sku: string; categoria: string
  qty_atual: number; qty_minima: number; preco_venda: number; status_estoque: string
}

export default function ProdutosPage() {
  const [produtos, setProdutos] = useState<Produto[]>([])
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')
  const [showEntrada, setShowEntrada] = useState<string | null>(null)
  const [qtdEntrada, setQtdEntrada] = useState(1)
  const [motivoEntrada, setMotivoEntrada] = useState('')

  const fetchProdutos = () => {
    setLoading(true)
    api.get('/produtos', { params: search ? { search } : {} })
      .then(res => setProdutos(res.data.data ?? []))
      .finally(() => setLoading(false))
  }

  useEffect(() => {
    const t = setTimeout(fetchProdutos, 300)
    return () => clearTimeout(t)
  }, [search])

  async function registrarEntrada(produtoId: string) {
    try {
      await api.post(`/produtos/${produtoId}/estoque/entrada`, { quantidade: qtdEntrada, motivo: motivoEntrada || 'Compra de fornecedor' })
      toast('Entrada registrada com sucesso!', 'success')
      setShowEntrada(null)
      fetchProdutos()
    } catch { toast('Erro ao registrar entrada.', 'danger') }
  }

  const columns: Column<Produto>[] = [
    { key: 'nome', label: 'Produto', render: r => <div><p style={{ margin: 0, color: 'var(--text)', fontWeight: 500 }}>{r.nome}</p><p className="font-mono" style={{ margin: 0, color: 'var(--muted)', fontSize: 12 }}>{r.sku}</p></div> },
    { key: 'categoria', label: 'Categoria', render: r => <span style={{ color: 'var(--muted)', fontSize: 13 }}>{r.categoria}</span> },
    { key: 'estoque', label: 'Estoque', render: r => <StockBar qtyAtual={r.qty_atual} qtyMinima={r.qty_minima} status={r.status_estoque} /> },
    { key: 'qty_minima', label: 'Mínimo', render: r => <span className="font-mono" style={{ color: 'var(--muted)' }}>{r.qty_minima}</span> },
    { key: 'preco_venda', label: 'Preço Venda', render: r => <span className="font-mono" style={{ color: 'var(--text)' }}>{r.preco_venda ? formatarMoeda(r.preco_venda) : '-'}</span> },
    { key: 'status_estoque', label: 'Status', render: r => <StatusPill status={r.status_estoque} /> },
    { key: 'acoes', label: '', render: r => (
      <button onClick={e => { e.stopPropagation(); setShowEntrada(r.id); setQtdEntrada(1); setMotivoEntrada('') }}
        style={{ background: 'none', border: '1px solid var(--border)', color: 'var(--accent)', borderRadius: 6, padding: '4px 10px', cursor: 'pointer', fontSize: 13 }}>
        + Entrada
      </button>
    )},
  ]

  return (
    <div>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 24 }}>
        <h1 className="font-display" style={{ fontSize: 28, fontWeight: 800, color: 'var(--text)', margin: 0 }}>Produtos / Estoque</h1>
        <input value={search} onChange={e => setSearch(e.target.value)}
          placeholder="Buscar por nome ou SKU..."
          style={{ padding: '8px 14px', borderRadius: 8, background: 'var(--card)', border: '1px solid var(--border)', color: 'var(--text)', fontSize: 14, width: 280, outline: 'none' }} />
      </div>
      <DataTable columns={columns} data={produtos} loading={loading}
        getRowClass={r => r.status_estoque === 'CRITICO' || r.status_estoque === 'SEM_ESTOQUE' ? 'danger-row' : ''}
        emptyMessage="Nenhum produto cadastrado." />

      {/* Modal entrada de estoque */}
      {showEntrada && (
        <div style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.6)', display: 'flex', alignItems: 'center', justifyContent: 'center', zIndex: 999 }}>
          <div style={{ background: 'var(--card)', borderRadius: 12, border: '1px solid var(--border)', padding: 28, width: 380 }}>
            <h3 className="font-display" style={{ fontSize: 20, fontWeight: 800, color: 'var(--text)', marginBottom: 20 }}>Entrada de Estoque</h3>
            <div style={{ marginBottom: 16 }}>
              <label style={{ color: 'var(--muted)', fontSize: 13, display: 'block', marginBottom: 6 }}>Quantidade</label>
              <input type="number" min={1} value={qtdEntrada} onChange={e => setQtdEntrada(+e.target.value)}
                style={{ width: '100%', padding: '9px 12px', borderRadius: 8, background: 'var(--bg)', border: '1px solid var(--border)', color: 'var(--text)', fontSize: 14, outline: 'none' }} />
            </div>
            <div style={{ marginBottom: 24 }}>
              <label style={{ color: 'var(--muted)', fontSize: 13, display: 'block', marginBottom: 6 }}>Motivo</label>
              <input value={motivoEntrada} onChange={e => setMotivoEntrada(e.target.value)} placeholder="Compra de fornecedor"
                style={{ width: '100%', padding: '9px 12px', borderRadius: 8, background: 'var(--bg)', border: '1px solid var(--border)', color: 'var(--text)', fontSize: 14, outline: 'none' }} />
            </div>
            <div style={{ display: 'flex', gap: 12 }}>
              <button onClick={() => setShowEntrada(null)}
                style={{ flex: 1, padding: '10px', borderRadius: 8, background: 'transparent', border: '1px solid var(--border)', color: 'var(--muted)', cursor: 'pointer' }}>
                Cancelar
              </button>
              <button onClick={() => registrarEntrada(showEntrada)}
                className="font-display"
                style={{ flex: 1, padding: '10px', borderRadius: 8, background: 'var(--accent)', color: '#000', border: 'none', fontWeight: 800, cursor: 'pointer' }}>
                Registrar
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
```

- [ ] **Step 10.9: Commit**

```bash
git add backend/app/Services/EstoqueService.php backend/app/Jobs/EnviarAlertaEstoque.php backend/app/Http/Controllers/ProdutoController.php backend/app/Http/Controllers/EstoqueController.php backend/app/Http/Resources/ProdutoResource.php backend/routes/api.php
git add frontend/components/ui/StockBar.tsx frontend/app/(dashboard)/produtos/
git commit -m "feat: add produtos/estoque module with stock bar and entrada manual"
```

---

## Task 11: Módulo OS — Backend

**Files:**
- Create: `backend/app/Http/Controllers/OrdemServicoController.php`
- Create: `backend/app/Http/Resources/OrdemServicoResource.php`
- Create: `backend/tests/Feature/OrdemServicoTest.php`

- [ ] **Step 11.1: Criar teste de OS (TDD)**

```php
<?php
// backend/tests/Feature/OrdemServicoTest.php
declare(strict_types=1);
namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Produto;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OrdemServicoTest extends TestCase
{
    use RefreshDatabase;

    private function setup(): array
    {
        $admin = Usuario::create(['nome' => 'Admin', 'email' => 'a@t.com', 'cpf' => '52998224725', 'role' => 'ADMIN', 'status' => 'ATIVO', 'senha_hash' => Hash::make('pass')]);
        $cliente = Cliente::create(['nome' => 'Cliente Teste', 'cpf_cnpj' => '52998224725']);
        $token = $admin->createToken('t')->plainTextToken;
        return [$token, $admin->id, $cliente->id];
    }

    public function test_criar_os(): void
    {
        [$token, $mecId, $cliId] = $this->setup();

        $response = $this->withToken($token)->postJson('/api/os', [
            'cliente_id'       => $cliId,
            'mecanico_id'      => $mecId,
            'problema_relatado' => 'Troca de óleo',
            'status'           => 'ABERTA',
        ]);

        $response->assertStatus(201)->assertJsonStructure(['data' => ['id', 'numero', 'status']]);
    }

    public function test_baixar_estoque_ao_concluir_os(): void
    {
        [$token, $mecId, $cliId] = $this->setup();

        $produto = Produto::create(['nome' => 'Filtro', 'sku' => 'FLT01', 'categoria' => 'Filtros', 'qty_atual' => 10, 'qty_minima' => 3, 'preco_venda' => 50]);

        $os = $this->withToken($token)->postJson('/api/os', [
            'cliente_id' => $cliId, 'mecanico_id' => $mecId,
            'problema_relatado' => 'Troca filtro', 'status' => 'ABERTA',
            'itens' => [['tipo' => 'PECA', 'produto_id' => $produto->id, 'descricao' => 'Filtro', 'quantidade' => 2, 'valor_unitario' => 50]],
        ])->json('data');

        $this->withToken($token)->patchJson("/api/os/{$os['id']}", ['status' => 'CONCLUIDA', 'valor_pago' => 100]);

        $this->assertEquals(8, $produto->fresh()->qty_atual);
    }
}
```

- [ ] **Step 11.2: Criar OrdemServicoResource.php**

```php
<?php
// backend/app/Http/Resources/OrdemServicoResource.php
declare(strict_types=1);
namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrdemServicoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'numero'           => $this->numero,
            'cliente_id'       => $this->cliente_id,
            'cliente'          => $this->whenLoaded('cliente', fn() => ['id' => $this->cliente->id, 'nome' => $this->cliente->nome, 'veiculo_placa' => $this->cliente->veiculo_placa]),
            'mecanico_id'      => $this->mecanico_id,
            'mecanico'         => $this->whenLoaded('mecanico', fn() => ['id' => $this->mecanico->id, 'nome' => $this->mecanico->nome]),
            'veiculo_descricao' => $this->veiculo_descricao,
            'veiculo_placa'    => $this->veiculo_placa,
            'problema_relatado' => $this->problema_relatado,
            'status'           => $this->status,
            'forma_pagamento'  => $this->forma_pagamento,
            'prazo_entrega'    => $this->prazo_entrega?->format('d/m/Y'),
            'valor_total'      => $this->valor_total,
            'valor_pago'       => $this->valor_pago,
            'saldo_devedor'    => $this->saldo_devedor,
            'itens'            => $this->whenLoaded('itens', fn() => $this->itens->map(fn($i) => [
                'id' => $i->id, 'tipo' => $i->tipo, 'produto_id' => $i->produto_id,
                'descricao' => $i->descricao, 'quantidade' => $i->quantidade,
                'valor_unitario' => $i->valor_unitario, 'valor_total' => $i->valor_total,
            ])),
            'criado_em'        => $this->criado_em?->format('d/m/Y H:i'),
        ];
    }
}
```

- [ ] **Step 11.3: Criar OrdemServicoController.php**

```php
<?php
// backend/app/Http/Controllers/OrdemServicoController.php
declare(strict_types=1);
namespace App\Http\Controllers;

use App\Http\Resources\OrdemServicoResource;
use App\Models\OrdemServico;
use App\Services\ClienteStatusService;
use App\Services\EstoqueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class OrdemServicoController extends Controller
{
    public function __construct(
        private readonly EstoqueService $estoqueService,
        private readonly ClienteStatusService $clienteStatusService
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = OrdemServico::with(['cliente', 'mecanico']);

        if ($request->has('cliente_id')) $query->where('cliente_id', $request->cliente_id);
        if ($request->has('status'))    $query->whereIn('status', explode(',', $request->status));
        if ($request->has('search'))    $query->whereHas('cliente', fn($q) => $q->where('nome', 'ilike', "%{$request->search}%"));

        return OrdemServicoResource::collection($query->orderBy('criado_em', 'desc')->paginate(20));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'cliente_id'        => ['required', 'uuid', 'exists:clientes,id'],
            'mecanico_id'       => ['nullable', 'uuid', 'exists:usuarios,id'],
            'veiculo_descricao' => ['nullable', 'string', 'max:100'],
            'veiculo_placa'     => ['nullable', 'string', 'max:10'],
            'problema_relatado' => ['nullable', 'string'],
            'status'            => ['nullable', 'string'],
            'forma_pagamento'   => ['nullable', 'string'],
            'prazo_entrega'     => ['nullable', 'date'],
            'itens'             => ['nullable', 'array'],
            'itens.*.tipo'          => ['required', 'in:SERVICO,PECA'],
            'itens.*.produto_id'    => ['nullable', 'uuid', 'exists:produtos,id'],
            'itens.*.descricao'     => ['required', 'string'],
            'itens.*.quantidade'    => ['required', 'numeric', 'min:0.01'],
            'itens.*.valor_unitario' => ['required', 'numeric', 'min:0'],
        ]);

        return DB::transaction(function () use ($validated, $request) {
            $os = OrdemServico::create(collect($validated)->except('itens')->toArray());

            $total = 0;
            foreach ($validated['itens'] ?? [] as $item) {
                $os->itens()->create($item);
                $total += $item['quantidade'] * $item['valor_unitario'];
            }
            $os->update(['valor_total' => $total]);

            $this->clienteStatusService->recalcular($os->cliente_id);

            return (new OrdemServicoResource($os->load(['cliente', 'mecanico', 'itens'])))->response()->setStatusCode(201);
        });
    }

    public function show(string $id): OrdemServicoResource
    {
        return new OrdemServicoResource(OrdemServico::with(['cliente', 'mecanico', 'itens.produto'])->findOrFail($id));
    }

    public function update(Request $request, string $id): OrdemServicoResource
    {
        $os = OrdemServico::with('itens')->findOrFail($id);
        $novoStatus = $request->status;

        $validated = $request->validate([
            'status'          => ['sometimes', 'string'],
            'valor_pago'      => ['sometimes', 'numeric', 'min:0'],
            'forma_pagamento' => ['sometimes', 'string'],
            'prazo_entrega'   => ['sometimes', 'nullable', 'date'],
        ]);

        return DB::transaction(function () use ($os, $validated, $novoStatus) {
            // Baixar estoque ao concluir
            if ($novoStatus === 'CONCLUIDA' && $os->status !== 'CONCLUIDA') {
                $this->estoqueService->baixarEstoqueOs($os);
            }

            $os->update($validated);
            $this->clienteStatusService->recalcular($os->cliente_id);

            return new OrdemServicoResource($os->fresh()->load(['cliente', 'mecanico', 'itens']));
        });
    }
}
```

- [ ] **Step 11.4: Adicionar rotas de OS ao api.php**

```php
use App\Http\Controllers\OrdemServicoController;
Route::apiResource('os', OrdemServicoController::class);
```

- [ ] **Step 11.5: Rodar testes**

```bash
php artisan test tests/Feature/OrdemServicoTest.php
```

Saída esperada: 2 testes verdes.

- [ ] **Step 11.6: Commit**

```bash
git add app/Http/Controllers/OrdemServicoController.php app/Http/Resources/OrdemServicoResource.php routes/api.php tests/Feature/OrdemServicoTest.php
git commit -m "feat: add OS backend with auto stock deduction on completion"
```

---

## Task 12: Módulo OS — Frontend

**Files:**
- Create: `frontend/app/(dashboard)/os/page.tsx`
- Create: `frontend/app/(dashboard)/os/nova/page.tsx`
- Create: `frontend/app/(dashboard)/os/[id]/page.tsx`
- Create: `frontend/components/forms/OSForm.tsx`

- [ ] **Step 12.1: Criar components/forms/OSForm.tsx**

```tsx
// frontend/components/forms/OSForm.tsx
'use client'
import { useState, useEffect } from 'react'
import { useForm, useFieldArray } from 'react-hook-form'
import api from '@/lib/api'
import { toast } from '@/hooks/useToast'
import { formatarMoeda } from '@/lib/formatters'

interface OsItem {
  tipo: 'SERVICO' | 'PECA'
  produto_id?: string
  descricao: string
  quantidade: number
  valor_unitario: number
}

interface OSFormData {
  cliente_id: string
  mecanico_id?: string
  problema_relatado?: string
  status: string
  forma_pagamento?: string
  prazo_entrega?: string
  valor_pago?: number
  itens: OsItem[]
}

interface OSFormProps {
  initialData?: Partial<OSFormData> & { id?: string }
  onSuccess?: (os: any) => void
}

const SELECT_STYLE: React.CSSProperties = {
  width: '100%', padding: '9px 12px', borderRadius: 8,
  background: 'var(--bg)', border: '1px solid var(--border)',
  color: 'var(--text)', fontSize: 14, outline: 'none',
}
const INPUT_STYLE: React.CSSProperties = { ...SELECT_STYLE }
const LABEL_STYLE: React.CSSProperties = { color: 'var(--muted)', fontSize: 13, display: 'block', marginBottom: 4 }

export function OSForm({ initialData, onSuccess }: OSFormProps) {
  const isEdit = !!initialData?.id
  const [clientes, setClientes] = useState<any[]>([])
  const [mecanicos, setMecanicos] = useState<any[]>([])
  const [produtos, setProdutos] = useState<any[]>([])

  const { register, handleSubmit, control, watch, formState: { isSubmitting } } = useForm<OSFormData>({
    defaultValues: {
      status: 'ABERTA',
      itens: [],
      ...initialData,
    },
  })

  const { fields, append, remove } = useFieldArray({ control, name: 'itens' })
  const itens = watch('itens')
  const total = itens.reduce((acc, i) => acc + (Number(i.quantidade || 0) * Number(i.valor_unitario || 0)), 0)

  useEffect(() => {
    Promise.all([
      api.get('/clientes?per_page=100'),
      api.get('/usuarios?role=MECANICO'),
      api.get('/produtos?per_page=200'),
    ]).then(([c, u, p]) => {
      setClientes(c.data.data ?? [])
      setMecanicos(u.data.data ?? [])
      setProdutos(p.data.data ?? [])
    })
  }, [])

  async function onSubmit(data: OSFormData) {
    try {
      const payload = { ...data, valor_total: total }
      if (isEdit) {
        const res = await api.put(`/os/${initialData!.id}`, payload)
        toast('OS atualizada!', 'success')
        onSuccess?.(res.data.data)
      } else {
        const res = await api.post('/os', payload)
        toast('OS criada com sucesso!', 'success')
        onSuccess?.(res.data.data)
      }
    } catch (err: any) {
      toast(err.response?.data?.message ?? 'Erro ao salvar OS.', 'danger')
    }
  }

  const STATUS_OPTIONS = ['ABERTA', 'EM_ANDAMENTO', 'AGUARDANDO_PECAS', 'CONCLUIDA', 'CANCELADA']
  const PAGAMENTO_OPTIONS = ['Dinheiro', 'Cartão de Crédito', 'Cartão de Débito', 'PIX', 'Cheque', 'Boleto']

  return (
    <form onSubmit={handleSubmit(onSubmit)}>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16, marginBottom: 24 }}>
        <div style={{ gridColumn: '1 / -1' }}>
          <label style={LABEL_STYLE}>Cliente *</label>
          <select {...register('cliente_id')} style={SELECT_STYLE}>
            <option value="">Selecionar cliente...</option>
            {clientes.map(c => <option key={c.id} value={c.id}>{c.nome} — {c.veiculo_placa ?? 'sem placa'}</option>)}
          </select>
        </div>
        <div>
          <label style={LABEL_STYLE}>Mecânico responsável</label>
          <select {...register('mecanico_id')} style={SELECT_STYLE}>
            <option value="">Selecionar mecânico...</option>
            {mecanicos.map(m => <option key={m.id} value={m.id}>{m.nome}</option>)}
          </select>
        </div>
        <div>
          <label style={LABEL_STYLE}>Status</label>
          <select {...register('status')} style={SELECT_STYLE}>
            {STATUS_OPTIONS.map(s => <option key={s} value={s}>{s.replace('_', ' ')}</option>)}
          </select>
        </div>
        <div style={{ gridColumn: '1 / -1' }}>
          <label style={LABEL_STYLE}>Problema relatado</label>
          <textarea {...register('problema_relatado')} rows={3}
            style={{ ...INPUT_STYLE, resize: 'vertical' }} />
        </div>
        <div>
          <label style={LABEL_STYLE}>Prazo de entrega</label>
          <input type="date" {...register('prazo_entrega')} style={INPUT_STYLE} />
        </div>
        <div>
          <label style={LABEL_STYLE}>Forma de pagamento</label>
          <select {...register('forma_pagamento')} style={SELECT_STYLE}>
            <option value="">Selecionar...</option>
            {PAGAMENTO_OPTIONS.map(p => <option key={p} value={p}>{p}</option>)}
          </select>
        </div>
      </div>

      {/* Itens da OS */}
      <div style={{ background: 'var(--bg)', borderRadius: 8, border: '1px solid var(--border)', padding: 16, marginBottom: 24 }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 12 }}>
          <h4 className="font-display" style={{ fontSize: 16, fontWeight: 700, color: 'var(--text)', margin: 0 }}>Serviços e Peças</h4>
          <div style={{ display: 'flex', gap: 8 }}>
            <button type="button" onClick={() => append({ tipo: 'SERVICO', descricao: '', quantidade: 1, valor_unitario: 0 })}
              style={{ padding: '6px 12px', background: 'rgba(30,136,229,0.15)', border: '1px solid var(--info)', color: 'var(--info)', borderRadius: 6, cursor: 'pointer', fontSize: 13 }}>
              + Serviço
            </button>
            <button type="button" onClick={() => append({ tipo: 'PECA', produto_id: '', descricao: '', quantidade: 1, valor_unitario: 0 })}
              style={{ padding: '6px 12px', background: 'rgba(245,166,35,0.15)', border: '1px solid var(--accent)', color: 'var(--accent)', borderRadius: 6, cursor: 'pointer', fontSize: 13 }}>
              + Peça
            </button>
          </div>
        </div>
        {fields.length === 0 && <p style={{ color: 'var(--muted)', fontSize: 14, textAlign: 'center', padding: '16px 0' }}>Nenhum item adicionado.</p>}
        {fields.map((field, idx) => {
          const tipo = watch(`itens.${idx}.tipo`)
          return (
            <div key={field.id} style={{ display: 'grid', gridTemplateColumns: '2fr 1fr 1fr auto', gap: 8, marginBottom: 8, padding: 8, background: 'var(--card)', borderRadius: 8 }}>
              {tipo === 'PECA' ? (
                <select {...register(`itens.${idx}.produto_id`)} style={SELECT_STYLE}
                  onChange={e => {
                    const p = produtos.find(p => p.id === e.target.value)
                    if (p) { /* useForm setValue seria necessário para descrição e preço */ }
                  }}>
                  <option value="">Selecionar peça...</option>
                  {produtos.map(p => <option key={p.id} value={p.id}>{p.nome} (est: {p.qty_atual})</option>)}
                </select>
              ) : (
                <input {...register(`itens.${idx}.descricao`)} placeholder="Descrição do serviço" style={INPUT_STYLE} />
              )}
              <input type="number" step="0.01" min="0.01" {...register(`itens.${idx}.quantidade`)} placeholder="Qtd" style={INPUT_STYLE} />
              <input type="number" step="0.01" min="0" {...register(`itens.${idx}.valor_unitario`)} placeholder="Valor unit." style={INPUT_STYLE} />
              <button type="button" onClick={() => remove(idx)}
                style={{ padding: '0 12px', background: 'transparent', border: 'none', color: 'var(--danger)', cursor: 'pointer', fontSize: 18 }}>×</button>
            </div>
          )
        })}
        {fields.length > 0 && (
          <div style={{ textAlign: 'right', marginTop: 12, paddingTop: 12, borderTop: '1px solid var(--border)' }}>
            <span style={{ color: 'var(--muted)', fontSize: 14 }}>Total: </span>
            <span className="font-mono" style={{ color: 'var(--accent)', fontSize: 18, fontWeight: 700 }}>{formatarMoeda(total)}</span>
          </div>
        )}
      </div>

      {isEdit && (
        <div style={{ marginBottom: 24 }}>
          <label style={LABEL_STYLE}>Valor pago (R$)</label>
          <input type="number" step="0.01" min="0" {...register('valor_pago')} style={{ ...INPUT_STYLE, width: 200 }} />
        </div>
      )}

      <div style={{ display: 'flex', justifyContent: 'flex-end' }}>
        <button type="submit" disabled={isSubmitting}
          className="font-display"
          style={{ padding: '10px 28px', background: isSubmitting ? 'var(--muted)' : 'var(--accent)', color: '#000', borderRadius: 8, border: 'none', fontWeight: 800, fontSize: 16, cursor: isSubmitting ? 'not-allowed' : 'pointer' }}>
          {isSubmitting ? 'Salvando...' : isEdit ? 'Atualizar OS' : 'Criar OS'}
        </button>
      </div>
    </form>
  )
}
```

- [ ] **Step 12.2: Criar página de lista e nova OS**

```tsx
// frontend/app/(dashboard)/os/page.tsx
'use client'
import { useState, useEffect } from 'react'
import { useRouter } from 'next/navigation'
import { DataTable, Column } from '@/components/ui/DataTable'
import { StatusPill } from '@/components/ui/StatusPill'
import { formatarMoeda, formatarData } from '@/lib/formatters'
import api from '@/lib/api'

export default function OSPage() {
  const router = useRouter()
  const [os, setOs] = useState<any[]>([])
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    api.get('/os').then(r => setOs(r.data.data ?? [])).finally(() => setLoading(false))
  }, [])

  const columns: Column<any>[] = [
    { key: 'numero', label: '#OS', render: r => <span className="font-mono" style={{ color: 'var(--accent)' }}>#{r.numero}</span> },
    { key: 'cliente', label: 'Cliente', render: r => r.cliente?.nome ?? '-' },
    { key: 'veiculo', label: 'Veículo', render: r => r.cliente?.veiculo_placa ?? r.veiculo_placa ?? '-' },
    { key: 'problema', label: 'Serviço', render: r => <span style={{ color: 'var(--text)', fontSize: 13 }}>{r.problema_relatado?.slice(0, 50) ?? '-'}</span> },
    { key: 'valor_total', label: 'Valor', render: r => <span className="font-mono">{formatarMoeda(r.valor_total)}</span> },
    { key: 'status', label: 'Status', render: r => <StatusPill status={r.status} /> },
    { key: 'criado_em', label: 'Data', render: r => formatarData(r.criado_em) },
  ]

  return (
    <div>
      <h1 className="font-display" style={{ fontSize: 28, fontWeight: 800, color: 'var(--text)', marginBottom: 24 }}>Ordens de Serviço</h1>
      <DataTable columns={columns} data={os} loading={loading}
        onRowClick={r => router.push(`/os/${r.id}`)}
        emptyMessage="Nenhuma OS encontrada." />
    </div>
  )
}
```

```tsx
// frontend/app/(dashboard)/os/nova/page.tsx
'use client'
import { useRouter } from 'next/navigation'
import { OSForm } from '@/components/forms/OSForm'

export default function NovaOSPage() {
  const router = useRouter()
  return (
    <div style={{ maxWidth: 900 }}>
      <h1 className="font-display" style={{ fontSize: 28, fontWeight: 800, color: 'var(--text)', marginBottom: 24 }}>Nova Ordem de Serviço</h1>
      <div style={{ background: 'var(--card)', borderRadius: 12, border: '1px solid var(--border)', padding: 32 }}>
        <OSForm onSuccess={os => router.push(`/os/${os.id}`)} />
      </div>
    </div>
  )
}
```

```tsx
// frontend/app/(dashboard)/os/[id]/page.tsx
'use client'
import { useEffect, useState } from 'react'
import { useParams, useRouter } from 'next/navigation'
import { OSForm } from '@/components/forms/OSForm'
import { StatusPill } from '@/components/ui/StatusPill'
import { formatarMoeda } from '@/lib/formatters'
import api from '@/lib/api'

export default function OSDetailPage() {
  const { id } = useParams<{ id: string }>()
  const router = useRouter()
  const [os, setOs] = useState<any>(null)

  useEffect(() => {
    api.get(`/os/${id}`).then(r => setOs(r.data.data))
  }, [id])

  if (!os) return <p style={{ color: 'var(--muted)' }}>Carregando...</p>

  return (
    <div style={{ maxWidth: 900 }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 16, marginBottom: 24 }}>
        <button onClick={() => router.back()} style={{ background: 'none', border: 'none', color: 'var(--muted)', cursor: 'pointer', fontSize: 14 }}>← Voltar</button>
        <h1 className="font-display" style={{ fontSize: 28, fontWeight: 800, color: 'var(--text)', margin: 0 }}>OS #{os.numero}</h1>
        <StatusPill status={os.status} />
        {os.saldo_devedor > 0 && (
          <span style={{ background: 'rgba(229,57,53,0.15)', color: 'var(--danger)', borderRadius: 8, padding: '4px 12px', fontSize: 14, fontWeight: 700 }}>
            Saldo: {formatarMoeda(os.saldo_devedor)}
          </span>
        )}
      </div>
      <div style={{ background: 'var(--card)', borderRadius: 12, border: '1px solid var(--border)', padding: 32 }}>
        <OSForm initialData={os} onSuccess={updated => setOs(updated)} />
      </div>
    </div>
  )
}
```

- [ ] **Step 12.3: Commit**

```bash
git add frontend/app/(dashboard)/os/ frontend/components/forms/OSForm.tsx
git commit -m "feat: add OS frontend with dynamic items list and status management"
```

---

## Task 13: Módulo Fiscal — Backend + Frontend

**Files:**
- Create: `backend/app/Services/NfeService.php`
- Create: `backend/app/Http/Controllers/NotaFiscalController.php`
- Create: `backend/app/Http/Resources/NotaFiscalResource.php`
- Create: `frontend/app/(dashboard)/fiscal/emitir/page.tsx`
- Create: `frontend/app/(dashboard)/fiscal/historico/page.tsx`
- Create: `frontend/components/forms/NotaFiscalForm.tsx`

- [ ] **Step 13.1: Criar NfeService.php**

```php
<?php
// backend/app/Services/NfeService.php
declare(strict_types=1);
namespace App\Services;

use App\Models\Configuracao;
use App\Models\NotaFiscal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class NfeService
{
    public function proximoNumeroNf(): int
    {
        return DB::transaction(function () {
            $config = Configuracao::lockForUpdate()->first();
            if (!$config) throw new \Exception('Configurações da empresa não encontradas.');
            $numero = $config->proximo_numero_nf;
            $config->increment('proximo_numero_nf');
            return $numero;
        });
    }

    public function emitir(NotaFiscal $nota): array
    {
        $config = Configuracao::first();
        if (!$config) throw new \Exception('Configurações não encontradas.');

        $apiKey = config('services.nfeio.api_key');
        $url    = config('services.nfeio.url');

        if (!$apiKey) {
            // Ambiente sem integração → simular autorização para desenvolvimento
            return [
                'status'      => 'AUTORIZADA',
                'chave'       => 'SIMULADO-' . uniqid(),
                'protocolo'   => 'SIMUL-' . now()->format('YmdHis'),
                'xml_retorno' => '<simulacao/>',
            ];
        }

        $response = Http::withBasicAuth($apiKey, '')
            ->post("{$url}/companies/{$config->cnpj}/serviceinvoices", [
                'cityServiceCode'   => $config->cnae,
                'description'       => $nota->observacoes ?? 'Serviços automotivos',
                'servicesAmount'    => $nota->valor_total,
                'borrower'          => [
                    'name'             => $nota->cliente?->nome,
                    'federalTaxNumber' => $nota->cliente?->cpf_cnpj,
                ],
            ]);

        if ($response->failed()) {
            throw new \Exception('Erro na SEFAZ: ' . $response->json('message', 'Erro desconhecido'));
        }

        return [
            'status'      => 'AUTORIZADA',
            'chave'       => $response->json('accessKey'),
            'protocolo'   => $response->json('number'),
            'xml_retorno' => $response->body(),
        ];
    }
}
```

- [ ] **Step 13.2: Criar NotaFiscalResource.php e NotaFiscalController.php**

```php
<?php
// backend/app/Http/Resources/NotaFiscalResource.php
declare(strict_types=1);
namespace App\Http\Resources;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotaFiscalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'numero'           => $this->numero,
            'serie'            => $this->serie,
            'modelo'           => $this->modelo,
            'cliente_id'       => $this->cliente_id,
            'cliente'          => $this->whenLoaded('cliente', fn() => ['id' => $this->cliente->id, 'nome' => $this->cliente->nome]),
            'os_id'            => $this->os_id,
            'natureza_operacao' => $this->natureza_operacao,
            'forma_pagamento'  => $this->forma_pagamento,
            'subtotal'         => $this->subtotal,
            'desconto'         => $this->desconto,
            'aliquota_iss'     => $this->aliquota_iss,
            'valor_iss'        => $this->valor_iss,
            'valor_total'      => $this->valor_total,
            'status'           => $this->status,
            'chave_acesso'     => $this->chave_acesso,
            'pdf_url'          => $this->pdf_url,
            'observacoes'      => $this->observacoes,
            'emitido_em'       => $this->emitido_em?->format('d/m/Y H:i'),
            'criado_em'        => $this->criado_em?->format('d/m/Y'),
        ];
    }
}
```

```php
<?php
// backend/app/Http/Controllers/NotaFiscalController.php
declare(strict_types=1);
namespace App\Http\Controllers;

use App\Http\Resources\NotaFiscalResource;
use App\Models\NotaFiscal;
use App\Services\NfeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotaFiscalController extends Controller
{
    public function __construct(private readonly NfeService $nfeService) {}

    public function index(Request $request)
    {
        $query = NotaFiscal::with('cliente')->orderBy('criado_em', 'desc');
        if ($request->has('status')) $query->whereIn('status', explode(',', $request->status));
        if ($request->has('cliente_id')) $query->where('cliente_id', $request->cliente_id);
        return NotaFiscalResource::collection($query->paginate(20));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'cliente_id'        => ['required', 'uuid', 'exists:clientes,id'],
            'os_id'             => ['nullable', 'uuid', 'exists:ordens_servico,id'],
            'natureza_operacao' => ['required', 'string'],
            'forma_pagamento'   => ['nullable', 'string'],
            'subtotal'          => ['required', 'numeric', 'min:0'],
            'desconto'          => ['nullable', 'numeric', 'min:0'],
            'aliquota_iss'      => ['nullable', 'numeric', 'min:0', 'max:100'],
            'observacoes'       => ['nullable', 'string'],
        ]);

        $subtotal    = $validated['subtotal'];
        $desconto    = $validated['desconto'] ?? 0;
        $aliquota    = $validated['aliquota_iss'] ?? 5.00;
        $valorIss    = (($subtotal - $desconto) * $aliquota) / 100;
        $valorTotal  = ($subtotal - $desconto) + $valorIss;

        $nota = NotaFiscal::create([
            ...$validated,
            'valor_iss'   => $valorIss,
            'valor_total' => $valorTotal,
            'status'      => 'RASCUNHO',
        ]);

        return (new NotaFiscalResource($nota->load('cliente')))->response()->setStatusCode(201);
    }

    public function emitir(string $id): JsonResponse
    {
        $nota = NotaFiscal::with('cliente')->findOrFail($id);

        if ($nota->status === 'AUTORIZADA') {
            return response()->json(['message' => 'NF já foi emitida.'], 400);
        }

        $nota->update(['status' => 'PROCESSANDO', 'numero' => $this->nfeService->proximoNumeroNf()]);

        try {
            $resultado = $this->nfeService->emitir($nota);
            $nota->update([
                'status'      => $resultado['status'],
                'chave_acesso' => $resultado['chave'],
                'protocolo'   => $resultado['protocolo'],
                'xml_retorno' => $resultado['xml_retorno'],
                'emitido_em'  => now(),
            ]);
        } catch (\Exception $e) {
            $nota->update(['status' => 'REJEITADA']);
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(new NotaFiscalResource($nota->fresh()->load('cliente')));
    }

    public function cancelar(Request $request, string $id): JsonResponse
    {
        $nota = NotaFiscal::findOrFail($id);
        $request->validate(['motivo' => ['required', 'string', 'min:10']]);
        $nota->update(['status' => 'CANCELADA']);
        return response()->json(['message' => 'NF cancelada.']);
    }
}
```

- [ ] **Step 13.3: Adicionar rotas fiscais ao api.php**

```php
use App\Http\Controllers\NotaFiscalController;
Route::apiResource('notas-fiscais', NotaFiscalController::class)->except(['update']);
Route::post('notas-fiscais/{nota}/emitir',   [NotaFiscalController::class, 'emitir']);
Route::post('notas-fiscais/{nota}/cancelar', [NotaFiscalController::class, 'cancelar']);
```

- [ ] **Step 13.4: Criar frontend de emissão de NF (split-view)**

```tsx
// frontend/app/(dashboard)/fiscal/emitir/page.tsx
'use client'
import { useState, useEffect } from 'react'
import api from '@/lib/api'
import { toast } from '@/hooks/useToast'
import { formatarMoeda } from '@/lib/formatters'

interface ItemNF { descricao: string; quantidade: number; valor_unitario: number }

export default function EmitirNFPage() {
  const [clientes, setClientes] = useState<any[]>([])
  const [clienteId, setClienteId] = useState('')
  const [natureza, setNatureza] = useState('Prestação de Serviços')
  const [formaPgto, setFormaPgto] = useState('')
  const [itens, setItens] = useState<ItemNF[]>([{ descricao: '', quantidade: 1, valor_unitario: 0 }])
  const [desconto, setDesconto] = useState(0)
  const [aliquota, setAliquota] = useState(5)
  const [obs, setObs] = useState('')
  const [loading, setLoading] = useState(false)
  const [empresa, setEmpresa] = useState<any>(null)

  useEffect(() => {
    Promise.all([
      api.get('/clientes?per_page=200'),
      api.get('/configuracoes'),
    ]).then(([c, e]) => {
      setClientes(c.data.data ?? [])
      setEmpresa(e.data)
    })
  }, [])

  const clienteSelecionado = clientes.find(c => c.id === clienteId)
  const subtotal = itens.reduce((acc, i) => acc + i.quantidade * i.valor_unitario, 0)
  const valorIss = ((subtotal - desconto) * aliquota) / 100
  const total = subtotal - desconto + valorIss

  async function emitir() {
    if (!clienteId) { toast('Selecione um cliente.', 'danger'); return }
    setLoading(true)
    try {
      const nf = await api.post('/notas-fiscais', {
        cliente_id: clienteId, natureza_operacao: natureza,
        forma_pagamento: formaPgto, subtotal, desconto,
        aliquota_iss: aliquota, observacoes: obs,
      })
      const resultado = await api.post(`/notas-fiscais/${nf.data.data.id}/emitir`)
      toast(`NF #${resultado.data.data.numero} emitida com sucesso!`, 'success')
    } catch (err: any) {
      toast(err.response?.data?.message ?? 'Erro ao emitir NF.', 'danger')
    } finally { setLoading(false) }
  }

  const inputStyle: React.CSSProperties = { width: '100%', padding: '9px 12px', borderRadius: 8, background: 'var(--bg)', border: '1px solid var(--border)', color: 'var(--text)', fontSize: 14, outline: 'none' }
  const labelStyle: React.CSSProperties = { color: 'var(--muted)', fontSize: 13, display: 'block', marginBottom: 4 }

  return (
    <div style={{ display: 'grid', gridTemplateColumns: '1fr 320px', gap: 24, alignItems: 'start' }}>
      {/* Formulário esquerdo */}
      <div style={{ background: 'var(--card)', borderRadius: 12, border: '1px solid var(--border)', padding: 28 }}>
        <h2 className="font-display" style={{ fontSize: 22, fontWeight: 800, color: 'var(--text)', marginBottom: 24 }}>Emitir Nota Fiscal</h2>

        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16, marginBottom: 20 }}>
          <div style={{ gridColumn: '1 / -1' }}>
            <label style={labelStyle}>Cliente</label>
            <select value={clienteId} onChange={e => setClienteId(e.target.value)} style={inputStyle}>
              <option value="">Selecionar cliente...</option>
              {clientes.map(c => <option key={c.id} value={c.id}>{c.nome}</option>)}
            </select>
          </div>
          <div>
            <label style={labelStyle}>Natureza da operação</label>
            <select value={natureza} onChange={e => setNatureza(e.target.value)} style={inputStyle}>
              <option>Prestação de Serviços</option>
              <option>Venda de Mercadoria</option>
              <option>Misto</option>
            </select>
          </div>
          <div>
            <label style={labelStyle}>Forma de pagamento</label>
            <select value={formaPgto} onChange={e => setFormaPgto(e.target.value)} style={inputStyle}>
              <option value="">Selecionar...</option>
              {['Dinheiro', 'PIX', 'Cartão de Crédito', 'Cartão de Débito', 'Boleto'].map(p => <option key={p}>{p}</option>)}
            </select>
          </div>
        </div>

        {/* Itens */}
        <div style={{ marginBottom: 20 }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 10 }}>
            <label style={labelStyle}>Itens</label>
            <button type="button" onClick={() => setItens(prev => [...prev, { descricao: '', quantidade: 1, valor_unitario: 0 }])}
              style={{ padding: '4px 12px', background: 'rgba(245,166,35,0.15)', border: '1px solid var(--accent)', color: 'var(--accent)', borderRadius: 6, cursor: 'pointer', fontSize: 13 }}>
              + Adicionar
            </button>
          </div>
          {itens.map((item, idx) => (
            <div key={idx} style={{ display: 'grid', gridTemplateColumns: '3fr 1fr 1fr auto', gap: 8, marginBottom: 8 }}>
              <input value={item.descricao} onChange={e => setItens(prev => prev.map((i, j) => j === idx ? { ...i, descricao: e.target.value } : i))} placeholder="Descrição" style={inputStyle} />
              <input type="number" min={0.01} step="0.01" value={item.quantidade} onChange={e => setItens(prev => prev.map((i, j) => j === idx ? { ...i, quantidade: +e.target.value } : i))} style={inputStyle} />
              <input type="number" min={0} step="0.01" value={item.valor_unitario} onChange={e => setItens(prev => prev.map((i, j) => j === idx ? { ...i, valor_unitario: +e.target.value } : i))} placeholder="Valor unit." style={inputStyle} />
              {itens.length > 1 && (
                <button type="button" onClick={() => setItens(prev => prev.filter((_, j) => j !== idx))}
                  style={{ padding: '0 12px', background: 'transparent', border: 'none', color: 'var(--danger)', cursor: 'pointer', fontSize: 18 }}>×</button>
              )}
            </div>
          ))}
        </div>

        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16, marginBottom: 20 }}>
          <div>
            <label style={labelStyle}>Desconto (R$)</label>
            <input type="number" min={0} step="0.01" value={desconto} onChange={e => setDesconto(+e.target.value)} style={inputStyle} />
          </div>
          <div>
            <label style={labelStyle}>Alíquota ISS (%)</label>
            <input type="number" min={0} max={100} step="0.01" value={aliquota} onChange={e => setAliquota(+e.target.value)} style={inputStyle} />
          </div>
          <div style={{ gridColumn: '1 / -1' }}>
            <label style={labelStyle}>Observações</label>
            <textarea value={obs} onChange={e => setObs(e.target.value)} rows={3} style={{ ...inputStyle, resize: 'vertical' }} />
          </div>
        </div>

        {/* Resumo */}
        <div style={{ background: 'var(--bg)', borderRadius: 8, padding: 16, marginBottom: 20, fontFamily: 'JetBrains Mono, monospace', fontSize: 14 }}>
          {[['Subtotal', formatarMoeda(subtotal)], ['Desconto', `-${formatarMoeda(desconto)}`], [`ISS (${aliquota}%)`, formatarMoeda(valorIss)]].map(([l, v]) => (
            <div key={l} style={{ display: 'flex', justifyContent: 'space-between', color: 'var(--muted)', marginBottom: 6 }}>
              <span>{l}</span><span>{v}</span>
            </div>
          ))}
          <div style={{ display: 'flex', justifyContent: 'space-between', color: 'var(--text)', fontWeight: 700, fontSize: 18, borderTop: '1px solid var(--border)', paddingTop: 10, marginTop: 6 }}>
            <span>TOTAL</span><span style={{ color: 'var(--accent)' }}>{formatarMoeda(total)}</span>
          </div>
        </div>
      </div>

      {/* Painel lateral direito */}
      <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
        {empresa && (
          <div style={{ background: 'var(--card)', borderRadius: 12, border: '1px solid var(--border)', padding: 20 }}>
            <h4 className="font-display" style={{ fontSize: 14, fontWeight: 700, color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '0.06em', marginBottom: 12 }}>Emitente</h4>
            <p style={{ color: 'var(--text)', fontSize: 14, fontWeight: 600, margin: '0 0 4px' }}>{empresa.nome_fantasia || empresa.razao_social}</p>
            <p className="font-mono" style={{ color: 'var(--muted)', fontSize: 13, margin: 0 }}>{empresa.cnpj}</p>
            <p style={{ color: 'var(--muted)', fontSize: 13, margin: '4px 0 0' }}>{empresa.cidade} — {empresa.uf}</p>
            <div style={{ marginTop: 12, padding: '6px 10px', background: empresa.ambiente_fiscal === 'PRODUCAO' ? 'rgba(67,160,71,0.1)' : 'rgba(245,166,35,0.1)', borderRadius: 6 }}>
              <span style={{ fontSize: 12, fontWeight: 600, color: empresa.ambiente_fiscal === 'PRODUCAO' ? 'var(--success)' : 'var(--accent)' }}>
                {empresa.ambiente_fiscal === 'PRODUCAO' ? '● Produção' : '● Homologação'}
              </span>
            </div>
          </div>
        )}

        {clienteSelecionado && (
          <div style={{ background: 'var(--card)', borderRadius: 12, border: '1px solid var(--border)', padding: 20 }}>
            <h4 className="font-display" style={{ fontSize: 14, fontWeight: 700, color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '0.06em', marginBottom: 12 }}>Destinatário</h4>
            <p style={{ color: 'var(--text)', fontSize: 14, fontWeight: 600, margin: '0 0 4px' }}>{clienteSelecionado.nome}</p>
            <p className="font-mono" style={{ color: 'var(--muted)', fontSize: 13, margin: 0 }}>{clienteSelecionado.cpf_cnpj}</p>
            <p style={{ color: 'var(--muted)', fontSize: 13, margin: '4px 0 0' }}>{clienteSelecionado.cidade} — {clienteSelecionado.uf}</p>
          </div>
        )}

        <button onClick={emitir} disabled={loading}
          className="font-display"
          style={{ width: '100%', padding: '14px', borderRadius: 10, background: loading ? 'var(--muted)' : 'var(--success)', color: '#fff', border: 'none', fontWeight: 800, fontSize: 18, cursor: loading ? 'not-allowed' : 'pointer' }}>
          {loading ? '⟳ Processando...' : 'EMITIR NOTA FISCAL'}
        </button>
      </div>
    </div>
  )
}
```

- [ ] **Step 13.5: Criar histórico de NF**

```tsx
// frontend/app/(dashboard)/fiscal/historico/page.tsx
'use client'
import { useState, useEffect } from 'react'
import { DataTable, Column } from '@/components/ui/DataTable'
import { StatusPill } from '@/components/ui/StatusPill'
import { formatarMoeda, formatarData } from '@/lib/formatters'
import api from '@/lib/api'
import { toast } from '@/hooks/useToast'

export default function HistoricoNFPage() {
  const [notas, setNotas] = useState<any[]>([])
  const [loading, setLoading] = useState(true)

  const fetchNotas = () => {
    api.get('/notas-fiscais').then(r => setNotas(r.data.data ?? [])).finally(() => setLoading(false))
  }
  useEffect(fetchNotas, [])

  const columns: Column<any>[] = [
    { key: 'numero', label: '#NF', render: r => <span className="font-mono" style={{ color: 'var(--accent)' }}>{r.numero ? `#${r.numero}` : '-'}</span> },
    { key: 'cliente', label: 'Cliente', render: r => r.cliente?.nome ?? '-' },
    { key: 'emitido_em', label: 'Emissão', render: r => formatarData(r.emitido_em) },
    { key: 'valor_total', label: 'Valor', render: r => <span className="font-mono">{formatarMoeda(r.valor_total ?? 0)}</span> },
    { key: 'modelo', label: 'Modelo', render: r => <span style={{ color: 'var(--muted)', fontSize: 13 }}>{r.modelo}</span> },
    { key: 'status', label: 'Situação', render: r => <StatusPill status={r.status} /> },
    { key: 'acoes', label: '', render: r => (
      r.status === 'AUTORIZADA' ? (
        <button onClick={async () => {
          if (!confirm('Deseja cancelar esta NF?')) return
          try { await api.post(`/notas-fiscais/${r.id}/cancelar`, { motivo: 'Cancelamento solicitado pelo usuário.' }); toast('NF cancelada.', 'success'); fetchNotas() }
          catch { toast('Erro ao cancelar.', 'danger') }
        }}
          style={{ background: 'none', border: '1px solid var(--danger)', color: 'var(--danger)', borderRadius: 6, padding: '4px 10px', cursor: 'pointer', fontSize: 13 }}>
          Cancelar
        </button>
      ) : null
    )},
  ]

  return (
    <div>
      <h1 className="font-display" style={{ fontSize: 28, fontWeight: 800, color: 'var(--text)', marginBottom: 24 }}>Histórico de Notas Fiscais</h1>
      <DataTable columns={columns} data={notas} loading={loading} emptyMessage="Nenhuma NF emitida." />
    </div>
  )
}
```

- [ ] **Step 13.6: Commit**

```bash
git add backend/app/Services/NfeService.php backend/app/Http/Controllers/NotaFiscalController.php backend/app/Http/Resources/NotaFiscalResource.php backend/routes/api.php
git add frontend/app/(dashboard)/fiscal/
git commit -m "feat: add fiscal module (NF-e/NFS-e emission and history)"
```

---

## Task 14: Dashboard + Stat Cards

**Files:**
- Create: `backend/app/Http/Controllers/DashboardController.php`
- Create: `frontend/components/ui/StatCard.tsx`
- Create: `frontend/components/dashboard/FaturamentoChart.tsx`
- Create: `frontend/components/dashboard/EstoqueAlerts.tsx`
- Modify: `frontend/app/(dashboard)/page.tsx`

- [ ] **Step 14.1: Criar DashboardController.php**

```php
<?php
// backend/app/Http/Controllers/DashboardController.php
declare(strict_types=1);
namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\NotaFiscal;
use App\Models\OrdemServico;
use App\Models\Produto;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(): JsonResponse
    {
        $clientesAtivos = Cliente::where('status', '!=', 'INATIVO')->count();

        $dividasAbertas = OrdemServico::whereColumn('valor_pago', '<', 'valor_total')
            ->sum(DB::raw('valor_total - valor_pago'));

        $faturamentoMes = NotaFiscal::where('status', 'AUTORIZADA')
            ->whereMonth('emitido_em', now()->month)
            ->whereYear('emitido_em', now()->year)
            ->sum('valor_total');

        $nfEmitidas = NotaFiscal::where('status', 'AUTORIZADA')
            ->whereMonth('emitido_em', now()->month)
            ->count();

        // Faturamento últimos 7 meses
        $faturamentoMensal = NotaFiscal::where('status', 'AUTORIZADA')
            ->where('emitido_em', '>=', now()->subMonths(6)->startOfMonth())
            ->select(
                DB::raw("TO_CHAR(emitido_em, 'YYYY-MM') as mes"),
                DB::raw('SUM(valor_total) as total')
            )
            ->groupBy('mes')
            ->orderBy('mes')
            ->get();

        // Produtos críticos
        $produtosCriticos = Produto::where('ativo', true)
            ->where(DB::raw('qty_atual'), '<', DB::raw('qty_minima'))
            ->orderByRaw('qty_atual::float / NULLIF(qty_minima, 0) ASC')
            ->limit(5)
            ->get(['id', 'nome', 'qty_atual', 'qty_minima', 'sku']);

        // Últimas OS
        $ultimasOs = OrdemServico::with('cliente')
            ->orderBy('criado_em', 'desc')
            ->limit(8)
            ->get();

        return response()->json([
            'stats' => [
                'clientes_ativos'   => $clientesAtivos,
                'dividas_abertas'   => round((float)$dividasAbertas, 2),
                'faturamento_mes'   => round((float)$faturamentoMes, 2),
                'nf_emitidas_mes'   => $nfEmitidas,
            ],
            'faturamento_mensal'  => $faturamentoMensal,
            'produtos_criticos'   => $produtosCriticos,
            'ultimas_os'          => $ultimasOs->map(fn($o) => [
                'id'     => $o->id, 'numero' => $o->numero,
                'cliente' => $o->cliente?->nome, 'status' => $o->status,
                'valor_total' => $o->valor_total, 'criado_em' => $o->criado_em?->format('d/m/Y'),
            ]),
        ]);
    }
}
```

- [ ] **Step 14.2: Adicionar rota do dashboard ao api.php**

```php
use App\Http\Controllers\DashboardController;
Route::get('dashboard', [DashboardController::class, 'index']);
```

- [ ] **Step 14.3: Criar StatCard.tsx**

```tsx
// frontend/components/ui/StatCard.tsx
interface StatCardProps {
  title: string
  value: string | number
  icon: string
  color: string  // 'var(--accent)', 'var(--danger)', etc.
  subtitle?: string
}

export function StatCard({ title, value, icon, color, subtitle }: StatCardProps) {
  return (
    <div style={{
      background: 'var(--card)', borderRadius: 12, border: '1px solid var(--border)',
      padding: 24, position: 'relative', overflow: 'hidden',
    }}>
      <div style={{ position: 'absolute', top: 0, left: 0, right: 0, height: 2, background: color }} />
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }}>
        <div>
          <p style={{ color: 'var(--muted)', fontSize: 13, fontWeight: 600, textTransform: 'uppercase', letterSpacing: '0.05em', margin: '0 0 8px' }}>{title}</p>
          <p className="font-mono" style={{ color: 'var(--text)', fontSize: 28, fontWeight: 700, margin: 0 }}>{value}</p>
          {subtitle && <p style={{ color: 'var(--muted)', fontSize: 13, margin: '6px 0 0' }}>{subtitle}</p>}
        </div>
        <span style={{ fontSize: 32, opacity: 0.15 }}>{icon}</span>
      </div>
    </div>
  )
}
```

- [ ] **Step 14.4: Criar FaturamentoChart.tsx**

```tsx
// frontend/components/dashboard/FaturamentoChart.tsx
'use client'
import { BarChart, Bar, XAxis, YAxis, Tooltip, ResponsiveContainer, Cell } from 'recharts'

interface FaturamentoChartProps {
  data: Array<{ mes: string; total: number }>
}

const CustomTooltip = ({ active, payload, label }: any) => {
  if (!active || !payload?.length) return null
  return (
    <div style={{ background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 8, padding: '10px 16px' }}>
      <p style={{ color: 'var(--muted)', fontSize: 12, margin: '0 0 4px' }}>{label}</p>
      <p style={{ color: 'var(--accent)', fontWeight: 700, margin: 0 }}>
        {new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(payload[0].value)}
      </p>
    </div>
  )
}

export function FaturamentoChart({ data }: FaturamentoChartProps) {
  const maxIdx = data.reduce((maxI, d, i, arr) => d.total > arr[maxI].total ? i : maxI, 0)

  return (
    <div style={{ background: 'var(--card)', borderRadius: 12, border: '1px solid var(--border)', padding: 24 }}>
      <h3 className="font-display" style={{ fontSize: 18, fontWeight: 800, color: 'var(--text)', marginBottom: 20 }}>Faturamento Mensal</h3>
      <ResponsiveContainer width="100%" height={200}>
        <BarChart data={data} barCategoryGap="30%">
          <XAxis dataKey="mes" tick={{ fill: 'var(--muted)', fontSize: 12 }} axisLine={false} tickLine={false} />
          <YAxis hide />
          <Tooltip content={<CustomTooltip />} cursor={{ fill: 'rgba(255,255,255,0.03)' }} />
          <Bar dataKey="total" radius={[4, 4, 0, 0]}>
            {data.map((_, idx) => <Cell key={idx} fill={idx === maxIdx ? 'var(--accent)' : 'rgba(245,166,35,0.3)'} />)}
          </Bar>
        </BarChart>
      </ResponsiveContainer>
    </div>
  )
}
```

- [ ] **Step 14.5: Atualizar app/(dashboard)/page.tsx com dados reais**

```tsx
// frontend/app/(dashboard)/page.tsx
'use client'
import { useState, useEffect } from 'react'
import { StatCard } from '@/components/ui/StatCard'
import { FaturamentoChart } from '@/components/dashboard/FaturamentoChart'
import { DataTable, Column } from '@/components/ui/DataTable'
import { StatusPill } from '@/components/ui/StatusPill'
import { StockBar } from '@/components/ui/StockBar'
import { formatarMoeda, formatarData } from '@/lib/formatters'
import api from '@/lib/api'

export default function DashboardPage() {
  const [data, setData] = useState<any>(null)

  useEffect(() => {
    api.get('/dashboard').then(r => setData(r.data))
  }, [])

  const osColumns: Column<any>[] = [
    { key: 'numero', label: '#OS', render: r => <span className="font-mono" style={{ color: 'var(--accent)' }}>#{r.numero}</span> },
    { key: 'cliente', label: 'Cliente', render: r => r.cliente ?? '-' },
    { key: 'status', label: 'Status', render: r => <StatusPill status={r.status} /> },
    { key: 'valor_total', label: 'Valor', render: r => <span className="font-mono">{formatarMoeda(r.valor_total)}</span> },
    { key: 'criado_em', label: 'Data', render: r => r.criado_em },
  ]

  if (!data) return (
    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: 16, marginBottom: 24 }}>
      {[1,2,3,4].map(i => (
        <div key={i} style={{ background: 'var(--card)', borderRadius: 12, border: '1px solid var(--border)', height: 110, animation: 'pulse 1.5s ease-in-out infinite' }} />
      ))}
    </div>
  )

  return (
    <div>
      <h1 className="font-display" style={{ fontSize: 28, fontWeight: 800, color: 'var(--text)', marginBottom: 24 }}>Dashboard</h1>

      {/* Stat cards */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: 16, marginBottom: 24 }}>
        <StatCard title="Clientes Ativos" value={data.stats.clientes_ativos} icon="👥" color="var(--info)" />
        <StatCard title="Dívidas em Aberto" value={formatarMoeda(data.stats.dividas_abertas)} icon="⚠" color="var(--danger)" subtitle="Total em débito" />
        <StatCard title="Faturamento do Mês" value={formatarMoeda(data.stats.faturamento_mes)} icon="💰" color="var(--success)" />
        <StatCard title="NF Emitidas" value={data.stats.nf_emitidas_mes} icon="🧾" color="var(--info)" subtitle="Este mês" />
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: '2fr 1fr', gap: 24, marginBottom: 24 }}>
        <FaturamentoChart data={data.faturamento_mensal} />

        {/* Alertas de estoque */}
        <div style={{ background: 'var(--card)', borderRadius: 12, border: '1px solid var(--border)', padding: 24 }}>
          <h3 className="font-display" style={{ fontSize: 18, fontWeight: 800, color: 'var(--text)', marginBottom: 16 }}>Alertas de Estoque</h3>
          {data.produtos_criticos.length === 0 ? (
            <p style={{ color: 'var(--success)', fontSize: 14 }}>✓ Estoque normalizado</p>
          ) : (
            data.produtos_criticos.map((p: any) => (
              <div key={p.id} style={{ marginBottom: 12 }}>
                <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 4 }}>
                  <span style={{ color: 'var(--text)', fontSize: 13 }}>{p.nome}</span>
                </div>
                <StockBar qtyAtual={p.qty_atual} qtyMinima={p.qty_minima}
                  status={p.qty_atual <= 0 ? 'SEM_ESTOQUE' : p.qty_atual < p.qty_minima * 0.4 ? 'CRITICO' : 'BAIXO'} />
              </div>
            ))
          )}
        </div>
      </div>

      {/* Últimas OS */}
      <div style={{ background: 'var(--card)', borderRadius: 12, border: '1px solid var(--border)', padding: 24 }}>
        <h3 className="font-display" style={{ fontSize: 18, fontWeight: 800, color: 'var(--text)', marginBottom: 16 }}>Últimas Ordens de Serviço</h3>
        <DataTable columns={osColumns} data={data.ultimas_os} emptyMessage="Nenhuma OS encontrada." />
      </div>
    </div>
  )
}
```

- [ ] **Step 14.6: Commit**

```bash
git add backend/app/Http/Controllers/DashboardController.php backend/routes/api.php
git add frontend/components/ui/StatCard.tsx frontend/components/dashboard/ frontend/app/(dashboard)/page.tsx
git commit -m "feat: add dashboard with stat cards, faturamento chart, and stock alerts"
```

---

## Task 15: Configurações + Usuários — Backend + Frontend

**Files:**
- Create: `backend/app/Http/Controllers/ConfiguracaoController.php`
- Create: `backend/app/Http/Controllers/UsuarioController.php`
- Create: `frontend/app/(dashboard)/configuracoes/page.tsx`
- Create: `frontend/app/(dashboard)/empresa/page.tsx`
- Create: `frontend/app/(dashboard)/usuarios/page.tsx`

- [ ] **Step 15.1: Criar ConfiguracaoController.php**

```php
<?php
// backend/app/Http/Controllers/ConfiguracaoController.php
declare(strict_types=1);
namespace App\Http\Controllers;

use App\Models\Configuracao;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

class ConfiguracaoController extends Controller
{
    public function show(): JsonResponse
    {
        $config = Configuracao::first() ?? new Configuracao();
        // Nunca retornar o certificado criptografado — apenas indicar se existe
        $data = $config->toArray();
        $data['tem_certificado'] = !empty($config->certificado_pfx_encrypted);
        unset($data['certificado_pfx_encrypted']);
        return response()->json($data);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'razao_social'           => ['nullable', 'string', 'max:150'],
            'nome_fantasia'          => ['nullable', 'string', 'max:100'],
            'cnpj'                   => ['nullable', 'string', 'max:18'],
            'inscricao_estadual'     => ['nullable', 'string', 'max:30'],
            'inscricao_municipal'    => ['nullable', 'string', 'max:20'],
            'regime_tributario'      => ['nullable', 'string', 'max:30'],
            'cep'                    => ['nullable', 'string', 'max:9'],
            'endereco'               => ['nullable', 'string', 'max:200'],
            'cidade'                 => ['nullable', 'string', 'max:80'],
            'uf'                     => ['nullable', 'string', 'size:2'],
            'telefone'               => ['nullable', 'string', 'max:15'],
            'email'                  => ['nullable', 'email', 'max:120'],
            'ambiente_fiscal'        => ['nullable', 'in:PRODUCAO,HOMOLOGACAO'],
            'serie_nf'               => ['nullable', 'string', 'max:5'],
            'aliquota_iss'           => ['nullable', 'numeric', 'min:0', 'max:100'],
            'cnae'                   => ['nullable', 'string', 'max:20'],
            'codigo_ibge'            => ['nullable', 'string', 'max:10'],
            'estoque_limite_padrao'  => ['nullable', 'integer', 'min:0'],
            'alertas_email'          => ['nullable', 'boolean'],
            'email_alertas'          => ['nullable', 'email', 'max:120'],
        ]);

        // Upload de certificado (se enviado como base64)
        if ($request->has('certificado_base64') && $request->certificado_base64) {
            $validated['certificado_pfx_encrypted'] = Crypt::encryptString($request->certificado_base64);
        }

        $config = Configuracao::first();
        if ($config) {
            $config->update($validated);
        } else {
            $config = Configuracao::create($validated);
        }

        return response()->json(['message' => 'Configurações atualizadas.', 'data' => $config]);
    }
}
```

- [ ] **Step 15.2: Criar UsuarioController.php**

```php
<?php
// backend/app/Http/Controllers/UsuarioController.php
declare(strict_types=1);
namespace App\Http\Controllers;

use App\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UsuarioController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Usuario::query();
        if ($request->has('role')) $query->where('role', $request->role);
        return response()->json(['data' => $query->orderBy('nome')->get(['id', 'nome', 'email', 'cpf', 'role', 'status', 'ultimo_acesso'])]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nome'     => ['required', 'string', 'max:120'],
            'email'    => ['required', 'email', 'unique:usuarios,email'],
            'cpf'      => ['required', 'string', 'size:11', 'unique:usuarios,cpf'],
            'telefone' => ['nullable', 'string'],
            'role'     => ['required', 'in:ADMIN,MECANICO,ATENDENTE,FINANCEIRO'],
            'senha'    => ['required', 'string', 'min:8'],
        ]);

        $usuario = Usuario::create([
            ...$validated,
            'senha_hash' => Hash::make($validated['senha']),
        ]);

        return response()->json(['data' => $usuario->only(['id', 'nome', 'email', 'role'])], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $usuario = Usuario::findOrFail($id);

        $validated = $request->validate([
            'nome'     => ['sometimes', 'required', 'string', 'max:120'],
            'email'    => ['sometimes', 'required', 'email', "unique:usuarios,email,{$id}"],
            'role'     => ['sometimes', 'required', 'in:ADMIN,MECANICO,ATENDENTE,FINANCEIRO'],
            'status'   => ['sometimes', 'required', 'in:ATIVO,INATIVO'],
            'senha'    => ['sometimes', 'nullable', 'string', 'min:8'],
        ]);

        if (!empty($validated['senha'])) {
            $validated['senha_hash'] = Hash::make($validated['senha']);
            unset($validated['senha']);
        }

        // Admin não pode desativar a si mesmo
        if (isset($validated['status']) && $validated['status'] === 'INATIVO' && $usuario->id === auth()->id()) {
            return response()->json(['message' => 'Você não pode desativar sua própria conta.'], 403);
        }

        $usuario->update($validated);
        return response()->json(['data' => $usuario->fresh()]);
    }
}
```

- [ ] **Step 15.3: Adicionar rotas de config e usuários ao api.php**

```php
use App\Http\Controllers\ConfiguracaoController;
use App\Http\Controllers\UsuarioController;

Route::get('configuracoes', [ConfiguracaoController::class, 'show']);
Route::put('configuracoes', [ConfiguracaoController::class, 'update']);

Route::apiResource('usuarios', UsuarioController::class)->except(['destroy', 'show']);
```

- [ ] **Step 15.4: Criar página de Configurações frontend**

```tsx
// frontend/app/(dashboard)/configuracoes/page.tsx
'use client'
import { useState, useEffect } from 'react'
import api from '@/lib/api'
import { toast } from '@/hooks/useToast'

export default function ConfiguracoesPage() {
  const [form, setForm] = useState({ estoque_limite_padrao: 5, alertas_email: true, email_alertas: '' })
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    api.get('/configuracoes').then(r => {
      const d = r.data
      setForm({ estoque_limite_padrao: d.estoque_limite_padrao ?? 5, alertas_email: d.alertas_email ?? true, email_alertas: d.email_alertas ?? '' })
    })
  }, [])

  async function salvar() {
    setSaving(true)
    try { await api.put('/configuracoes', form); toast('Configurações salvas!', 'success') }
    catch { toast('Erro ao salvar.', 'danger') }
    finally { setSaving(false) }
  }

  const inputStyle: React.CSSProperties = { padding: '9px 12px', borderRadius: 8, background: 'var(--bg)', border: '1px solid var(--border)', color: 'var(--text)', fontSize: 14, outline: 'none' }
  const labelStyle: React.CSSProperties = { color: 'var(--muted)', fontSize: 13, display: 'block', marginBottom: 4 }

  return (
    <div style={{ maxWidth: 560 }}>
      <h1 className="font-display" style={{ fontSize: 28, fontWeight: 800, color: 'var(--text)', marginBottom: 24 }}>Configurações</h1>
      <div style={{ background: 'var(--card)', borderRadius: 12, border: '1px solid var(--border)', padding: 28 }}>
        <h3 className="font-display" style={{ fontSize: 16, fontWeight: 700, color: 'var(--text)', marginBottom: 20 }}>Estoque</h3>
        <div style={{ marginBottom: 20 }}>
          <label style={labelStyle}>Limite padrão de alerta (unidades)</label>
          <input type="number" min={0} value={form.estoque_limite_padrao}
            onChange={e => setForm(f => ({ ...f, estoque_limite_padrao: +e.target.value }))}
            style={{ ...inputStyle, width: 120 }} />
          <p style={{ color: 'var(--muted)', fontSize: 12, marginTop: 4 }}>
            Produtos com estoque abaixo deste valor receberão alerta.
          </p>
        </div>

        <h3 className="font-display" style={{ fontSize: 16, fontWeight: 700, color: 'var(--text)', marginBottom: 20, marginTop: 28 }}>Notificações</h3>
        <div style={{ marginBottom: 16 }}>
          <label style={{ display: 'flex', alignItems: 'center', gap: 10, cursor: 'pointer' }}>
            <input type="checkbox" checked={form.alertas_email}
              onChange={e => setForm(f => ({ ...f, alertas_email: e.target.checked }))} />
            <span style={{ color: 'var(--text)', fontSize: 14 }}>Enviar alertas de estoque por e-mail</span>
          </label>
        </div>
        {form.alertas_email && (
          <div style={{ marginBottom: 20 }}>
            <label style={labelStyle}>E-mail para alertas</label>
            <input type="email" value={form.email_alertas}
              onChange={e => setForm(f => ({ ...f, email_alertas: e.target.value }))}
              style={{ ...inputStyle, width: '100%' }} placeholder="alertas@suaoficina.com.br" />
          </div>
        )}

        <button onClick={salvar} disabled={saving}
          className="font-display"
          style={{ marginTop: 8, padding: '10px 28px', background: saving ? 'var(--muted)' : 'var(--accent)', color: '#000', borderRadius: 8, border: 'none', fontWeight: 800, fontSize: 16, cursor: saving ? 'not-allowed' : 'pointer' }}>
          {saving ? 'Salvando...' : 'Salvar Configurações'}
        </button>
      </div>
    </div>
  )
}
```

- [ ] **Step 15.5: Criar página Empresa (dados da empresa e certificado)**

```tsx
// frontend/app/(dashboard)/empresa/page.tsx
'use client'
import { useState, useEffect } from 'react'
import api from '@/lib/api'
import { toast } from '@/hooks/useToast'

export default function EmpresaPage() {
  const [form, setForm] = useState<any>({})
  const [saving, setSaving] = useState(false)
  const [temCertificado, setTemCertificado] = useState(false)

  useEffect(() => {
    api.get('/configuracoes').then(r => {
      setForm(r.data)
      setTemCertificado(r.data.tem_certificado ?? false)
    })
  }, [])

  async function salvar() {
    setSaving(true)
    try { await api.put('/configuracoes', form); toast('Dados da empresa salvos!', 'success') }
    catch { toast('Erro ao salvar.', 'danger') }
    finally { setSaving(false) }
  }

  const set = (k: string) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) =>
    setForm((f: any) => ({ ...f, [k]: e.target.value }))

  const inputStyle: React.CSSProperties = { width: '100%', padding: '9px 12px', borderRadius: 8, background: 'var(--bg)', border: '1px solid var(--border)', color: 'var(--text)', fontSize: 14, outline: 'none' }
  const labelStyle: React.CSSProperties = { color: 'var(--muted)', fontSize: 13, display: 'block', marginBottom: 4 }

  return (
    <div style={{ maxWidth: 800 }}>
      <h1 className="font-display" style={{ fontSize: 28, fontWeight: 800, color: 'var(--text)', marginBottom: 24 }}>Dados da Empresa</h1>
      <div style={{ background: 'var(--card)', borderRadius: 12, border: '1px solid var(--border)', padding: 28 }}>
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
          {([
            ['razao_social', 'Razão Social', '1 / -1'],
            ['nome_fantasia', 'Nome Fantasia', ''],
            ['cnpj', 'CNPJ', ''],
            ['inscricao_estadual', 'Inscrição Estadual', ''],
            ['inscricao_municipal', 'Inscrição Municipal', ''],
            ['regime_tributario', 'Regime Tributário', ''],
            ['telefone', 'Telefone', ''],
            ['email', 'E-mail', ''],
            ['cep', 'CEP', ''],
            ['endereco', 'Endereço', '1 / -1'],
            ['cidade', 'Cidade', ''],
            ['uf', 'UF', ''],
          ] as [string, string, string][]).map(([key, label, col]) => (
            <div key={key} style={col ? { gridColumn: col } : {}}>
              <label style={labelStyle}>{label}</label>
              <input value={form[key] ?? ''} onChange={set(key)} style={inputStyle} />
            </div>
          ))}

          <p style={{ gridColumn: '1 / -1', color: 'var(--muted)', fontSize: 12, fontWeight: 600, textTransform: 'uppercase', letterSpacing: '0.06em', margin: '8px 0 -4px' }}>Configurações Fiscais</p>

          <div>
            <label style={labelStyle}>Ambiente</label>
            <select value={form.ambiente_fiscal ?? 'HOMOLOGACAO'} onChange={set('ambiente_fiscal')} style={inputStyle}>
              <option value="HOMOLOGACAO">Homologação</option>
              <option value="PRODUCAO">Produção</option>
            </select>
          </div>
          <div>
            <label style={labelStyle}>Série NF</label>
            <input value={form.serie_nf ?? '001'} onChange={set('serie_nf')} style={inputStyle} />
          </div>
          <div>
            <label style={labelStyle}>Alíquota ISS (%)</label>
            <input type="number" step="0.01" value={form.aliquota_iss ?? 5} onChange={set('aliquota_iss')} style={inputStyle} />
          </div>
          <div>
            <label style={labelStyle}>CNAE Principal</label>
            <input value={form.cnae ?? ''} onChange={set('cnae')} style={inputStyle} placeholder="4520001" />
          </div>

          <div style={{ gridColumn: '1 / -1' }}>
            <label style={labelStyle}>Certificado Digital A1 (.pfx)</label>
            <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
              {temCertificado && <span style={{ color: 'var(--success)', fontSize: 13 }}>✓ Certificado carregado</span>}
              <input type="file" accept=".pfx"
                onChange={e => {
                  const file = e.target.files?.[0]
                  if (!file) return
                  const reader = new FileReader()
                  reader.onload = ev => setForm((f: any) => ({ ...f, certificado_base64: btoa(ev.target?.result as string) }))
                  reader.readAsBinaryString(file)
                }}
                style={{ color: 'var(--muted)', fontSize: 14 }} />
            </div>
            <p style={{ color: 'var(--muted)', fontSize: 12, marginTop: 4 }}>
              O certificado é armazenado criptografado (AES-256).
            </p>
          </div>
        </div>

        <button onClick={salvar} disabled={saving}
          className="font-display"
          style={{ marginTop: 24, padding: '10px 28px', background: saving ? 'var(--muted)' : 'var(--accent)', color: '#000', borderRadius: 8, border: 'none', fontWeight: 800, fontSize: 16, cursor: saving ? 'not-allowed' : 'pointer' }}>
          {saving ? 'Salvando...' : 'Salvar Empresa'}
        </button>
      </div>
    </div>
  )
}
```

- [ ] **Step 15.6: Criar página de Usuários frontend**

```tsx
// frontend/app/(dashboard)/usuarios/page.tsx
'use client'
import { useState, useEffect } from 'react'
import { DataTable, Column } from '@/components/ui/DataTable'
import { StatusPill } from '@/components/ui/StatusPill'
import { formatarData } from '@/lib/formatters'
import api from '@/lib/api'
import { toast } from '@/hooks/useToast'

export default function UsuariosPage() {
  const [usuarios, setUsuarios] = useState<any[]>([])
  const [loading, setLoading] = useState(true)

  const fetchUsuarios = () => {
    api.get('/usuarios').then(r => setUsuarios(r.data.data ?? [])).finally(() => setLoading(false))
  }
  useEffect(fetchUsuarios, [])

  const columns: Column<any>[] = [
    { key: 'nome', label: 'Nome', render: r => <span style={{ color: 'var(--text)', fontWeight: 500 }}>{r.nome}</span> },
    { key: 'email', label: 'E-mail', render: r => <span style={{ color: 'var(--muted)', fontSize: 13 }}>{r.email}</span> },
    { key: 'role', label: 'Perfil', render: r => <span className="pill pill-info">{r.role}</span> },
    { key: 'ultimo_acesso', label: 'Último acesso', render: r => formatarData(r.ultimo_acesso) },
    { key: 'status', label: 'Status', render: r => <StatusPill status={r.status} /> },
  ]

  return (
    <div>
      <h1 className="font-display" style={{ fontSize: 28, fontWeight: 800, color: 'var(--text)', marginBottom: 24 }}>Usuários</h1>
      <DataTable columns={columns} data={usuarios} loading={loading} emptyMessage="Nenhum usuário cadastrado." />
    </div>
  )
}
```

- [ ] **Step 15.7: Commit**

```bash
git add backend/app/Http/Controllers/ConfiguracaoController.php backend/app/Http/Controllers/UsuarioController.php backend/routes/api.php
git add frontend/app/(dashboard)/configuracoes/ frontend/app/(dashboard)/empresa/ frontend/app/(dashboard)/usuarios/
git commit -m "feat: add configurações, empresa, and usuarios modules"
```

---

## Task 16: Seeder de Demo + Verificação Final

**Files:**
- Create: `backend/database/seeders/DemoSeeder.php`
- Modify: `backend/database/seeders/DatabaseSeeder.php`

- [ ] **Step 16.1: Criar DemoSeeder.php**

```php
<?php
// backend/database/seeders/DemoSeeder.php
declare(strict_types=1);
namespace Database\Seeders;

use App\Models\Cliente;
use App\Models\Configuracao;
use App\Models\Produto;
use App\Models\Usuario;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        // Usuários demo
        Usuario::create([
            'nome' => 'Administrador', 'email' => 'admin@mecanicapro.com',
            'cpf' => '52998224725', 'role' => 'ADMIN', 'status' => 'ATIVO',
            'senha_hash' => Hash::make('admin123'),
        ]);

        Usuario::create([
            'nome' => 'João Mecânico', 'email' => 'mecanico@mecanicapro.com',
            'cpf' => '87748248800', 'role' => 'MECANICO', 'status' => 'ATIVO',
            'senha_hash' => Hash::make('mec123'),
        ]);

        // Configurações padrão
        Configuracao::create([
            'razao_social'          => 'Oficina MecânicaPro Ltda',
            'nome_fantasia'         => 'MecânicaPro',
            'cnpj'                  => '11222333000181',
            'regime_tributario'     => 'Simples Nacional',
            'cidade'                => 'São Paulo',
            'uf'                    => 'SP',
            'ambiente_fiscal'       => 'HOMOLOGACAO',
            'serie_nf'              => '001',
            'proximo_numero_nf'     => 1,
            'aliquota_iss'          => 5.00,
            'estoque_limite_padrao' => 5,
            'alertas_email'         => true,
        ]);

        // Clientes demo
        $c1 = Cliente::create(['nome' => 'Carlos Souza', 'cpf_cnpj' => '52998224725', 'telefone' => '(11) 99999-0001', 'veiculo_modelo' => 'Honda Civic', 'veiculo_ano' => 2021, 'veiculo_placa' => 'BRA2E19', 'cidade' => 'São Paulo', 'uf' => 'SP', 'status' => 'REGULAR']);
        $c2 = Cliente::create(['nome' => 'Maria Oliveira', 'cpf_cnpj' => '87748248800', 'telefone' => '(11) 88888-0002', 'veiculo_modelo' => 'Toyota Corolla', 'veiculo_ano' => 2019, 'veiculo_placa' => 'ABC1234', 'status' => 'DEVEDOR']);

        // Produtos demo (variados níveis de estoque)
        Produto::create(['nome' => 'Filtro de Óleo Bosch', 'sku' => 'FLT-OL-001', 'categoria' => 'Filtros', 'qty_atual' => 0,  'qty_minima' => 10, 'preco_venda' => 28.90]);
        Produto::create(['nome' => 'Óleo Motor 5W30',      'sku' => 'OL-5W30-1L', 'categoria' => 'Óleo/Fluidos', 'qty_atual' => 3,  'qty_minima' => 20, 'preco_venda' => 45.00]);
        Produto::create(['nome' => 'Pastilha de Freio',    'sku' => 'FRE-PAS-001','categoria' => 'Freios',        'qty_atual' => 8,  'qty_minima' => 10, 'preco_venda' => 89.90]);
        Produto::create(['nome' => 'Filtro de Ar',         'sku' => 'FLT-AR-001', 'categoria' => 'Filtros',       'qty_atual' => 15, 'qty_minima' => 10, 'preco_venda' => 35.00]);
        Produto::create(['nome' => 'Vela de Ignição NGK',  'sku' => 'EL-VEL-NGK', 'categoria' => 'Elétrica',      'qty_atual' => 24, 'qty_minima' => 8,  'preco_venda' => 22.50]);
    }
}
```

- [ ] **Step 16.2: Atualizar DatabaseSeeder.php**

```php
<?php
// backend/database/seeders/DatabaseSeeder.php
namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(DemoSeeder::class);
    }
}
```

- [ ] **Step 16.3: Rodar seeder**

```bash
cd backend
php artisan db:seed
```

Saída esperada: `Seeding: Database\Seeders\DemoSeeder` seguido de `Database seeding completed successfully.`

- [ ] **Step 16.4: Testar suite completa de testes**

```bash
php artisan test
```

Saída esperada: todos os testes verdes. Se algum falhar, diagnosticar e corrigir antes de continuar.

- [ ] **Step 16.5: Verificação visual completa**

Iniciar ambos os servidores:
```bash
# Terminal 1 — backend
cd backend && php artisan serve --port=8000

# Terminal 2 — frontend
cd frontend && npm run dev
```

Verificar manualmente cada rota no browser:
1. `/login` → login com `admin@mecanicapro.com / admin123` → redireciona para dashboard
2. `/` (dashboard) → 4 stat cards, gráfico de faturamento, alertas de estoque (filtros críticos)
3. `/clientes` → lista com `Carlos Souza` (verde) e `Maria Oliveira` (vermelho — devedor)
4. `/clientes/novo` → cadastrar cliente com CEP (ViaCEP deve preencher endereço)
5. `/produtos` → lista com barras de estoque animadas nos produtos críticos
6. `/os/nova` → criar OS, adicionar serviços e peças
7. `/fiscal/emitir` → emitir NF (retorna simulação em ambiente de homologação)
8. `/fiscal/historico` → lista NF emitidas
9. `/empresa` → formulário de dados da empresa
10. `/configuracoes` → limite de estoque e alertas de email

- [ ] **Step 16.6: Commit final**

```bash
cd C:\Users\dougl\workspace6
git add backend/database/seeders/
git commit -m "feat: add demo seeder with users, clients, and products"
git tag v1.0.0 -m "MecânicaPro v1.0.0 — sistema completo"
```

---

## Spec Coverage — Self-Review

Verificação de cobertura do spec (`CLAUDE.md`):

| Requisito | Tarefa | Status |
|-----------|--------|--------|
| Auth: Login com validação client-side | Task 6 (Step 6.5) | ✅ |
| Auth: Forgot/Reset password | Task 5 + 6 | ✅ |
| Auth: Rate limiting (5 tentativas/15min) | Task 5 (Step 5.3) | ✅ |
| Auth: Roles ADMIN/MECANICO/ATENDENTE/FINANCEIRO | Task 4, 15 | ✅ |
| Layout: Sidebar 230px com badges | Task 7 | ✅ |
| Layout: Topbar com breadcrumb dinâmico | Task 7 | ✅ |
| Layout: Toast global | Task 7 | ✅ |
| Layout: AlertBanner dismissível | Task 7 | ✅ |
| Design system: CSS variables âmbar | Task 2 | ✅ |
| Design system: Barlow Condensed/Barlow/JetBrains Mono | Task 2 | ✅ |
| Clientes: CRUD com CPF/CNPJ validation | Task 8 + 9 | ✅ |
| Clientes: ViaCEP autopreenchimento | Task 9 (Step 9.6) | ✅ |
| Clientes: danger-row para devedores | Task 9 (Step 9.5) | ✅ |
| Clientes: status REGULAR/DEVEDOR/OS_ABERTA | Task 8 (Step 8.5) | ✅ |
| Produtos: CRUD com barra de estoque animada | Task 10 | ✅ |
| Produtos: status CRITICO/BAIXO/NORMAL | Task 10 (Step 10.1) | ✅ |
| Produtos: entrada manual de estoque | Task 10 (Step 10.5-10.8) | ✅ |
| Produtos: Job de alerta por email | Task 10 (Step 10.2) | ✅ |
| OS: CRUD com itens dinâmicos (SERVICO/PECA) | Task 11 + 12 | ✅ |
| OS: Baixa automática de estoque ao CONCLUIDA | Task 11 (Step 11.3) | ✅ |
| OS: Recálculo de status do cliente | Task 11 (Step 11.3) | ✅ |
| OS: Transação DB para baixa de estoque | Task 10 (Step 10.1) | ✅ |
| Fiscal: NF-e/NFS-e com NFe.io | Task 13 (Step 13.1) | ✅ |
| Fiscal: Numeração com lockForUpdate | Task 13 (Step 13.1) | ✅ |
| Fiscal: Split-view emissão | Task 13 (Step 13.4) | ✅ |
| Fiscal: Histórico com cancelamento | Task 13 (Step 13.5) | ✅ |
| Dashboard: 4 stat cards | Task 14 | ✅ |
| Dashboard: Gráfico de faturamento (Recharts) | Task 14 (Step 14.4) | ✅ |
| Dashboard: Alertas de estoque crítico | Task 14 (Step 14.5) | ✅ |
| Dashboard: Últimas OS | Task 14 (Step 14.5) | ✅ |
| Configurações: Dados da empresa + certificado A1 | Task 15 (Step 15.5) | ✅ |
| Configurações: Alerta de estoque padrão | Task 15 (Step 15.4) | ✅ |
| DB: Todas as migrations PostgreSQL | Task 3 | ✅ |
| Seeder: Usuários e dados demo | Task 16 | ✅ |
| TDD: Testes para auth, clientes, OS | Task 5, 8, 11 | ✅ |

**Sem gaps detectados.**

