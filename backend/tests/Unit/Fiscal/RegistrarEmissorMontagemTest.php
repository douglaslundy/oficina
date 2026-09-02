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

    private function service(): RegistrarEmissorService
    {
        return new RegistrarEmissorService(
            new \App\Services\Fiscal\FiscalProviderManager(),
            new \App\Services\Fiscal\CertificadoValidator(),
        );
    }

    public function test_monta_emissor_data_usa_campos_de_endereco_decompostos(): void
    {
        $cfg = new Configuracao([
            'cnpj' => '12345678000199', 'razao_social' => 'X', 'regime_tributario' => 'Simples Nacional',
            'endereco' => 'texto livre que nao deve ir pro campo estruturado',
            'logradouro' => 'Rua das Flores', 'numero' => '123', 'bairro' => 'Jardim',
            'cidade' => 'Ilicínea', 'uf' => 'MG', 'cep' => '37275-000',
        ]);

        $e = $this->service()->montarEmissorData($cfg);

        $this->assertSame('Rua das Flores', $e->logradouro);
        $this->assertSame('123', $e->numero);
        $this->assertSame('Jardim', $e->bairro);
    }

    public function test_monta_emissor_data_sem_regime_nao_inventa_simples_nacional(): void
    {
        $cfg = new Configuracao(['cnpj' => '12345678000199', 'razao_social' => 'X']);

        $e = $this->service()->montarEmissorData($cfg);

        $this->assertSame('', $e->regimeTributario);
    }

    public function test_campos_fiscais_faltando_lista_o_que_falta(): void
    {
        $cfg = new Configuracao([
            'regime_tributario' => 'Simples Nacional', 'uf' => 'MG', 'cidade' => 'Ilicínea',
            // sem logradouro, bairro, cep, codigo_ibge
        ]);

        $faltando = RegistrarEmissorService::camposFiscaisFaltando($cfg);

        $this->assertContains('logradouro', $faltando);
        $this->assertContains('bairro', $faltando);
        $this->assertContains('CEP', $faltando);
        $this->assertContains('código IBGE do município', $faltando);
        $this->assertNotContains('UF', $faltando);
    }

    public function test_campos_fiscais_faltando_vazio_quando_completo(): void
    {
        $cfg = new Configuracao([
            'regime_tributario' => 'Simples Nacional', 'uf' => 'MG', 'cidade' => 'Ilicínea',
            'logradouro' => 'Rua A', 'bairro' => 'Centro', 'cep' => '37275-000',
            'codigo_ibge' => '3132404',
        ]);

        $this->assertSame([], RegistrarEmissorService::camposFiscaisFaltando($cfg));
    }
}
