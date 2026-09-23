<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guarda de idempotência pra registrarNotaSeExcedente(): sem isso, duas
 * chamadas de AplicarResultadoNotaService::aplicar() pra mesma nota (ex:
 * o job original de emissão e o cron nfe:reconciliar-processando
 * observando a mesma nota recém-AUTORIZADA quase ao mesmo tempo) geram
 * duas Cobranca para o mesmo documento fiscal — cobrança duplicada real,
 * não só e-mail duplicado.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('cobrancas', function (Blueprint $table) {
            $table->uuid('nota_fiscal_id')->nullable()->after('oficina_id');
            $table->index('nota_fiscal_id');
        });
    }

    public function down(): void
    {
        Schema::table('cobrancas', function (Blueprint $table) {
            $table->dropColumn('nota_fiscal_id');
        });
    }
};
