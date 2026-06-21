<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('planos', function (Blueprint $table) {
            // Libera o envio de orçamentos para aprovação do cliente. Default false:
            // o admin do SaaS habilita por plano (igual aos canais de alerta).
            $table->boolean('orcamento')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('planos', function (Blueprint $table) {
            $table->dropColumn('orcamento');
        });
    }
};
