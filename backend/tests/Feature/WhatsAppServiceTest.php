<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Oficina;
use App\Models\WhatsAppConfig;
use App\Services\WhatsAppService;
use App\Tenancy\TenancyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppServiceTest extends TestCase
{
    use RefreshDatabase;

    private WhatsAppService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $oficina = Oficina::create([
            'nome' => 'Oficina Teste',
            'cnpj' => '12.345.678/0001-90',
            'slug' => 'oficina-teste',
        ]);
        TenancyContext::set($oficina->id, $oficina->slug);

        WhatsAppConfig::create([
            'oficina_id'        => $oficina->id,
            'evolution_url'     => 'http://evolution.test',
            'evolution_api_key' => 'chave-global-123',
            'instance_name'     => 'mecanicapro',
            'ativo'             => true,
        ]);

        $this->service = app(WhatsAppService::class);
    }

    protected function tearDown(): void
    {
        TenancyContext::clear();
        parent::tearDown();
    }

    public function test_qrcode_cria_instancia_quando_nao_existe(): void
    {
        Http::fake([
            // Instância ainda não existe na Evolution
            '*/instance/connectionState/*' => Http::response([
                'status' => 404,
                'error'  => 'Not Found',
                'response' => ['message' => ['The "mecanicapro" instance does not exist']],
            ], 404),
            // Criação devolve o QR em base64 + hash da instância
            '*/instance/create' => Http::response([
                'instance' => ['instanceName' => 'mecanicapro', 'status' => 'created'],
                'hash'     => 'token-da-instancia-xyz',
                'qrcode'   => ['base64' => 'data:image/png;base64,QRDATA'],
            ], 201),
        ]);

        $qr = $this->service->qrCode();

        $this->assertSame('data:image/png;base64,QRDATA', $qr);

        // Confirma que chamou o endpoint de criação
        Http::assertSent(fn ($req) => str_contains($req->url(), '/instance/create')
            && $req['instanceName'] === 'mecanicapro'
            && $req['qrcode'] === true);

        // Token retornado pela Evolution deve ter sido persistido
        $this->assertSame('token-da-instancia-xyz', WhatsAppConfig::first()->getRawOriginal('instance_token'));
    }

    public function test_qrcode_usa_connect_quando_instancia_ja_existe(): void
    {
        Http::fake([
            '*/instance/connectionState/*' => Http::response([
                'instance' => ['state' => 'connecting'],
            ], 200),
            '*/instance/connect/*' => Http::response([
                'base64' => 'data:image/png;base64,CONNECTQR',
            ], 200),
        ]);

        $qr = $this->service->qrCode();

        $this->assertSame('data:image/png;base64,CONNECTQR', $qr);
        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/instance/create'));
    }

    public function test_testar_conexao_avisa_quando_instancia_nao_existe(): void
    {
        Http::fake([
            '*/instance/connectionState/*' => Http::response([
                'status'   => 404,
                'response' => ['message' => ['The "mecanicapro" instance does not exist']],
            ], 404),
        ]);

        $resultado = $this->service->testarConexao('http://evolution.test', 'chave-global-123', 'mecanicapro');

        $this->assertFalse($resultado['ok']);
        $this->assertSame('nao_criada', $resultado['status']);
    }
}
