<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notas_entrada_itens', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('nota_entrada_id');
            $table->uuid('produto_id');
            $table->string('codigo_barras_xml', 20)->nullable();
            $table->string('descricao_xml', 200)->nullable();
            $table->decimal('quantidade', 10, 2)->default(0);
            $table->decimal('valor_unitario', 10, 2)->default(0);
            $table->boolean('produto_criado')->default(false);

            $table->foreign('nota_entrada_id')->references('id')->on('notas_entrada')->onDelete('cascade');
            $table->foreign('produto_id')->references('id')->on('produtos')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notas_entrada_itens');
    }
};
