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
        Schema::create('orcamentos', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('oficina_id');
            $table->uuid('os_id');
            $table->string('token', 64)->unique();
            // PENDENTE | APROVADO | PARCIAL | RECUSADO
            $table->string('status', 15)->default('PENDENTE');
            $table->string('canal_envio', 30)->nullable(); // WHATSAPP | EMAIL | AMBOS
            $table->timestampTz('enviado_em')->useCurrent();
            $table->timestampTz('respondido_em')->nullable();

            $table->foreign('oficina_id')->references('id')->on('oficinas')->onDelete('cascade');
            $table->foreign('os_id')->references('id')->on('ordens_servico')->onDelete('cascade');
            $table->index(['oficina_id', 'os_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orcamentos');
    }
};
