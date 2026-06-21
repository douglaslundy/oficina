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
        Schema::create('oficina_servicos', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('oficina_id');
            $table->string('servico', 30); // ALERTA_WHATSAPP | ALERTA_EMAIL | ORCAMENTO
            $table->uuid('pacote_id')->nullable(); // origem (se veio de um pacote)
            $table->integer('quantidade')->default(-1);    // -1 = ilimitado (por mês)
            $table->decimal('valor_adicional', 10, 2)->default(0);
            $table->boolean('recorrente')->default(true);
            $table->date('data_inicio');
            $table->date('data_fim')->nullable();          // null quando recorrente
            $table->boolean('ativo')->default(true);
            $table->timestampTz('criado_em')->useCurrent();

            $table->foreign('oficina_id')->references('id')->on('oficinas')->onDelete('cascade');
            $table->index(['oficina_id', 'servico', 'ativo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oficina_servicos');
    }
};
