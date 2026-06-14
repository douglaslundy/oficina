<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('configuracoes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('razao_social', 150)->nullable();
            $table->string('nome_fantasia', 100)->nullable();
            $table->string('cnpj', 18)->nullable();
            $table->string('inscricao_estadual', 30)->nullable();
            $table->string('inscricao_municipal', 20)->nullable();
            $table->string('regime_tributario', 30)->nullable();
            $table->string('cep', 9)->nullable();
            $table->string('endereco', 200)->nullable();
            $table->string('cidade', 80)->nullable();
            $table->char('uf', 2)->nullable();
            $table->string('telefone', 15)->nullable();
            $table->string('email', 120)->nullable();
            $table->string('ambiente_fiscal', 15)->default('HOMOLOGACAO');
            $table->string('serie_nf', 5)->default('001');
            $table->integer('proximo_numero_nf')->default(1);
            $table->decimal('aliquota_iss', 5, 2)->default(5.00);
            $table->string('cnae', 20)->nullable();
            $table->string('codigo_ibge', 10)->nullable();
            $table->integer('estoque_limite_padrao')->default(5);
            $table->boolean('alertas_email')->default(true);
            $table->string('email_alertas', 120)->nullable();
            $table->text('certificado_pfx_encrypted')->nullable();
            $table->timestampTz('atualizado_em')->useCurrent();
        });
    }

    public function down(): void { Schema::dropIfExists('configuracoes'); }
};
