<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('saas_config', function (Blueprint $table) {
            $table->integer('voto_confianca_dias')->default(3);
        });

        Schema::table('oficinas', function (Blueprint $table) {
            $table->date('voto_confianca_ate')->nullable();
        });

        Schema::table('cobrancas', function (Blueprint $table) {
            $table->timestampTz('voto_confianca_usado_em')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('saas_config', function (Blueprint $table) {
            $table->dropColumn('voto_confianca_dias');
        });

        Schema::table('oficinas', function (Blueprint $table) {
            $table->dropColumn('voto_confianca_ate');
        });

        Schema::table('cobrancas', function (Blueprint $table) {
            $table->dropColumn('voto_confianca_usado_em');
        });
    }
};
