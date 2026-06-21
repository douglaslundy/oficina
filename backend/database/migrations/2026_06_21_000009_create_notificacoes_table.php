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
        Schema::create('notificacoes', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('titulo', 150);
            $table->string('subtitulo', 200)->nullable();
            $table->text('texto');
            $table->text('imagem')->nullable(); // data URL base64 (opcional)
            $table->string('alvo_tipo', 12)->default('TODOS'); // TODOS | PLANO | OFICINAS
            $table->uuid('plano_id')->nullable();
            $table->jsonb('oficina_ids')->default('[]');
            $table->integer('vezes_dia')->default(1);        // máx. de exibições por dia
            $table->integer('intervalo_minutos')->default(60); // intervalo mínimo entre exibições
            $table->date('data_inicio')->nullable();
            $table->date('data_fim')->nullable();
            $table->boolean('ativo')->default(true);
            $table->timestampTz('criado_em')->useCurrent();

            $table->index(['ativo', 'alvo_tipo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notificacoes');
    }
};
