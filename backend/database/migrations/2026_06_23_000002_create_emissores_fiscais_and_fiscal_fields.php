<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('configuracoes', function (Blueprint $table) {
            $table->text('certificado_senha_encrypted')->nullable();
            $table->date('certificado_validade')->nullable();
            $table->string('certificado_nome', 150)->nullable();
            $table->string('certificado_status', 20)->nullable(); // OK | INVALIDO | EXPIRADO
        });

        Schema::create('emissores_fiscais', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('oficina_id');
            $table->string('provedor', 10);   // SPEDY | FOCUS
            $table->string('ambiente', 12);    // HOMOLOGACAO | PRODUCAO
            $table->string('emissor_externo_id', 100)->nullable();
            $table->text('token_encrypted')->nullable();
            $table->string('status', 20)->default('PENDENTE'); // PENDENTE | REGISTRADO | ERRO
            $table->timestampTz('registrado_em')->nullable();
            $table->text('ultimo_erro')->nullable();
            $table->timestampTz('criado_em')->useCurrent();
            $table->unique(['oficina_id', 'provedor', 'ambiente']);
            $table->index('oficina_id');
        });

        Schema::table('notas_fiscais', function (Blueprint $table) {
            $table->string('provedor', 10)->nullable();   // SPEDY | FOCUS
            $table->string('ambiente', 12)->nullable();    // HOMOLOGACAO | PRODUCAO
            $table->string('referencia_externa', 60)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('notas_fiscais', function (Blueprint $table) {
            $table->dropColumn(['provedor', 'ambiente', 'referencia_externa']);
        });
        Schema::dropIfExists('emissores_fiscais');
        Schema::table('configuracoes', function (Blueprint $table) {
            $table->dropColumn([
                'certificado_senha_encrypted', 'certificado_validade',
                'certificado_nome', 'certificado_status',
            ]);
        });
    }
};
