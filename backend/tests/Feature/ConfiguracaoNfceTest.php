<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Configuracao;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Pedido explícito do usuário (2026-09-14): switch de qual documento fiscal
 * usar pra venda de produtos (NF-e como padrão) + CSC/CSCId pra NFC-e via
 * NFePHP.
 */
class ConfiguracaoNfceTest extends TestCase
{
    use RefreshDatabase;

    private function loginAdmin(): string
    {
        $user = Usuario::create([
            'nome' => 'Admin', 'email' => 'admin@test.com', 'cpf' => '52998224725',
            'role' => 'ADMIN', 'status' => 'ATIVO', 'senha_hash' => Hash::make('admin123'),
        ]);
        return $user->createToken('test')->plainTextToken;
    }

    public function test_modelo_venda_padrao_e_nfe_por_padrao(): void
    {
        $config = Configuracao::create(['razao_social' => 'Oficina X']);

        $this->assertSame('NF-e', $config->modelo_venda_padrao);
    }

    public function test_show_nunca_expoe_csc_em_texto_puro(): void
    {
        $token = $this->loginAdmin();
        Configuracao::create([
            'razao_social' => 'Oficina X',
            'csc_id_homologacao' => '1',
            'csc_token_homologacao_encrypted' => Crypt::encryptString('segredo-csc-123'),
        ]);

        $response = $this->withToken($token)->getJson('/api/configuracoes');

        $response->assertStatus(200)
            ->assertJsonPath('tem_csc_homologacao', true)
            ->assertJsonPath('tem_csc_producao', false)
            ->assertJsonMissing(['csc_token_homologacao_encrypted']);
        $this->assertStringNotContainsString('segredo-csc-123', $response->getContent());
    }

    public function test_update_cifra_o_token_csc_antes_de_salvar(): void
    {
        $token = $this->loginAdmin();
        Configuracao::create(['razao_social' => 'Oficina X']);

        $response = $this->withToken($token)->putJson('/api/configuracoes', [
            'modelo_venda_padrao'   => 'NFC-e',
            'csc_id_homologacao'    => '1',
            'csc_token_homologacao' => 'segredo-csc-456',
        ]);

        $response->assertStatus(200);
        $config = Configuracao::first();
        $this->assertSame('NFC-e', $config->modelo_venda_padrao);
        $this->assertSame('1', $config->csc_id_homologacao);
        $this->assertNotNull($config->csc_token_homologacao_encrypted);
        $this->assertStringNotContainsString('segredo-csc-456', $config->csc_token_homologacao_encrypted);
        $this->assertSame('segredo-csc-456', Crypt::decryptString($config->csc_token_homologacao_encrypted));
    }

    /**
     * Bug real de produção (2026-09-14, achado ao testar o switch em
     * produção): update() devolvia $config INTEIRO, cru — nunca passava
     * pelo mesmo filtro que show() já tinha. Expunha o PFX do certificado
     * inteiro, a senha cifrada e o token CSC recém-adicionado, todos em
     * texto (o ciphertext, mas mesmo assim não deveria sair do backend).
     */
    public function test_update_nunca_expoe_secrets_cifrados_na_resposta(): void
    {
        $token = $this->loginAdmin();
        Configuracao::create([
            'razao_social' => 'Oficina X',
            'certificado_pfx_encrypted' => 'blob-pfx-fake',
            'certificado_senha_encrypted' => Crypt::encryptString('senha-cert'),
        ]);

        $response = $this->withToken($token)->putJson('/api/configuracoes', [
            'csc_id_homologacao'    => '1',
            'csc_token_homologacao' => 'segredo-csc-789',
        ]);

        $response->assertStatus(200);
        $body = $response->getContent();
        $this->assertStringNotContainsString('blob-pfx-fake', $body);
        $this->assertStringNotContainsString('senha-cert', $body);
        $this->assertStringNotContainsString('segredo-csc-789', $body);
        $response->assertJsonMissing(['certificado_pfx_encrypted'])
            ->assertJsonMissing(['certificado_senha_encrypted'])
            ->assertJsonMissing(['csc_token_homologacao_encrypted']);
    }

    public function test_update_rejeita_modelo_venda_padrao_invalido(): void
    {
        $token = $this->loginAdmin();
        Configuracao::create(['razao_social' => 'Oficina X']);

        $response = $this->withToken($token)->putJson('/api/configuracoes', [
            'modelo_venda_padrao' => 'NFS-e',
        ]);

        $response->assertStatus(422);
    }
}
