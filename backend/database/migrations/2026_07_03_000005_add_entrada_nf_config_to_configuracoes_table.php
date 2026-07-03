<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configuracoes', function (Blueprint $table) {
            $table->decimal('markup_padrao_entrada_nf', 5, 2)->default(40.00);
            $table->boolean('atualizar_custo_entrada_nf')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('configuracoes', function (Blueprint $table) {
            $table->dropColumn(['markup_padrao_entrada_nf', 'atualizar_custo_entrada_nf']);
        });
    }
};
