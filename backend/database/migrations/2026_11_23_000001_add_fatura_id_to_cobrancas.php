<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Excedentes (nota fiscal acima do plano etc.) deixam de ser cobrança própria:
 * ficam "a faturar" e são somados à próxima fatura de assinatura. fatura_id
 * aponta para a cobrança ASSINATURA que os incluiu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cobrancas', function (Blueprint $table) {
            $table->uuid('fatura_id')->nullable()->index();
            $table->foreign('fatura_id')->references('id')->on('cobrancas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cobrancas', function (Blueprint $table) {
            $table->dropForeign(['fatura_id']);
            $table->dropColumn('fatura_id');
        });
    }
};
