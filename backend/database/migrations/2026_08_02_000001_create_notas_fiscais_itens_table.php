<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('notas_fiscais_itens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('nota_fiscal_id')->references('id')->on('notas_fiscais')->onDelete('cascade');
            $table->uuid('produto_id')->nullable();
            $table->uuid('oficina_id')->nullable();
            $table->string('descricao', 200);
            $table->string('ncm', 8)->nullable();
            $table->string('cfop', 4)->nullable();
            $table->smallInteger('origem')->nullable();
            $table->string('tributacao_icms', 10)->nullable();
            $table->string('cst_csosn', 4)->nullable();
            $table->decimal('quantidade', 8, 2)->default(1);
            $table->decimal('valor_unitario', 10, 2);
            $table->decimal('valor_total', 10, 2)->storedAs('quantidade * valor_unitario');
        });
    }

    public function down(): void { Schema::dropIfExists('notas_fiscais_itens'); }
};
