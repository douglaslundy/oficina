<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('movimentacoes_estoque', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('produto_id')->references('id')->on('produtos');
            $table->string('tipo', 10);
            $table->integer('quantidade');
            $table->string('motivo', 100)->nullable();
            $table->uuid('os_id')->nullable();
            $table->uuid('usuario_id')->nullable();
            $table->timestampTz('criado_em')->useCurrent();
        });
    }

    public function down(): void { Schema::dropIfExists('movimentacoes_estoque'); }
};
