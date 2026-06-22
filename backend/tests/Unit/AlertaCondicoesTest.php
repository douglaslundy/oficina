<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AlertaDispatchService;
use App\Services\EntitlementService;
use App\Services\WhatsAppService;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * Testa a lógica pura de casamento de condições do dispatch de alertas.
 * Não toca no banco — instancia o serviço com dependências mockadas e
 * exercita o método privado condicoesCasam via reflection.
 */
class AlertaCondicoesTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function casa(array $condicoes, array $vars): bool
    {
        $svc = new AlertaDispatchService(
            Mockery::mock(WhatsAppService::class),
            Mockery::mock(EntitlementService::class),
        );

        $m = new \ReflectionMethod(AlertaDispatchService::class, 'condicoesCasam');
        $m->setAccessible(true);

        return (bool) $m->invoke($svc, $condicoes, $vars);
    }

    public function test_sem_condicoes_sempre_casa(): void
    {
        $this->assertTrue($this->casa([], ['status' => 'CANCELADA']));
    }

    public function test_status_alvo_casa_com_o_status(): void
    {
        $this->assertTrue($this->casa(['status_alvo' => ['CANCELADA']], ['status' => 'CANCELADA']));
    }

    public function test_status_alvo_nao_casa_com_outro_status(): void
    {
        $this->assertFalse($this->casa(['status_alvo' => ['CANCELADA']], ['status' => 'EM_ANDAMENTO']));
    }

    public function test_status_alvo_com_multiplos_valores(): void
    {
        $cond = ['status_alvo' => ['CANCELADA', 'CONCLUIDA']];
        $this->assertTrue($this->casa($cond, ['status' => 'CONCLUIDA']));
        $this->assertFalse($this->casa($cond, ['status' => 'ABERTA']));
    }

    public function test_lista_vazia_no_campo_ignora_o_filtro(): void
    {
        $this->assertTrue($this->casa(['status_alvo' => []], ['status' => 'QUALQUER']));
    }
}
