<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('notificacao_visualizacoes', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('tipo', 10); // MANUAL | COBRANCA
            $table->uuid('notificacao_id')->nullable();
            $table->uuid('cobranca_id')->nullable();
            $table->string('titulo', 150);
            $table->text('mensagem');
            $table->uuid('oficina_id');
            $table->uuid('usuario_id')->nullable();
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestampTz('visualizado_em')->useCurrent();

            $table->foreign('notificacao_id')->references('id')->on('notificacoes')->nullOnDelete();
            $table->foreign('cobranca_id')->references('id')->on('cobrancas')->nullOnDelete();
            $table->foreign('oficina_id')->references('id')->on('oficinas')->cascadeOnDelete();
            $table->foreign('usuario_id')->references('id')->on('usuarios')->nullOnDelete();

            $table->index(['tipo', 'notificacao_id']);
            $table->index(['tipo', 'cobranca_id', 'oficina_id']);
            $table->index('oficina_id');
            $table->index('visualizado_em');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notificacao_visualizacoes');
    }
};
