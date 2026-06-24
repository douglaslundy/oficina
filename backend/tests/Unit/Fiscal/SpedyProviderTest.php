<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Services\Fiscal\Data\NotaFiscalData;
use App\Services\Fiscal\Providers\SpedyProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SpedyProviderTest extends TestCase
{
    private function nota(): NotaFiscalData
    {
        return new NotaFiscalData(
            tipo: 'NFSE',
            tomador: [
                'nome' => 'Cliente Teste', 'cpf_cnpj' => '12345678000199',
                'email' => 'c@x.com', 'cep' => '01310100', 'logradouro' => 'Av A',
                'numero' => '10', 'bairro' => 'Centro', 'cidade' => 'São Paulo',
                'uf' => 'SP', 'codigo_ibge' => '3550308',
            ],
            descricao: 'Serviço de troca de óleo',
            valorServicos: 200.00,
            aliquotaIss: 5.0,
            issRetido: false,
            codigoServicoFederal: '14.01',
            codigoServicoMunicipal: '1401',
            naturezaOperacao: 'Prestação de Serviços',
            referenciaExterna: 'os-123',
        );
    }

    public function test_map_status_normaliza(): void
    {
        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $this->assertSame('AUTORIZADA', $p->mapStatus('authorized'));
        $this->assertSame('PROCESSANDO', $p->mapStatus('enqueued'));
        $this->assertSame('REJEITADA', $p->mapStatus('rejected'));
        $this->assertSame('CANCELADA', $p->mapStatus('canceled'));
    }

    public function test_payload_nfse_usa_campos_spedy(): void
    {
        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $payload = $p->montarPayloadNfse($this->nota());

        $this->assertSame('Serviço de troca de óleo', $payload['description']);
        $this->assertSame('14.01', $payload['federalServiceCode']);
        $this->assertSame(200.00, $payload['total']['invoiceAmount']);
        $this->assertSame(0.05, $payload['total']['issRate']);
        $this->assertSame('12345678000199', $payload['receiver']['federalTaxNumber']);
    }

    public function test_emitir_autorizada(): void
    {
        Http::fake([
            '*/service-invoices' => Http::response([
                'id' => 'inv-1', 'status' => 'authorized',
                'accessKey' => 'CHAVE-SP', 'number' => '55',
            ], 201),
        ]);

        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $r = $p->emitir($this->nota());

        $this->assertSame('AUTORIZADA', $r->status);
        $this->assertSame('CHAVE-SP', $r->chave);
        $this->assertSame('55', $r->numero);

        Http::assertSent(fn ($req) =>
            $req->hasHeader('X-Api-Key', 'tok') &&
            str_contains($req->url(), '/service-invoices')
        );
    }

    public function test_emitir_falha_retorna_rejeitada(): void
    {
        Http::fake([
            '*/service-invoices' => Http::response(['message' => 'CNPJ não habilitado'], 422),
        ]);

        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $r = $p->emitir($this->nota());

        $this->assertSame('REJEITADA', $r->status);
        $this->assertStringContainsString('CNPJ não habilitado', (string) $r->mensagemErro);
    }

    public function test_registrar_emissor_retorna_token(): void
    {
        Http::fake([
            '*/companies' => Http::response([
                'id' => 'comp-1',
                'apiCredentials' => ['apiKey' => 'spedy-key-1'],
            ], 201),
        ]);

        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', null, null);
        $e = new \App\Services\Fiscal\Data\EmissorData(
            cnpj: '12.345.678/0001-99', razaoSocial: 'Oficina X Ltda', nomeFantasia: 'Oficina X',
            inscricaoEstadual: '123', inscricaoMunicipal: '456', regimeTributario: 'Simples Nacional',
            email: 'of@x.com', telefone: '11999999999', cep: '01310-100', logradouro: 'Av Paulista',
            numero: '1000', complemento: null, bairro: 'Centro', cidade: 'São Paulo', uf: 'SP',
            codigoIbge: '3550308', cnae: '4520-0/01',
        );
        $r = $p->registrarEmissor($e);

        $this->assertSame('REGISTRADO', $r->status);
        $this->assertSame('comp-1', $r->emissorExternoId);
        $this->assertSame('spedy-key-1', $r->token);
    }

    public function test_cancelar_sucesso(): void
    {
        Http::fake(['*/service-invoices/inv-1' => Http::response([], 200)]);

        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $r = $p->cancelar('inv-1', 'Serviço não prestado conforme acordado');

        $this->assertSame('CANCELADA', $r->status);
    }
}
