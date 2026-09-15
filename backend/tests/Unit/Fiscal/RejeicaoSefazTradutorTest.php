<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Services\Fiscal\RejeicaoSefazTradutor;
use Tests\TestCase;

class RejeicaoSefazTradutorTest extends TestCase
{
    public function test_traduz_cstat_883_gtin_sem_informacao(): void
    {
        $resultado = RejeicaoSefazTradutor::traduzir('cStat=883: Rejeicao: GTIN (cEAN) sem informacao [nItem: 1]');

        $this->assertNotNull($resultado);
        $this->assertStringContainsString('código de barras', $resultado);
    }

    public function test_traduz_cstat_204_nota_ja_autorizada(): void
    {
        $resultado = RejeicaoSefazTradutor::traduzir('cStat=204: Rejeicao: Duplicidade de NF-e');

        $this->assertNotNull($resultado);
        $this->assertStringContainsString('já foi autorizada', $resultado);
    }

    public function test_traduz_cstat_806_icms_st_sem_cest(): void
    {
        $resultado = RejeicaoSefazTradutor::traduzir('cStat=806: Rejeicao: Operacao com ICMS-ST sem informacao do CEST. [nItem: 1]');

        $this->assertNotNull($resultado);
        $this->assertStringContainsString('CEST', $resultado);
    }

    /** @dataProvider codigosMapeadosProvider */
    public function test_traduz_todos_os_codigos_mapeados(string $codigo): void
    {
        $resultado = RejeicaoSefazTradutor::traduzir("cStat={$codigo}: Motivo qualquer");

        $this->assertNotNull($resultado);
    }

    public static function codigosMapeadosProvider(): array
    {
        return [
            ['883'], ['204'], ['215'], ['225'], ['999'], ['217'], ['558'], ['632'], ['806'],
        ];
    }

    public function test_retorna_null_quando_codigo_nao_mapeado(): void
    {
        $this->assertNull(RejeicaoSefazTradutor::traduzir('cStat=110: Uso Denegado'));
    }

    public function test_retorna_null_quando_mensagem_nao_tem_cstat(): void
    {
        $this->assertNull(RejeicaoSefazTradutor::traduzir('Erro de conexão com a SEFAZ'));
    }

    public function test_retorna_null_quando_mensagem_e_null(): void
    {
        $this->assertNull(RejeicaoSefazTradutor::traduzir(null));
    }

    public function test_retorna_null_quando_mensagem_e_vazia(): void
    {
        $this->assertNull(RejeicaoSefazTradutor::traduzir(''));
    }
}
