<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('os_itens', function (Blueprint $table) {
            // null = sem orçamento/sem resposta; true = aprovado; false = recusado.
            // Peças são informativas (sempre incluídas) — só serviços alternam aqui.
            $table->boolean('aprovado')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('os_itens', function (Blueprint $table) {
            $table->dropColumn('aprovado');
        });
    }
};
