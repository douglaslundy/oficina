<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Services\Fiscal\CertificadoValidator;
use PHPUnit\Framework\TestCase;

class CertificadoValidatorTest extends TestCase
{
    public function test_pfx_invalido_retorna_erro(): void
    {
        $validator = new CertificadoValidator();
        $resultado = $validator->validar('conteudo-que-nao-e-pfx', 'senha-errada');

        $this->assertFalse($resultado['ok']);
        $this->assertNull($resultado['validade']);
        $this->assertNotNull($resultado['erro']);
    }
}
