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

        $this->assertSame('data:image/png;base64,QRDATA', $qr['qrcode']);

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

        $this->assertSame('data:image/png;base64,CONNECTQR', $qr['qrcode']);
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

    public function test_qrcode_propaga_erro_de_conexao(): void
    {
        // Simula falha de conexão (ex.: URL inalcançável de dentro do container).
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('cURL error 7: Failed to connect');
        });

        $r = $this->service->qrCode();

        $this->assertNull($r['qrcode']);
        $this->assertArrayHasKey('error', $r);
        $this->assertStringContainsString('cURL error 7', $r['error']);
    }

    public function test_enviar_teste_normaliza_numero_e_envia(): void
    {
        Http::fake([
            '*/message/sendText/*' => Http::response(['key' => ['id' => 'ABC']], 201),
        ]);

        $r = $this->service->enviarTeste('11999998888');

        $this->assertTrue($r['ok']);
        // Deve prefixar 55 (DDI Brasil) no número
        Http::assertSent(fn ($req) => str_contains($req->url(), '/message/sendText/')
            && $req['number'] === '5511999998888');
    }

    public function test_enviar_teste_retorna_erro_quando_evolution_falha(): void
    {
        Http::fake([
            '*/message/sendText/*' => Http::response(['message' => 'not connected'], 400),
        ]);

        $r = $this->service->enviarTeste('11999998888');

        $this->assertFalse($r['ok']);
        $this->assertArrayHasKey('error', $r);
    }

    public function test_desconectar_chama_logout(): void
    {
        Http::fake([
            '*/instance/logout/*' => Http::response(['status' => 'SUCCESS'], 200),
        ]);

        $r = $this->service->desconectar();

        $this->assertTrue($r['ok']);
        Http::assertSent(fn ($req) => str_contains($req->url(), '/instance/logout/')
            && $req->method() === 'DELETE');
    }
}
