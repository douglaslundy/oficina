<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal\NfePhp;

use App\Services\Fiscal\NfePhp\MotorNfse;
use Tests\TestCase;

/**
 * Testa MotorNfse::classificarMotivoCancelamento() isoladamente — método
 * puro (sem I/O) que infere o cMotivo (Tabela TSCodJustCanc) a partir do
 * texto livre de motivo, em vez do '9' hardcoded anterior.
 *
 * classificarMotivoCancelamento() é private; usamos ReflectionMethod,
 * mesmo padrão de MotorNfseConsultarMappingTest.php.
 */
class MotorNfseClassificarMotivoTest extends TestCase
{
    private function classificar(string $motivo): string
    {
        $motor = new MotorNfse();
        $m = new \ReflectionMethod(MotorNfse::class, 'classificarMotivoCancelamento');
        $m->setAccessible(true);

        return $m->invoke($motor, $motivo);
    }

    public function test_texto_com_erro_classifica_como_1(): void
    {
        $this->assertSame('1', $this->classificar('Erro na emissão, valor errado.'));
        $this->assertSame('1', $this->classificar('Emiti por engano.'));
    }

    public function test_texto_com_servico_nao_prestado_classifica_como_2(): void
    {
        $this->assertSame('2', $this->classificar('Serviço não prestado, cliente desistiu.'));
        $this->assertSame('2', $this->classificar('servico nao realizado'));
    }

    public function test_texto_sem_palavra_chave_classifica_como_9_outros(): void
    {
        $this->assertSame('9', $this->classificar('Cliente pediu cancelamento.'));
        $this->assertSame('9', $this->classificar(''));
    }

    public function test_deteccao_e_insensivel_a_acentuacao_e_caixa(): void
    {
        $this->assertSame('1', $this->classificar('ERRO NA EMISSAO'));
        $this->assertSame('2', $this->classificar('SERVIÇO NÃO PRESTADO'));
    }
}
