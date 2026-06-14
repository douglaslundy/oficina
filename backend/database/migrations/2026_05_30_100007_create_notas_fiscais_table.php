<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('notas_fiscais', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->integer('numero')->nullable();
            $table->string('serie', 5)->default('001');
            $table->string('modelo', 10)->default('NFS-e');
            $table->uuid('cliente_id')->nullable();
            $table->uuid('os_id')->nullable();
            $table->string('natureza_operacao', 50)->nullable();
            $table->string('forma_pagamento', 30)->nullable();
            $table->decimal('subtotal', 10, 2)->nullable();
            $table->decimal('desconto', 10, 2)->default(0);
            $table->decimal('aliquota_iss', 5, 2)->default(5.00);
            $table->decimal('valor_iss', 10, 2)->nullable();
            $table->decimal('valor_total', 10, 2)->nullable();
            $table->string('status', 15)->default('RASCUNHO');
            $table->string('chave_acesso', 50)->nullable();
            $table->string('protocolo', 30)->nullable();
            $table->text('xml_retorno')->nullable();
            $table->string('pdf_url')->nullable();
            $table->text('observacoes')->nullable();
            $table->timestampTz('emitido_em')->nullable();
            $table->timestampTz('criado_em')->useCurrent();
        });
    }

    public function down(): void { Schema::dropIfExists('notas_fiscais'); }
};
