<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('saas_config', function (Blueprint $table) {
            $table->integer('alerta_cobranca_vezes_dia')->default(1);
            $table->integer('alerta_cobranca_dias_exibicao')->default(30);
        });
    }

    public function down(): void
    {
        Schema::table('saas_config', function (Blueprint $table) {
            $table->dropColumn(['alerta_cobranca_vezes_dia', 'alerta_cobranca_dias_exibicao']);
        });
    }
};
