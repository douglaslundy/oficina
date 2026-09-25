<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Models\Configuracao;
use App\Models\NotaFiscal;
use App\Models\OrdemServico;
use App\Services\Fiscal\InformacoesComplementaresResolver as R;
use Tests\TestCase;

class InformacoesComplementaresResolverTest extends TestCase
{
    private function cfg(string $regime): Configuracao
    {
        $c = new Configuracao();
        $c->regime_tributario = $regime;
        return $c;
    }

    private function nota(?OrdemServico $os = null, ?string $obs = null): NotaFiscal
    {
        $n = new NotaFiscal();
        $n->observacoes = $obs;
        if ($os) {
            $n->os_id = 'os-1';
            $n->setRelation('ordemServico', $os);
        }
        return $n;
    }

    private function os(): OrdemServico
    {
        $os = new OrdemServico();
        $os->veiculo_placa = 'ABC1D23';
        $os->veiculo_descricao = 'Fiat Fiorino 2019';
        $os->km_atual = 84500;
        $os->setRelation('veiculo', null);
        return $os;
    }

    public function test_simples_com_os_traz_optante_placa_modelo_e_km(): void
    {
        $t = R::montar($this->nota($this->os()), $this->cfg('Simples Nacional'), false, R::LIMITE_NFE);

        $this->assertStringContainsString('optante pelo Simples Nacional', $t);
        $this->assertStringContainsString('Placa: ABC1D23', $t);
        $this->assertStringContainsString('Modelo: Fiat Fiorino 2019', $t);
        $this->assertStringContainsString('KM: 84500', $t);
    }

    public function test_regime_normal_nao_menciona_simples(): void
    {
        $t = R::montar($this->nota($this->os()), $this->cfg('Lucro Presumido'), false, R::LIMITE_NFE);

        $this->assertStringNotContainsString('Simples', $t);
        $this->assertStringContainsString('Placa: ABC1D23', $t);
    }

    public function test_observacoes_so_entram_quando_solicitado(): void
    {
        $n = $this->nota(null, 'Pagamento em 30 dias');

        $this->assertStringContainsString('Pagamento em 30 dias', R::montar($n, $this->cfg('Simples Nacional'), true, R::LIMITE_NFE));
        $this->assertStringNotContainsString('Pagamento', R::montar($n, $this->cfg('Simples Nacional'), false, R::LIMITE_NFE));
    }

    public function test_sem_nada_a_dizer_retorna_null(): void
    {
        $this->assertNull(R::montar($this->nota(), $this->cfg('Lucro Presumido'), false, R::LIMITE_NFE));
    }

    public function test_respeita_o_limite(): void
    {
        $t = R::montar($this->nota($this->os()), $this->cfg('Simples Nacional'), false, 40);

        $this->assertSame(40, mb_strlen($t));
    }
}
