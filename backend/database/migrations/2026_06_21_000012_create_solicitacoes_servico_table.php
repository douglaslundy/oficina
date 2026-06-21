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
        Schema::create('solicitacoes_servico', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('oficina_id');
            $table->uuid('pacote_id');
            $table->string('status', 12)->default('PENDENTE'); // PENDENTE | APROVADA | RECUSADA
            $table->text('observacao')->nullable();
            $table->timestampTz('criado_em')->useCurrent();
            $table->timestampTz('respondido_em')->nullable();

            $table->foreign('oficina_id')->references('id')->on('oficinas')->onDelete('cascade');
            $table->foreign('pacote_id')->references('id')->on('pacotes_servico')->onDelete('cascade');
            $table->index(['status', 'oficina_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('solicitacoes_servico');
    }
};
