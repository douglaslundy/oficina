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
            // Canais de alerta liberados para o plano. Default false: o admin do
            // SaaS habilita por plano conforme a contratação.
            $table->boolean('alerta_whatsapp')->default(false);
            $table->boolean('alerta_email')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('planos', function (Blueprint $table) {
            $table->dropColumn(['alerta_whatsapp', 'alerta_email']);
        });
    }
};
