<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alerta_configs', function (Blueprint $table) {
            // Canais escolhidos para o alerta: ["WHATSAPP"], ["EMAIL"] ou ambos.
            $table->jsonb('canais')->default('["WHATSAPP"]');
            // E-mails extras (um por linha no front), além de cliente/mecânico cadastrados.
            $table->jsonb('emails')->default('[]');
        });
    }

    public function down(): void
    {
        Schema::table('alerta_configs', function (Blueprint $table) {
            $table->dropColumn(['canais', 'emails']);
        });
    }
};
