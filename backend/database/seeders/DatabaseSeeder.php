<?php
declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Cliente;
use App\Models\Configuracao;
use App\Models\Oficina;
use App\Models\Plano;
use App\Models\Produto;
use App\Models\SuperAdmin;
use App\Models\Usuario;
use App\Tenancy\TenancyContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // ---------------------------------------------------------------
        // 1. Planos SaaS
        // ---------------------------------------------------------------
        $free = Plano::firstOrCreate(
            ['nome' => 'Free'],
            ['preco_mensal' => 0, 'limite_usuarios' => 2, 'limite_os_mes' => 20, 'ativo' => true]
        );

        $pro = Plano::firstOrCreate(
            ['nome' => 'Pro'],
            ['preco_mensal' => 149, 'limite_usuarios' => 10, 'limite_os_mes' => 200, 'ativo' => true]
        );

        Plano::firstOrCreate(
            ['nome' => 'Enterprise'],
            ['preco_mensal' => 399, 'limite_usuarios' => -1, 'limite_os_mes' => -1, 'ativo' => true]
        );

        // ---------------------------------------------------------------
        // 2. Super Admin (não scoped — sem oficina_id)
        // ---------------------------------------------------------------
        SuperAdmin::firstOrCreate(
            ['email' => 'superadmin@mecanicapro.com'],
            ['nome' => 'Super Admin', 'senha_hash' => Hash::make('super123')]
        );

        // ---------------------------------------------------------------
        // 3. Oficina demo — "Oficina Silva"
        // ---------------------------------------------------------------
        $oficina = Oficina::firstOrCreate(
            ['slug' => 'oficina-silva'],
            [
                'nome'        => 'Oficina Silva',
                'cnpj'        => '12345678000195',
                'plano_id'    => $pro->id,
                'status'      => 'ATIVA',
                'admin_email' => 'admin@mecanicapro.com',
            ]
        );

        // ---------------------------------------------------------------
        // 4. Dados scoped à Oficina Silva
        //    Use withoutGlobalScopes() for lookups to find existing
        //    rows that may have oficina_id = NULL (from earlier seeds)
        //    and update them to be properly scoped.
        // ---------------------------------------------------------------

        // Usuários
        Usuario::withoutGlobalScopes()->updateOrCreate(
            ['email' => 'admin@mecanicapro.com'],
            [
                'nome'       => 'Administrador',
                'cpf'        => '52998224725',
                'role'       => 'ADMIN',
                'status'     => 'ATIVO',
                'senha_hash' => Hash::make('admin123'),
                'oficina_id' => $oficina->id,
            ]
        );

        Usuario::withoutGlobalScopes()->updateOrCreate(
            ['email' => 'mecanico@mecanicapro.com'],
            [
                'nome'       => 'João Mecânico',
                'cpf'        => '87748248800',
                'role'       => 'MECANICO',
                'status'     => 'ATIVO',
                'senha_hash' => Hash::make('mec123'),
                'oficina_id' => $oficina->id,
            ]
        );

        // Configurações da oficina
        Configuracao::withoutGlobalScopes()->updateOrCreate(
            ['cnpj' => '12345678000195'],
            [
                'razao_social'          => 'Oficina Silva LTDA',
                'nome_fantasia'         => 'Oficina Silva',
                'regime_tributario'     => 'Simples Nacional',
                'cidade'                => 'São Paulo',
                'uf'                    => 'SP',
                'ambiente_fiscal'       => 'HOMOLOGACAO',
                'serie_nf'              => '001',
                'proximo_numero_nf'     => 1,
                'aliquota_iss'          => 5.00,
                'estoque_limite_padrao' => 5,
                'alertas_email'         => true,
                'oficina_id'            => $oficina->id,
            ]
        );

        // Handle existing Configuracao row that may have a different cnpj
        Configuracao::withoutGlobalScopes()
            ->whereNull('oficina_id')
            ->update(['oficina_id' => $oficina->id]);

        // Clientes demo
        Cliente::withoutGlobalScopes()->updateOrCreate(
            ['cpf_cnpj' => '52998224725'],
            [
                'nome'           => 'Carlos Souza',
                'telefone'       => '(11) 99999-0001',
                'veiculo_modelo' => 'Honda Civic',
                'veiculo_ano'    => 2021,
                'veiculo_placa'  => 'BRA2E19',
                'cidade'         => 'São Paulo',
                'uf'             => 'SP',
                'status'         => 'REGULAR',
                'oficina_id'     => $oficina->id,
            ]
        );

        Cliente::withoutGlobalScopes()->updateOrCreate(
            ['cpf_cnpj' => '87748248800'],
            [
                'nome'           => 'Maria Oliveira',
                'telefone'       => '(11) 88888-0002',
                'veiculo_modelo' => 'Toyota Corolla',
                'veiculo_ano'    => 2019,
                'veiculo_placa'  => 'ABC1234',
                'status'         => 'DEVEDOR',
                'oficina_id'     => $oficina->id,
            ]
        );

        // Produtos demo com diferentes níveis de estoque
        $produtos = [
            ['nome' => 'Filtro de Óleo Bosch',  'sku' => 'FLT-OL-001',  'categoria' => 'Filtros',       'qty_atual' => 0,  'qty_minima' => 10, 'preco_custo' => 18.00, 'preco_venda' => 28.90],
            ['nome' => 'Óleo Motor 5W30 1L',    'sku' => 'OL-5W30-1L',  'categoria' => 'Óleo/Fluidos', 'qty_atual' => 3,  'qty_minima' => 20, 'preco_custo' => 30.00, 'preco_venda' => 45.00],
            ['nome' => 'Pastilha de Freio',     'sku' => 'FRE-PAS-001', 'categoria' => 'Freios',        'qty_atual' => 8,  'qty_minima' => 10, 'preco_custo' => 55.00, 'preco_venda' => 89.90],
            ['nome' => 'Filtro de Ar',          'sku' => 'FLT-AR-001',  'categoria' => 'Filtros',       'qty_atual' => 15, 'qty_minima' => 10, 'preco_custo' => 22.00, 'preco_venda' => 35.00],
            ['nome' => 'Vela de Ignição NGK',   'sku' => 'EL-VEL-NGK',  'categoria' => 'Elétrica',      'qty_atual' => 24, 'qty_minima' => 8,  'preco_custo' => 14.00, 'preco_venda' => 22.50],
            ['nome' => 'Correia Dentada',       'sku' => 'MOT-COR-001', 'categoria' => 'Motor',         'qty_atual' => 6,  'qty_minima' => 5,  'preco_custo' => 45.00, 'preco_venda' => 78.00],
        ];

        foreach ($produtos as $produto) {
            Produto::withoutGlobalScopes()->updateOrCreate(
                ['sku' => $produto['sku']],
                array_merge($produto, ['oficina_id' => $oficina->id])
            );
        }
    }
}
