<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('produtos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('nome', 150);
            $table->string('sku', 30)->unique();
            $table->string('categoria', 40);
            $table->string('unidade', 10)->default('Un');
            $table->integer('qty_atual')->default(0);
            $table->integer('qty_minima')->default(5);
            $table->decimal('preco_custo', 10, 2)->nullable();
            $table->decimal('preco_venda', 10, 2)->nullable();
            $table->boolean('ativo')->default(true);
            $table->timestampTz('criado_em')->useCurrent();
        });
    }

    public function down(): void { Schema::dropIfExists('produtos'); }
};
