<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('saas_config', function (Blueprint $table) {
            $table->integer('cobranca_dias_antecedencia_padrao')->default(5);
            $table->integer('cobranca_dias_suspensao_padrao')->default(10);
            $table->decimal('desconto_anual_pct', 5, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('saas_config', function (Blueprint $table) {
            $table->dropColumn(['cobranca_dias_antecedencia_padrao', 'cobranca_dias_suspensao_padrao', 'desconto_anual_pct']);
        });
    }
};
