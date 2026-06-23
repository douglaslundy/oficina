<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Models\Configuracao;
use App\Services\Fiscal\RegistrarEmissorService;
use PHPUnit\Framework\TestCase;

class RegistrarEmissorMontagemTest extends TestCase
{
    public function test_monta_emissor_data_da_configuracao(): void
    {
        $cfg = new Configuracao([
            'cnpj' => '12.345.678/0001-99', 'razao_social' => 'Oficina X Ltda',
            'nome_fantasia' => 'Oficina X', 'inscricao_estadual' => '123',
            'inscricao_municipal' => '456', 'regime_tributario' => 'Simples Nacional',
            'email' => 'of@x.com', 'telefone' => '11999999999', 'cep' => '01310-100',
            'endereco' => 'Av Paulista', 'cidade' => 'São Paulo', 'uf' => 'SP',
            'cnae' => '4520-0/01', 'codigo_ibge' => '3550308',
        ]);

        $service = new RegistrarEmissorService(
            new \App\Services\Fiscal\FiscalProviderManager(),
            new \App\Services\Fiscal\CertificadoValidator(),
        );
        $e = $service->montarEmissorData($cfg);

        $this->assertSame('12345678000199', $e->cnpjLimpo());
        $this->assertSame('Oficina X Ltda', $e->razaoSocial);
        $this->assertSame('3550308', $e->codigoIbge);
    }
}
