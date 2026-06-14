<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('veiculos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('cliente_id');
            $table->uuid('oficina_id');
            $table->string('modelo', 80);
            $table->smallInteger('ano')->nullable();
            $table->string('placa', 10)->nullable();
            $table->string('chassi', 20)->nullable();
            $table->boolean('ativo')->default(true);
            $table->timestampTz('criado_em')->useCurrent();

            $table->foreign('cliente_id')->references('id')->on('clientes')->onDelete('cascade');
            $table->foreign('oficina_id')->references('id')->on('oficinas')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('veiculos');
    }
};
