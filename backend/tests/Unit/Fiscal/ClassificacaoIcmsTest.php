<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Services\Fiscal\ClassificacaoIcms;
use PHPUnit\Framework\TestCase;

class ClassificacaoIcmsTest extends TestCase
{
    public function test_cst_de_substituicao_tributaria(): void
    {
        foreach (['10', '30', '60', '70'] as $cst) {
            $this->assertSame(ClassificacaoIcms::ST, ClassificacaoIcms::derivar($cst, null), "CST {$cst}");
        }
    }

    public function test_cst_normal(): void
    {
        foreach (['00', '20', '40', '41', '50', '51', '90'] as $cst) {
            $this->assertSame(ClassificacaoIcms::NORMAL, ClassificacaoIcms::derivar($cst, null), "CST {$cst}");
        }
    }

    public function test_csosn_de_substituicao_tributaria(): void
    {
        foreach (['201', '202', '203', '500'] as $csosn) {
            $this->assertSame(ClassificacaoIcms::ST, ClassificacaoIcms::derivar(null, $csosn), "CSOSN {$csosn}");
        }
    }

    public function test_csosn_normal(): void
    {
        foreach (['101', '102', '103', '300', '400', '900'] as $csosn) {
            $this->assertSame(ClassificacaoIcms::NORMAL, ClassificacaoIcms::derivar(null, $csosn), "CSOSN {$csosn}");
        }
    }

    /** CST 12 foi criado pelo Ajuste SINIEF 39/2023 e revogado pelo 20/2024. */
    public function test_cst_revogado_nao_e_assumido_como_normal(): void
    {
        $this->assertNull(ClassificacaoIcms::derivar('12', null));
        $this->assertNull(ClassificacaoIcms::derivar('52', null));
        $this->assertNull(ClassificacaoIcms::derivar('72', null));
    }

    public function test_codigo_desconhecido_devolve_null(): void
    {
        $this->assertNull(ClassificacaoIcms::derivar('99', null));
        $this->assertNull(ClassificacaoIcms::derivar(null, '777'));
        $this->assertNull(ClassificacaoIcms::derivar(null, null));
    }

    public function test_normaliza_codigo_com_espaco_e_sem_zero_a_esquerda(): void
    {
        $this->assertSame(ClassificacaoIcms::NORMAL, ClassificacaoIcms::derivar(' 0 ', null));
        $this->assertSame(ClassificacaoIcms::ST, ClassificacaoIcms::derivar(' 60 ', null));
    }

    public function test_csosn_tem_precedencia_quando_ambos_vem_preenchidos(): void
    {
        $this->assertSame(ClassificacaoIcms::ST, ClassificacaoIcms::derivar('00', '500'));
    }
}
