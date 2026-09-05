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
            // MANUAL (padrão): o sistema calcula CFOP/CST/ICMS/ISS de cada item.
            // AUTOMATICO_PROVEDOR: a Spedy calcula via POST /v1/orders (nenhum
            // campo fiscal no payload). Só Spedy na v1.
            $table->string('calculo_tributario_modo', 20)->default('MANUAL');
        });
    }

    public function down(): void
    {
        Schema::table('configuracoes', function (Blueprint $table) {
            $table->dropColumn('calculo_tributario_modo');
        });
    }
};
