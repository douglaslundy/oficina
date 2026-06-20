<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('alerta_logs', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('oficina_id');
            $table->string('tipo', 50)->notNull();
            $table->string('destinatario', 30)->notNull();
            $table->text('mensagem')->notNull();
            $table->boolean('sucesso')->default(true);
            $table->text('erro')->nullable();
            $table->timestampTz('enviado_em')->useCurrent();

            $table->foreign('oficina_id')->references('id')->on('oficinas')->onDelete('cascade');
            $table->index(['oficina_id', 'enviado_em']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alerta_logs');
    }
};
