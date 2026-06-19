<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('planos', function (Blueprint $table) {
            $table->integer('limite_produtos')->default(-1);   // -1 = ilimitado
            $table->integer('limite_clientes')->default(-1);   // -1 = ilimitado
            $table->integer('limite_notas_mes')->default(-1);  // -1 = ilimitado
            // Valor cobrado por nota fiscal emitida acima do limite mensal.
            $table->decimal('preco_nota_excedente', 10, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('planos', function (Blueprint $table) {
            $table->dropColumn([
                'limite_produtos',
                'limite_clientes',
                'limite_notas_mes',
                'preco_nota_excedente',
            ]);
        });
    }
};
