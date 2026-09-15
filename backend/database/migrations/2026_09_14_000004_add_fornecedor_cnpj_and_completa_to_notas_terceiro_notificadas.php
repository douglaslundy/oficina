<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bug real achado ao vivo em produção (2026-09-14, mesmo dia do lançamento
 * da feature): o botão "Ver notas" (tela Produtos → Notas Recebidas) usava
 * `EntradaNfController::recebidas()`, que fazia uma consulta AO VIVO via
 * `listarNotasRecebidas()` — mas pro NFePHP esse método agora AVANÇA o
 * checkpoint de NSU (`Configuracao::dist_dfe_ultimo_nsu`). Como o comando
 * agendado `nfe:verificar-notas-recebidas` já tinha rodado e avançado esse
 * checkpoint, a consulta ao vivo da tela não achava mais nada NOVO — mesmo
 * havendo 3 notas já detectadas (e alertadas) esperando importação. Corrigido
 * fazendo a tela ler de `notas_terceiro_notificadas` (populada pelo mesmo
 * `VerificarNotasTerceiroService` usado pelo comando agendado) em vez de só
 * confiar no resultado de uma nova consulta ao vivo — por isso a tabela
 * precisa guardar `fornecedor_cnpj`/`completa` também, campos que a tela já
 * exibia mas que só vinham do resultado ao vivo antes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notas_terceiro_notificadas', function (Blueprint $table) {
            $table->string('fornecedor_cnpj', 18)->nullable()->after('fornecedor_nome');
            $table->boolean('completa')->default(false)->after('data_emissao');
        });
    }

    public function down(): void
    {
        Schema::table('notas_terceiro_notificadas', function (Blueprint $table) {
            $table->dropColumn(['fornecedor_cnpj', 'completa']);
        });
    }
};
