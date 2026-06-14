<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ordens_servico', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->integer('numero')->unique();
            $table->foreignUuid('cliente_id')->references('id')->on('clientes');
            $table->uuid('mecanico_id')->nullable();
            $table->string('veiculo_descricao', 100)->nullable();
            $table->string('veiculo_placa', 10)->nullable();
            $table->text('problema_relatado')->nullable();
            $table->string('status', 25)->default('ABERTA');
            $table->string('forma_pagamento', 30)->nullable();
            $table->date('prazo_entrega')->nullable();
            $table->decimal('valor_total', 10, 2)->default(0);
            $table->decimal('valor_pago', 10, 2)->default(0);
            $table->timestampTz('criado_em')->useCurrent();
            $table->timestampTz('atualizado_em')->useCurrent();
        });
    }

    public function down(): void { Schema::dropIfExists('ordens_servico'); }
};
