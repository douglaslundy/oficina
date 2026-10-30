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
            // PDF (abre o PDF antes de imprimir) | DIALOGO (janela de impressão do navegador)
            // | DIRETO (confirma no sistema e manda direto pra impressora).
            $table->string('modo_impressao', 10)->default('DIALOGO');
        });
    }

    public function down(): void
    {
        Schema::table('configuracoes', fn (Blueprint $t) => $t->dropColumn('modo_impressao'));
    }
};
