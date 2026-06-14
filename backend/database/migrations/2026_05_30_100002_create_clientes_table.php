<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('clientes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('nome', 150);
            $table->string('cpf_cnpj', 18)->unique();
            $table->string('telefone', 15)->nullable();
            $table->string('email', 120)->nullable();
            $table->string('cep', 9)->nullable();
            $table->string('endereco', 200)->nullable();
            $table->string('bairro', 80)->nullable();
            $table->string('cidade', 80)->nullable();
            $table->char('uf', 2)->nullable();
            $table->string('veiculo_modelo', 80)->nullable();
            $table->smallInteger('veiculo_ano')->nullable();
            $table->string('veiculo_placa', 10)->nullable();
            $table->string('status', 20)->default('REGULAR');
            $table->timestampTz('criado_em')->useCurrent();
        });
    }

    public function down(): void { Schema::dropIfExists('clientes'); }
};
