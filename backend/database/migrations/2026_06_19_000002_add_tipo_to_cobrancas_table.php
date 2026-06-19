<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('cobrancas', function (Blueprint $table) {
            // ASSINATURA (mensalidade do plano) | NOTA_EXCEDENTE (nota acima do limite)
            $table->string('tipo', 20)->default('ASSINATURA');
            $table->string('descricao', 200)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cobrancas', function (Blueprint $table) {
            $table->dropColumn(['tipo', 'descricao']);
        });
    }
};
