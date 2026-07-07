<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('veiculo_proprietarios', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('veiculo_id');
            $table->uuid('cliente_id');
            $table->foreignUuid('oficina_id')->nullable()->constrained('oficinas')->nullOnDelete();
            $table->timestampTz('data_inicio')->useCurrent();
            $table->timestampTz('data_fim')->nullable();

            $table->foreign('veiculo_id')->references('id')->on('veiculos')->onDelete('cascade');
            $table->foreign('cliente_id')->references('id')->on('clientes')->onDelete('cascade');
            $table->index(['veiculo_id', 'data_fim']);
        });

        // Backfill: 1 período aberto (dono atual) por veículo já existente.
        $veiculos = DB::table('veiculos')->select('id', 'cliente_id', 'oficina_id', 'criado_em')->get();
        foreach ($veiculos as $veiculo) {
            DB::table('veiculo_proprietarios')->insert([
                'id'          => (string) Str::uuid(),
                'veiculo_id'  => $veiculo->id,
                'cliente_id'  => $veiculo->cliente_id,
                'oficina_id'  => $veiculo->oficina_id,
                'data_inicio' => $veiculo->criado_em,
                'data_fim'    => null,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('veiculo_proprietarios');
    }
};
