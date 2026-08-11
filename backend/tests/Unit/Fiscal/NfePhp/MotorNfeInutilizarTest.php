<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal\NfePhp;

use App\Services\Fiscal\NfePhp\MotorNfe;
use PHPUnit\Framework\TestCase;

/**
 * inutilizar() fim-a-fim não é testável sem certificado real (mesma
 * limitação de emitir()/consultar()/cancelar(), documentada nos arquivos de
 * teste correspondentes). Este arquivo cobre o guard local do range
 * (numero_final < numero_inicial) e o caminho "sem Configuracao" — ambos
 * puramente locais, sem I/O nem certificado.
 */
class MotorNfeInutilizarTest extends TestCase
{
    public function test_inutilizar_sem_configuracao_retorna_erro(): void
    {
        $motor = new MotorNfe();
        $resultado = $motor->inutilizar(1, 10, 12, 'Falha no processo antes da transmissão', 'HOMOLOGACAO');

        $this->assertSame('ERRO', $resultado->status);
    }

    /**
     * Guard local (não depende do controller já validar isso) — número
     * final menor que o inicial nunca deveria chegar a gastar uma chamada
     * real à SEFAZ.
     */
    public function test_inutilizar_com_numero_final_menor_que_inicial_retorna_erro_sem_tentar_rede(): void
    {
        $motor = new MotorNfe();
        $resultado = $motor->inutilizar(1, 12, 10, 'Falha no processo antes da transmissão', 'HOMOLOGACAO');

        $this->assertSame('ERRO', $resultado->status);
        $this->assertStringContainsString('final', (string) $resultado->mensagemErro);
    }
}
