<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('os_itens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('os_id')->references('id')->on('ordens_servico')->onDelete('cascade');
            $table->string('tipo', 10);
            $table->uuid('produto_id')->nullable();
            $table->string('descricao', 200);
            $table->decimal('quantidade', 8, 2)->default(1);
            $table->decimal('valor_unitario', 10, 2);
            $table->decimal('valor_total', 10, 2)->storedAs('quantidade * valor_unitario');
        });
    }

    public function down(): void { Schema::dropIfExists('os_itens'); }
};
