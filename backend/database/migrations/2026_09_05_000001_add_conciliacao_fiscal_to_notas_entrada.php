<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notas_entrada', function (Blueprint $table) {
            $table->timestampTz('fiscal_conferida_em')->nullable();
            $table->timestampTz('fiscal_ultima_consulta_em')->nullable();
            $table->text('fiscal_erro_consulta')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('notas_entrada', function (Blueprint $table) {
            $table->dropColumn(['fiscal_conferida_em', 'fiscal_ultima_consulta_em', 'fiscal_erro_consulta']);
        });
    }
};
