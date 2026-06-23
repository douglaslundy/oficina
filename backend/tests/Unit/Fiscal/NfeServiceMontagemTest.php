<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Models\Cliente;
use App\Models\NotaFiscal;
use App\Services\NfeService;
use PHPUnit\Framework\TestCase;

class NfeServiceMontagemTest extends TestCase
{
    public function test_monta_nota_data_a_partir_da_nota(): void
    {
        $cliente = new Cliente([
            'nome' => 'Fulano', 'cpf_cnpj' => '12345678000199',
            'email' => 'f@x.com', 'cep' => '01310100', 'endereco' => 'Av A',
            'bairro' => 'Centro', 'cidade' => 'São Paulo', 'uf' => 'SP',
        ]);
        $nota = new NotaFiscal([
            'valor_total' => 150.0, 'aliquota_iss' => 5.0,
            'natureza_operacao' => 'Prestação de Serviços',
            'observacoes' => 'Troca de óleo', 'referencia_externa' => 'nf-abc',
        ]);
        $nota->setRelation('cliente', $cliente);

        $service = new NfeService();
        $data = $service->montarNotaData($nota, codigoServicoFederal: '14.01', codigoServicoMunicipal: '1401', codigoIbgeTomador: '3550308');

        $this->assertSame('NFSE', $data->tipo);
        $this->assertSame(150.0, $data->valorServicos);
        $this->assertSame(5.0, $data->aliquotaIss);
        $this->assertSame('nf-abc', $data->referenciaExterna);
        $this->assertSame('12345678000199', $data->tomador['cpf_cnpj']);
    }
}
