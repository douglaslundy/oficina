<?php

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
use App\Http\Controllers\UsuarioController;
use App\Http\Controllers\VeiculoController;
use App\Http\Controllers\SaaS\AuthController as SaaSAuthController;
use App\Http\Controllers\SaaS\CobrancaController as SaaSCobrancaController;
use App\Http\Controllers\SaaS\DashboardController as SaaSDashboardController;
use App\Http\Controllers\SaaS\OficinaController as SaaSOficinaController;
use App\Http\Controllers\SaaS\PlanoController as SaaSPlanoController;
use App\Http\Controllers\SaaS\WebhookController as SaaSWebhookController;
use Illuminate\Support\Facades\Route;

// Asaas webhook — público, validado pelo token no header
Route::post('saas/webhooks/asaas', [SaaSWebhookController::class, 'asaas']);

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
        Route::get('planos',        [SaaSPlanoController::class, 'index']);
        Route::post('planos',       [SaaSPlanoController::class, 'store']);
        Route::put('planos/{id}',   [SaaSPlanoController::class, 'update']);
        Route::delete('planos/{id}', [SaaSPlanoController::class, 'destroy']);

        // Cobranças
        Route::get('cobrancas',               [SaaSCobrancaController::class, 'index']);
        Route::get('cobrancas/{oficina_id}',  [SaaSCobrancaController::class, 'byOficina']);

        // Dashboard SaaS
        Route::get('dashboard', [SaaSDashboardController::class, 'index']);
    });
});

// Auth — público (com tenant middleware para contexto opcional)
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

// Recursos protegidos
Route::middleware(['tenant', 'auth:sanctum'])->group(function () {
    Route::apiResource('clientes', ClienteController::class);
    Route::get('clientes/{clienteId}/veiculos',   [VeiculoController::class, 'index']);
    Route::post('clientes/{clienteId}/veiculos',  [VeiculoController::class, 'store']);
    Route::put('veiculos/{id}',                   [VeiculoController::class, 'update']);
    Route::delete('veiculos/{id}',                [VeiculoController::class, 'destroy']);

    Route::apiResource('produtos', ProdutoController::class);
    Route::post('produtos/{produto}/estoque/entrada',    [EstoqueController::class, 'entrada']);
    Route::get('produtos/{produto}/estoque/historico',   [EstoqueController::class, 'historico']);

    Route::apiResource('os', OrdemServicoController::class);
    Route::get('os/{id}/pdf', [OrdemServicoController::class, 'pdf']);

    Route::apiResource('notas-fiscais', NotaFiscalController::class)->except(['update', 'destroy']);
    Route::post('notas-fiscais/{id}/emitir',   [NotaFiscalController::class, 'emitir']);
    Route::post('notas-fiscais/{id}/cancelar', [NotaFiscalController::class, 'cancelar']);
    Route::get('notas-fiscais/{id}/pdf',       [NotaFiscalController::class, 'pdf']);

    Route::get('dashboard', [DashboardController::class, 'index']);

    Route::post('configuracoes/certificado', [ConfiguracaoController::class, 'uploadCertificado']);
    Route::get('configuracoes', [ConfiguracaoController::class, 'show']);
    Route::put('configuracoes', [ConfiguracaoController::class, 'update']);

    Route::get('usuarios',      [UsuarioController::class, 'index']);
    Route::post('usuarios',     [UsuarioController::class, 'store']);
    Route::put('usuarios/{id}', [UsuarioController::class, 'update']);

    Route::apiResource('agendamentos', AgendamentoController::class);
    Route::post('agendamentos/{id}/confirmar', [AgendamentoController::class, 'confirmar']);
    Route::post('agendamentos/{id}/cancelar',  [AgendamentoController::class, 'cancelar']);
});
