<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('ordens_servico', function (Blueprint $table) {
            $table->boolean('venda_a_prazo')->default(false)->after('forma_pagamento');
            $table->unsignedSmallInteger('prazo_pagamento_dias')->nullable()->after('venda_a_prazo');
            $table->date('data_vencimento_pagamento')->nullable()->after('prazo_pagamento_dias');
        });
    }

    public function down(): void
    {
        Schema::table('ordens_servico', function (Blueprint $table) {
            $table->dropColumn(['venda_a_prazo', 'prazo_pagamento_dias', 'data_vencimento_pagamento']);
        });
    }
};
