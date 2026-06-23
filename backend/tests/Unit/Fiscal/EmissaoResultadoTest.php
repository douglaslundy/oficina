<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Services\Fiscal\Data\EmissaoResultado;
use App\Services\Fiscal\Data\RegistroResultado;
use PHPUnit\Framework\TestCase;

class EmissaoResultadoTest extends TestCase
{
    public function test_autorizada_define_status_e_dados(): void
    {
        $r = EmissaoResultado::autorizada('CHAVE1', 'PROTO1', '10', '<xml/>', 'http://pdf', 'ref-1');
        $this->assertSame('AUTORIZADA', $r->status);
        $this->assertSame('CHAVE1', $r->chave);
        $this->assertSame('ref-1', $r->referenciaExterna);
        $this->assertNull($r->mensagemErro);
    }

    public function test_rejeitada_carrega_mensagem(): void
    {
        $r = EmissaoResultado::rejeitada('CNPJ inválido', 'ref-2');
        $this->assertSame('REJEITADA', $r->status);
        $this->assertSame('CNPJ inválido', $r->mensagemErro);
    }

    public function test_registro_ok_e_erro(): void
    {
        $ok = RegistroResultado::ok('emp-123', 'tok-abc');
        $this->assertSame('REGISTRADO', $ok->status);
        $this->assertSame('emp-123', $ok->emissorExternoId);

        $err = RegistroResultado::erro('falhou');
        $this->assertSame('ERRO', $err->status);
        $this->assertSame('falhou', $err->mensagemErro);
    }
}
