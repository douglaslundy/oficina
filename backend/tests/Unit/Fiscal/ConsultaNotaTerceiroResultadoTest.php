<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Services\Fiscal\Data\ConsultaNotaTerceiroResultado;
use App\Services\Fiscal\Data\ConsultaNotaTerceiroResumo;
use PHPUnit\Framework\TestCase;

class ConsultaNotaTerceiroResultadoTest extends TestCase
{
    public function test_completa_carrega_os_dados(): void
    {
        $r = ConsultaNotaTerceiroResultado::completa(['chave_acesso' => 'X', 'itens' => []]);
        $this->assertSame('COMPLETA', $r->status);
        $this->assertSame(['chave_acesso' => 'X', 'itens' => []], $r->dados);
        $this->assertNull($r->mensagemErro);
    }

    public function test_aguardando_manifestacao_nao_carrega_dados(): void
    {
        $r = ConsultaNotaTerceiroResultado::aguardandoManifestacao();
        $this->assertSame('AGUARDANDO_MANIFESTACAO', $r->status);
        $this->assertNull($r->dados);
    }

    public function test_nao_encontrada(): void
    {
        $r = ConsultaNotaTerceiroResultado::naoEncontrada();
        $this->assertSame('NAO_ENCONTRADA', $r->status);
    }

    public function test_erro_carrega_mensagem(): void
    {
        $r = ConsultaNotaTerceiroResultado::erro('Falha ao consultar.');
        $this->assertSame('ERRO', $r->status);
        $this->assertSame('Falha ao consultar.', $r->mensagemErro);
    }

    public function test_resumo_expoe_propriedades(): void
    {
        $r = new ConsultaNotaTerceiroResumo('CHAVE1', 'Fornecedor X', '12345678000199', '2026-09-01', 150.5, true);
        $this->assertSame('CHAVE1', $r->chaveAcesso);
        $this->assertSame('Fornecedor X', $r->fornecedorNome);
        $this->assertSame('12345678000199', $r->fornecedorCnpj);
        $this->assertSame('2026-09-01', $r->dataEmissao);
        $this->assertSame(150.5, $r->valorTotal);
        $this->assertTrue($r->completa);
    }
}
