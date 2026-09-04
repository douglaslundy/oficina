<?php
declare(strict_types=1);

namespace Tests\Feature\Saas;

use App\Models\SuperAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BackupDownloadTest extends TestCase
{
    use RefreshDatabase;

    private string $arquivo;

    protected function setUp(): void
    {
        parent::setUp();
        $dir = storage_path('backups');
        @mkdir($dir, 0755, true);
        $this->arquivo = 'backup_2026-09-04_10-00-00_teste.sql.gz';
        file_put_contents($dir . '/' . $this->arquivo, gzencode('conteudo do backup'));
    }

    protected function tearDown(): void
    {
        @unlink(storage_path('backups/' . $this->arquivo));
        parent::tearDown();
    }

    private function admin(): SuperAdmin
    {
        return SuperAdmin::create([
            'nome' => 'Super', 'email' => 's@t.com', 'senha_hash' => Hash::make('x'),
        ]);
    }

    public function test_link_assinado_permite_download_sem_auth(): void
    {
        $url = $this->actingAs($this->admin(), 'saas')
            ->getJson("/api/saas/backup/{$this->arquivo}/link")
            ->assertOk()
            ->json('url');

        $this->assertStringContainsString('signature=', $url);

        // Sem token — a assinatura é a credencial.
        $resp = $this->get($url);
        $resp->assertOk();
        $resp->assertHeader('content-disposition', 'attachment; filename="' . $this->arquivo . '"');
    }

    public function test_assinatura_invalida_recusa(): void
    {
        $this->get("/api/saas/backup/{$this->arquivo}/download-assinado?expires=9999999999&signature=deadbeef")
            ->assertForbidden();
    }

    public function test_link_de_arquivo_inexistente_404(): void
    {
        $this->actingAs($this->admin(), 'saas')
            ->getJson('/api/saas/backup/backup_2020-01-01_00-00-00.sql.gz/link')
            ->assertNotFound();
    }
}
