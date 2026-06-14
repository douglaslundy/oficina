<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('agendamentos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('cliente_id')->references('id')->on('clientes');
            $table->uuid('mecanico_id')->nullable();
            $table->string('tipo_servico', 100);
            $table->text('observacoes')->nullable();
            $table->timestampTz('data_hora_inicio');
            $table->timestampTz('data_hora_fim');
            $table->string('status', 20)->default('AGENDADO'); // AGENDADO | CONFIRMADO | CANCELADO | CONCLUIDO
            $table->uuid('os_id')->nullable(); // OS gerada ao confirmar
            $table->timestampTz('criado_em')->useCurrent();
        });
    }

    public function down(): void { Schema::dropIfExists('agendamentos'); }
};
