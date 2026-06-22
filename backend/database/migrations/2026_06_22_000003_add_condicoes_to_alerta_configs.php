<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alerta_configs', function (Blueprint $table) {
            // Filtro de disparo do alerta no formato campo => [valores aceitos].
            // Ex.: {"status_alvo": ["CANCELADA"]}. Null = dispara sempre.
            $table->jsonb('condicoes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('alerta_configs', function (Blueprint $table) {
            $table->dropColumn('condicoes');
        });
    }
};
