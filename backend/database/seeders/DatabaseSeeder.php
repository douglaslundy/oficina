<?php
declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Plano;
use App\Models\SuperAdmin;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Planos SaaS
        Plano::firstOrCreate(
            ['nome' => 'Free'],
            ['preco_mensal' => 0, 'limite_usuarios' => 2, 'limite_os_mes' => 20,
             'limite_produtos' => 50, 'limite_clientes' => 50, 'limite_notas_mes' => 10,
             'preco_nota_excedente' => 2.50, 'ativo' => true]
        );

        Plano::firstOrCreate(
            ['nome' => 'Pro'],
            ['preco_mensal' => 149, 'limite_usuarios' => 10, 'limite_os_mes' => 200,
             'limite_produtos' => 1000, 'limite_clientes' => 1000, 'limite_notas_mes' => 200,
             'preco_nota_excedente' => 1.50, 'ativo' => true]
        );

        Plano::firstOrCreate(
            ['nome' => 'Enterprise'],
            ['preco_mensal' => 399, 'limite_usuarios' => -1, 'limite_os_mes' => -1,
             'limite_produtos' => -1, 'limite_clientes' => -1, 'limite_notas_mes' => -1,
             'preco_nota_excedente' => 0, 'ativo' => true]
        );

        // Super Admin padrao
        SuperAdmin::firstOrCreate(
            ['email' => 'douglaslundy@gmail.com'],
            ['nome' => 'Super Admin', 'senha_hash' => Hash::make('12345678')]
        );
    }
}
