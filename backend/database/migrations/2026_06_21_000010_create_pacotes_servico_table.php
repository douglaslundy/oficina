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
        Schema::create('pacotes_servico', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('nome', 100);
            $table->string('servico', 30); // ALERTA_WHATSAPP | ALERTA_EMAIL | ORCAMENTO
            $table->integer('quantidade')->default(-1);   // -1 = ilimitado (por mês)
            $table->decimal('valor', 10, 2)->default(0);  // valor adicional na mensalidade
            $table->boolean('recorrente')->default(true); // true = sem vencimento
            $table->integer('periodo_dias')->nullable();  // usado quando não recorrente
            $table->boolean('ativo')->default(true);
            $table->timestampTz('criado_em')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pacotes_servico');
    }
};
