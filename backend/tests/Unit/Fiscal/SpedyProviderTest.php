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
}
