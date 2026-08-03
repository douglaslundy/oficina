<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Services\Fiscal\Data\EmissorData;
use App\Services\Fiscal\Data\NotaFiscalData;
use App\Services\Fiscal\Providers\NfePhpProvider;
use PHPUnit\Framework\TestCase;

class NfePhpProviderTest extends TestCase
{
    private function emissorCompleto(): EmissorData
    {
        return new EmissorData(
            cnpj: '12.345.678/0001-99', razaoSocial: 'Oficina Teste Ltda', nomeFantasia: 'Oficina Teste',
            inscricaoEstadual: '123456789', inscricaoMunicipal: '987654321', regimeTributario: 'Simples Nacional',
            email: 'contato@oficina.com', telefone: '11999999999', cep: '01310-100', logradouro: 'Av Paulista',
            numero: '1000', complemento: null, bairro: 'Bela Vista', cidade: 'São Paulo', uf: 'SP',
            codigoIbge: '3550308', cnae: '4520-0/01',
        );
    }

    public function test_registrar_emissor_ok_com_dados_completos(): void
    {
        $p = new NfePhpProvider('HOMOLOGACAO');
        $r = $p->registrarEmissor($this->emissorCompleto());

        $this->assertSame('REGISTRADO', $r->status);
    }

    public function test_registrar_emissor_erro_com_cnae_vazio(): void
    {
        $incompleto = new EmissorData(
            cnpj: '12.345.678/0001-99', razaoSocial: 'Oficina Teste Ltda', nomeFantasia: null,
            inscricaoEstadual: '123456789', inscricaoMunicipal: '987654321', regimeTributario: 'Simples Nacional',
            email: 'contato@oficina.com', telefone: null, cep: '01310-100', logradouro: 'Av Paulista',
            numero: '1000', complemento: null, bairro: 'Bela Vista', cidade: 'São Paulo', uf: 'SP',
            codigoIbge: '3550308', cnae: '',
        );

        $p = new NfePhpProvider('HOMOLOGACAO');
        $r = $p->registrarEmissor($incompleto);

        $this->assertSame('ERRO', $r->status);
        $this->assertStringContainsString('CNAE', $r->mensagemErro);
    }

    public function test_emitir_nfe_ainda_nao_suportado(): void
    {
        $nota = new NotaFiscalData(
            tipo: 'NFSE', tomador: ['nome' => 'Cliente', 'cpf_cnpj' => '12345678000199'],
            descricao: 'Venda', valorServicos: 0.0, aliquotaIss: 0.0, issRetido: false,
            codigoServicoFederal: '', codigoServicoMunicipal: '',
            naturezaOperacao: 'Venda de Mercadoria', referenciaExterna: 'nf-1', modelo: 'NFE',
        );

        $p = new NfePhpProvider('HOMOLOGACAO');
        $r = $p->emitir($nota);

        $this->assertSame('REJEITADA', $r->status);
    }
}
