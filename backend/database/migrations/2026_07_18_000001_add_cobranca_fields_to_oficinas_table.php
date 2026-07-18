<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('oficinas', function (Blueprint $table) {
            $table->string('ciclo_cobranca', 10)->default('MENSAL')->after('gateway');
            $table->date('proximo_vencimento')->nullable()->after('ciclo_cobranca');
            $table->integer('dias_antecedencia_cobranca')->nullable()->after('proximo_vencimento');
            $table->integer('dias_suspensao_vencido')->nullable()->after('dias_antecedencia_cobranca');
        });
    }

    public function down(): void
    {
        Schema::table('oficinas', function (Blueprint $table) {
            $table->dropColumn(['ciclo_cobranca', 'proximo_vencimento', 'dias_antecedencia_cobranca', 'dias_suspensao_vencido']);
        });
    }
};
