<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Texto livre da OS que vai nos "dados adicionais" das notas fiscais geradas a
 * partir dela (NF-e infCpl / NFS-e xInfComp) — exigência de clientes
 * corporativos (Correios). Somado ao texto automático (Simples + placa/modelo/KM).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('ordens_servico', function (Blueprint $table) {
            $table->string('informacoes_complementares', 500)->nullable()->after('problema_relatado');
        });
    }

    public function down(): void
    {
        Schema::table('ordens_servico', function (Blueprint $table) {
            $table->dropColumn('informacoes_complementares');
        });
    }
};
