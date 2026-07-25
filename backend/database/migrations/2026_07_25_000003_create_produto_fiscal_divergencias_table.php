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
        Schema::create('produto_fiscal_divergencias', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('oficina_id');
            $table->uuid('produto_id');
            $table->uuid('nota_entrada_id')->nullable();
            $table->string('campo', 20);           // ncm | cest | origem | tributacao_icms
            $table->string('valor_atual', 20)->nullable();
            $table->string('valor_xml', 20)->nullable();
            $table->timestampTz('criado_em')->useCurrent();
            $table->timestampTz('resolvido_em')->nullable();
            $table->string('resolucao', 12)->nullable(); // MANTEVE | ACEITOU_XML

            $table->foreign('oficina_id')->references('id')->on('oficinas')->onDelete('cascade');
            $table->foreign('produto_id')->references('id')->on('produtos')->onDelete('cascade');
            $table->foreign('nota_entrada_id')->references('id')->on('notas_entrada')->onDelete('set null');

            $table->index(['oficina_id', 'resolvido_em']);
            $table->index('produto_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('produto_fiscal_divergencias');
    }
};
