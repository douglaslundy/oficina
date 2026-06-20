<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\AgendamentoController;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\ConfiguracaoController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EstoqueController;
use App\Http\Controllers\NotaFiscalController;
use App\Http\Controllers\OrdemServicoController;
use App\Http\Controllers\ProdutoController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\RelatorioController;
use App\Http\Controllers\UsuarioController;
use App\Http\Controllers\ServicoController;
use App\Http\Controllers\VeiculoController;
use App\Http\Controllers\SaaS\AuthController as SaaSAuthController;
use App\Http\Controllers\SaaS\CobrancaController as SaaSCobrancaController;
use App\Http\Controllers\SaaS\DashboardController as SaaSDashboardController;
use App\Http\Controllers\SaaS\OficinaController as SaaSOficinaController;
use App\Http\Controllers\SaaS\PlanoController as SaaSPlanoController;
use App\Http\Controllers\SaaS\WebhookController as SaaSWebhookController;
use App\Http\Controllers\SaaS\SaasConfigController;

// Health check — para docker-compose healthcheck e monitoramento
Route::get('/health', fn() => response()->json(['status' => 'ok']));

// Webhooks SaaS — públicos, validados internamente
Route::post('saas/webhooks/asaas',        [SaaSWebhookController::class, 'asaas']);
Route::post('saas/webhooks/mercadopago',  [SaaSWebhookController::class, 'mercadopago']);

// SaaS Admin — Auth (público, sem middleware de tenant)
Route::prefix('saas')->group(function () {
    Route::post('/auth/login',  [SaaSAuthController::class, 'login']);
    Route::post('/auth/logout', [SaaSAuthController::class, 'logout'])->middleware('auth:saas');
    Route::get('/auth/me',      [SaaSAuthController::class, 'me'])->middleware('auth:saas');

    // Protected SaaS routes
    Route::middleware('auth:saas')->group(function () {
        // Oficinas (tenant management)
        Route::get('oficinas',                   [SaaSOficinaController::class, 'index']);
        Route::post('oficinas',                  [SaaSOficinaController::class, 'store']);
        Route::get('oficinas/{id}',              [SaaSOficinaController::class, 'show']);
        Route::put('oficinas/{id}',              [SaaSOficinaController::class, 'update']);
        Route::post('oficinas/{id}/suspender',   [SaaSOficinaController::class, 'suspender']);
        Route::post('oficinas/{id}/reativar',    [SaaSOficinaController::class, 'reativar']);

        // Planos
        Route::get('planos',         [SaaSPlanoController::class, 'index']);
        Route::post('planos',        [SaaSPlanoController::class, 'store']);
        Route::put('planos/{id}',    [SaaSPlanoController::class, 'update']);
        Route::delete('planos/{id}', [SaaSPlanoController::class, 'destroy']);

        // Cobranças
        Route::get('cobrancas',                [SaaSCobrancaController::class, 'index']);
        Route::get('cobrancas/por/{oficina_id}', [SaaSCobrancaController::class, 'byOficina']);
        Route::delete('cobrancas/{id}',        [SaaSCobrancaController::class, 'cancelar']);

        // Oficinas — Asaas
        Route::get('oficinas/{id}/asaas',                      [SaaSOficinaController::class, 'asaasStatus']);
        Route::post('oficinas/{id}/sincronizar-cobrancas',     [SaaSOficinaController::class, 'sincronizarCobrancas']);
        Route::post('oficinas/{id}/gerar-cobranca',            [SaaSOficinaController::class, 'gerarCobrancaAvulsa']);
        Route::post('oficinas/{id}/cancelar-assinatura',       [SaaSOficinaController::class, 'cancelarAssinatura']);

        // Dashboard SaaS
        Route::get('dashboard', [SaaSDashboardController::class, 'index']);

        // Configurações gateway (SaaS Admin)
        Route::get('config',                     [SaasConfigController::class, 'show']);
        Route::put('config/gateway',             [SaasConfigController::class, 'updateGateway']);
        Route::put('config/asaas',               [SaasConfigController::class, 'updateAsaas']);
        Route::put('config/mercadopago',         [SaasConfigController::class, 'updateMercadoPago']);
    });
});

// Auth — público
Route::middleware('tenant')->prefix('auth')->group(function () {
    Route::post('/login',           [LoginController::class, 'login']);
    Route::post('/forgot-password', [ForgotPasswordController::class, 'sendResetLink']);
    Route::post('/reset-password',  [ResetPasswordController::class, 'reset']);
});

// Auth — protegido
Route::middleware(['tenant', 'auth:sanctum'])->prefix('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'logout']);
    Route::get('/me',      [LoginController::class, 'me']);
});

// ─── Dashboard — todos os roles ───────────────────────────────────────────────
Route::middleware(['tenant', 'auth:sanctum'])->group(function () {
    Route::get('dashboard',      [DashboardController::class, 'index']);
    Route::get('plano/limites',  [PlanController::class, 'limites']);
});

// ─── Clientes — leitura: todos; escrita: ADMIN, ATENDENTE ────────────────────
Route::middleware(['tenant', 'auth:sanctum'])->group(function () {
    Route::get('clientes',           [ClienteController::class, 'index']);
    Route::get('clientes/{cliente}', [ClienteController::class, 'show']);
    Route::get('clientes/{clienteId}/veiculos', [VeiculoController::class, 'index']);
});
Route::middleware(['tenant', 'auth:sanctum', 'role:ADMIN,ATENDENTE'])->group(function () {
    Route::post('clientes',             [ClienteController::class, 'store']);
    Route::put('clientes/{cliente}',    [ClienteController::class, 'update']);
    Route::delete('clientes/{cliente}', [ClienteController::class, 'destroy']);
    Route::post('clientes/{clienteId}/veiculos', [VeiculoController::class, 'store']);
    Route::put('veiculos/{id}',         [VeiculoController::class, 'update']);
    Route::delete('veiculos/{id}',      [VeiculoController::class, 'destroy']);
});

// ─── Produtos — leitura: todos; escrita: ADMIN, ATENDENTE ───────────────────
Route::middleware(['tenant', 'auth:sanctum'])->group(function () {
    Route::get('produtos',            [ProdutoController::class, 'index']);
    Route::get('produtos/{produto}',  [ProdutoController::class, 'show']);
    Route::get('produtos/{produto}/estoque/historico', [EstoqueController::class, 'historico']);
});
Route::middleware(['tenant', 'auth:sanctum', 'role:ADMIN,ATENDENTE'])->group(function () {
    Route::post('produtos',              [ProdutoController::class, 'store']);
    Route::put('produtos/{produto}',     [ProdutoController::class, 'update']);
    Route::delete('produtos/{produto}',  [ProdutoController::class, 'destroy']);
    Route::post('produtos/{produto}/estoque/entrada', [EstoqueController::class, 'entrada']);
});

// ─── Serviços — leitura: todos; escrita: ADMIN, ATENDENTE; desativar: ADMIN ───
Route::middleware(['tenant', 'auth:sanctum'])->group(function () {
    Route::get('servicos', [ServicoController::class, 'index']);
});
Route::middleware(['tenant', 'auth:sanctum', 'role:ADMIN,ATENDENTE'])->group(function () {
    Route::post('servicos',     [ServicoController::class, 'store']);
    Route::put('servicos/{id}', [ServicoController::class, 'update']);
});
Route::middleware(['tenant', 'auth:sanctum', 'role:ADMIN'])->group(function () {
    Route::delete('servicos/{id}', [ServicoController::class, 'destroy']);
});

// ─── OS — leitura: todos; criação/edição: ADMIN, ATENDENTE, MECANICO ─────────
Route::middleware(['tenant', 'auth:sanctum'])->group(function () {
    Route::get('os',             [OrdemServicoController::class, 'index']);
    Route::get('os/{id}',        [OrdemServicoController::class, 'show']);
    Route::get('os/{id}/pdf',    [OrdemServicoController::class, 'pdf']);
    Route::get('os/{id}/recibo', [OrdemServicoController::class, 'recibo']);
});
Route::middleware(['tenant', 'auth:sanctum', 'role:ADMIN,ATENDENTE,MECANICO'])->group(function () {
    Route::post('os',         [OrdemServicoController::class, 'store']);
    Route::put('os/{id}',     [OrdemServicoController::class, 'update']);
    Route::post('os/{osId}/itens',                          [OrdemServicoController::class, 'addItem']);
    Route::put('os/{osId}/itens/{itemId}',                  [OrdemServicoController::class, 'updateItem']);
    Route::delete('os/{osId}/itens/{itemId}',               [OrdemServicoController::class, 'removeItem']);
    Route::post('os/{id}/pagamentos',                        [OrdemServicoController::class, 'addPagamento']);
    Route::delete('os/{id}/pagamentos/{pagamentoId}',        [OrdemServicoController::class, 'removePagamento']);
});
Route::middleware(['tenant', 'auth:sanctum', 'role:ADMIN'])->group(function () {
    Route::delete('os/{id}', [OrdemServicoController::class, 'destroy']);
});

// ─── Notas Fiscais — ADMIN e FINANCEIRO ─────────────────────────────────────
Route::middleware(['tenant', 'auth:sanctum', 'role:ADMIN,FINANCEIRO'])->group(function () {
    Route::get('notas-fiscais',                [NotaFiscalController::class, 'index']);
    Route::get('notas-fiscais/{id}',           [NotaFiscalController::class, 'show']);
    Route::get('notas-fiscais/{id}/pdf',       [NotaFiscalController::class, 'pdf']);
    Route::post('notas-fiscais',               [NotaFiscalController::class, 'store']);
    Route::post('notas-fiscais/{id}/emitir',   [NotaFiscalController::class, 'emitir']);
    Route::post('notas-fiscais/{id}/cancelar', [NotaFiscalController::class, 'cancelar']);
});

// ─── Relatórios — ADMIN e FINANCEIRO ─────────────────────────────────────────
Route::middleware(['tenant', 'auth:sanctum', 'role:ADMIN,FINANCEIRO'])->group(function () {
    Route::get('relatorios/os',       [RelatorioController::class, 'os']);
    Route::get('relatorios/clientes', [RelatorioController::class, 'clientes']);
    Route::get('relatorios/estoque',  [RelatorioController::class, 'estoque']);
});

// ─── Usuários — somente ADMIN ─────────────────────────────────────────────────
Route::middleware(['tenant', 'auth:sanctum', 'role:ADMIN'])->group(function () {
    Route::get('usuarios',          [UsuarioController::class, 'index']);
    Route::get('usuarios/{id}',     [UsuarioController::class, 'show']);
    Route::post('usuarios',         [UsuarioController::class, 'store']);
    Route::put('usuarios/{id}',     [UsuarioController::class, 'update']);
});

// ─── Configurações — somente ADMIN ───────────────────────────────────────────
Route::middleware(['tenant', 'auth:sanctum', 'role:ADMIN'])->group(function () {
    Route::post('configuracoes/certificado', [ConfiguracaoController::class, 'uploadCertificado']);
    Route::get('configuracoes',              [ConfiguracaoController::class, 'show']);
    Route::put('configuracoes',              [ConfiguracaoController::class, 'update']);
});

// ─── Agendamentos — todos os roles ───────────────────────────────────────────
Route::middleware(['tenant', 'auth:sanctum'])->group(function () {
    Route::apiResource('agendamentos', AgendamentoController::class);
    Route::post('agendamentos/{id}/confirmar', [AgendamentoController::class, 'confirmar']);
    Route::post('agendamentos/{id}/cancelar',  [AgendamentoController::class, 'cancelar']);
});

// ─── Auditoria — somente ADMIN ────────────────────────────────────────────────
Route::middleware(['tenant', 'auth:sanctum', 'role:ADMIN'])->group(function () {
    Route::get('auditoria',      [AuditController::class, 'index']);
    Route::get('auditoria/{id}', [AuditController::class, 'show']);
});
