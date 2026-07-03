<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movimentacoes_estoque', function (Blueprint $table) {
            $table->uuid('nota_entrada_id')->nullable()->after('os_id');
            $table->foreign('nota_entrada_id')->references('id')->on('notas_entrada')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('movimentacoes_estoque', function (Blueprint $table) {
            $table->dropForeign(['nota_entrada_id']);
            $table->dropColumn('nota_entrada_id');
        });
    }
};
