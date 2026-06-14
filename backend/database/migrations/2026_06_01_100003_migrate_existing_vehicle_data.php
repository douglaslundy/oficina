<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $clientes = DB::table('clientes')
            ->whereNotNull('veiculo_modelo')
            ->get(['id', 'oficina_id', 'veiculo_modelo', 'veiculo_ano', 'veiculo_placa']);

        foreach ($clientes as $cliente) {
            DB::table('veiculos')->insert([
                'id'         => (string) Str::uuid(),
                'cliente_id' => $cliente->id,
                'oficina_id' => $cliente->oficina_id,
                'modelo'     => $cliente->veiculo_modelo,
                'ano'        => $cliente->veiculo_ano,
                'placa'      => $cliente->veiculo_placa,
                'chassi'     => null,
                'ativo'      => true,
                'criado_em'  => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Remove migrated vehicles — identify by matching clientes with veiculo_modelo set
        $clienteIds = DB::table('clientes')
            ->whereNotNull('veiculo_modelo')
            ->pluck('id');

        DB::table('veiculos')->whereIn('cliente_id', $clienteIds)->delete();
    }
};
