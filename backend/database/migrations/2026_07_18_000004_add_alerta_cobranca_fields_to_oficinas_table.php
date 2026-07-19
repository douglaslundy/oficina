<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('oficinas', function (Blueprint $table) {
            $table->integer('alerta_cobranca_exibicoes_hoje')->default(0);
            $table->date('alerta_cobranca_ultima_exibicao_em')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('oficinas', function (Blueprint $table) {
            $table->dropColumn(['alerta_cobranca_exibicoes_hoje', 'alerta_cobranca_ultima_exibicao_em']);
        });
    }
};
