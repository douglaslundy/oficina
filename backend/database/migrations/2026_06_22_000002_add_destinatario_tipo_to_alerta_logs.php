<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alerta_logs', function (Blueprint $table) {
            // CLIENTE | MECANICO | CADASTRADO (número fixo do alerta) | null (envio direto)
            $table->string('destinatario_tipo', 12)->nullable()->after('destinatario');
        });
    }

    public function down(): void
    {
        Schema::table('alerta_logs', function (Blueprint $table) {
            $table->dropColumn('destinatario_tipo');
        });
    }
};
