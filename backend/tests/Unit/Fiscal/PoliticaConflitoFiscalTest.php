<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Services\Fiscal\PoliticaConflitoFiscal;
use PHPUnit\Framework\TestCase;

class PoliticaConflitoFiscalTest extends TestCase
{
    public function test_campo_vazio_e_preenchido_com_o_valor_do_xml(): void
    {
        $this->assertSame(PoliticaConflitoFiscal::PREENCHER, PoliticaConflitoFiscal::decidir(null, '87083090'));
        $this->assertSame(PoliticaConflitoFiscal::PREENCHER, PoliticaConflitoFiscal::decidir('', '87083090'));
    }

    public function test_valores_iguais_nao_fazem_nada(): void
    {
        $this->assertSame(PoliticaConflitoFiscal::NADA, PoliticaConflitoFiscal::decidir('87083090', '87083090'));
        $this->assertSame(PoliticaConflitoFiscal::NADA, PoliticaConflitoFiscal::decidir(0, '0'));
        $this->assertSame(PoliticaConflitoFiscal::NADA, PoliticaConflitoFiscal::decidir('ST', 'ST'));
    }

    public function test_valores_diferentes_geram_divergencia(): void
    {
        $this->assertSame(PoliticaConflitoFiscal::DIVERGENCIA, PoliticaConflitoFiscal::decidir('87083090', '84212300'));
        $this->assertSame(PoliticaConflitoFiscal::DIVERGENCIA, PoliticaConflitoFiscal::decidir('NORMAL', 'ST'));
        $this->assertSame(PoliticaConflitoFiscal::DIVERGENCIA, PoliticaConflitoFiscal::decidir(0, 1));
    }

    public function test_xml_sem_valor_nunca_apaga_o_cadastro(): void
    {
        $this->assertSame(PoliticaConflitoFiscal::NADA, PoliticaConflitoFiscal::decidir('87083090', null));
        $this->assertSame(PoliticaConflitoFiscal::NADA, PoliticaConflitoFiscal::decidir('87083090', ''));
        $this->assertSame(PoliticaConflitoFiscal::NADA, PoliticaConflitoFiscal::decidir(null, null));
    }

    public function test_origem_zero_e_valor_valido_e_nao_conta_como_vazio(): void
    {
        // 0 é origem válida (mercadoria nacional). Se caísse em empty(), o
        // produto com origem 0 seria tratado como sem origem para sempre.
        $this->assertSame(PoliticaConflitoFiscal::DIVERGENCIA, PoliticaConflitoFiscal::decidir(0, 2));
        $this->assertSame(PoliticaConflitoFiscal::NADA, PoliticaConflitoFiscal::decidir(0, null));
    }
}
