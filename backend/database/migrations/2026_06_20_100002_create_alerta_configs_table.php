<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alerta_configs', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('oficina_id');
            $table->string('tipo', 50)->notNull();
            $table->string('nome', 120)->notNull();
            $table->boolean('pre_definido')->default(true);
            $table->boolean('ativo')->default(false);
            $table->text('template_mensagem')->nullable();
            $table->jsonb('destinatarios')->default('[]');
            $table->boolean('enviar_cliente')->default(false);
            $table->boolean('enviar_mecanico')->default(false);
            $table->timestampTz('criado_em')->useCurrent();
            $table->timestampTz('atualizado_em')->useCurrent()->useCurrentOnUpdate();

            $table->foreign('oficina_id')->references('id')->on('oficinas')->onDelete('cascade');
            $table->unique(['oficina_id', 'tipo'])->where('pre_definido = true');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alerta_configs');
    }
};
