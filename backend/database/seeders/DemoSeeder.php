<?php
declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Cliente;
use App\Models\Configuracao;
use App\Models\Produto;
use App\Models\Usuario;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        // Usuários demo
        Usuario::create([
            'nome'       => 'Administrador',
            'email'      => 'admin@mecanicapro.com',
            'cpf'        => '52998224725',
            'role'       => 'ADMIN',
            'status'     => 'ATIVO',
            'senha_hash' => Hash::make('admin123'),
        ]);

        Usuario::create([
            'nome'       => 'João Mecânico',
            'email'      => 'mecanico@mecanicapro.com',
            'cpf'        => '87748248800',
            'role'       => 'MECANICO',
            'status'     => 'ATIVO',
            'senha_hash' => Hash::make('mec123'),
        ]);

        // Configurações padrão
        Configuracao::create([
            'razao_social'          => 'Oficina MecânicaPro Ltda',
            'nome_fantasia'         => 'MecânicaPro',
            'cnpj'                  => '11222333000181',
            'regime_tributario'     => 'Simples Nacional',
            'cidade'                => 'São Paulo',
            'uf'                    => 'SP',
            'ambiente_fiscal'       => 'HOMOLOGACAO',
            'serie_nf'              => '001',
            'proximo_numero_nf'     => 1,
            'aliquota_iss'          => 5.00,
            'estoque_limite_padrao' => 5,
            'alertas_email'         => true,
        ]);

        // Clientes demo
        Cliente::create([
            'nome' => 'Carlos Souza', 'cpf_cnpj' => '52998224725',
            'telefone' => '(11) 99999-0001', 'veiculo_modelo' => 'Honda Civic',
            'veiculo_ano' => 2021, 'veiculo_placa' => 'BRA2E19',
            'cidade' => 'São Paulo', 'uf' => 'SP', 'status' => 'REGULAR',
        ]);

        Cliente::create([
            'nome' => 'Maria Oliveira', 'cpf_cnpj' => '87748248800',
            'telefone' => '(11) 88888-0002', 'veiculo_modelo' => 'Toyota Corolla',
            'veiculo_ano' => 2019, 'veiculo_placa' => 'ABC1234',
            'status' => 'DEVEDOR',
        ]);

        // Produtos demo com diferentes níveis de estoque
        Produto::create(['nome' => 'Filtro de Óleo Bosch',  'sku' => 'FLT-OL-001',  'categoria' => 'Filtros',       'qty_atual' => 0,  'qty_minima' => 10, 'preco_custo' => 18.00, 'preco_venda' => 28.90]);
        Produto::create(['nome' => 'Óleo Motor 5W30 1L',    'sku' => 'OL-5W30-1L',  'categoria' => 'Óleo/Fluidos', 'qty_atual' => 3,  'qty_minima' => 20, 'preco_custo' => 30.00, 'preco_venda' => 45.00]);
        Produto::create(['nome' => 'Pastilha de Freio',     'sku' => 'FRE-PAS-001', 'categoria' => 'Freios',        'qty_atual' => 8,  'qty_minima' => 10, 'preco_custo' => 55.00, 'preco_venda' => 89.90]);
        Produto::create(['nome' => 'Filtro de Ar',          'sku' => 'FLT-AR-001',  'categoria' => 'Filtros',       'qty_atual' => 15, 'qty_minima' => 10, 'preco_custo' => 22.00, 'preco_venda' => 35.00]);
        Produto::create(['nome' => 'Vela de Ignição NGK',   'sku' => 'EL-VEL-NGK',  'categoria' => 'Elétrica',      'qty_atual' => 24, 'qty_minima' => 8,  'preco_custo' => 14.00, 'preco_venda' => 22.50]);
        Produto::create(['nome' => 'Correia Dentada',       'sku' => 'MOT-COR-001', 'categoria' => 'Motor',         'qty_atual' => 6,  'qty_minima' => 5,  'preco_custo' => 45.00, 'preco_venda' => 78.00]);
    }
}
