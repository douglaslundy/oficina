<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Services\Fiscal\ValidadorCamposFiscais;
use PHPUnit\Framework\TestCase;

class ValidadorCamposFiscaisTest extends TestCase
{
    public function test_ncm_valido(): void
    {
        $this->assertSame('87083090', ValidadorCamposFiscais::ncm('87083090'));
        $this->assertSame('87083090', ValidadorCamposFiscais::ncm('8708.30.90'));
    }

    public function test_ncm_invalido_devolve_null(): void
    {
        $this->assertNull(ValidadorCamposFiscais::ncm('8708309'));    // 7 dígitos
        $this->assertNull(ValidadorCamposFiscais::ncm('870830901'));  // 9 dígitos
        $this->assertNull(ValidadorCamposFiscais::ncm(''));
        $this->assertNull(ValidadorCamposFiscais::ncm(null));
        $this->assertNull(ValidadorCamposFiscais::ncm('ABCDEFGH'));
    }

    public function test_ncm_rejeita_lixo_apos_formatacao(): void
    {
        // Rejeita se houver caracteres não-dígito após remover separadores conhecidos
        $this->assertNull(ValidadorCamposFiscais::ncm('87083090abc'));
        $this->assertNull(ValidadorCamposFiscais::ncm('8708-30-90xyz'));
        $this->assertNull(ValidadorCamposFiscais::ncm('87083090 def'));
    }

    public function test_cest_valido(): void
    {
        $this->assertSame('0100100', ValidadorCamposFiscais::cest('0100100'));
        $this->assertSame('0100100', ValidadorCamposFiscais::cest('01.001.00'));
    }

    public function test_cest_invalido_devolve_null(): void
    {
        $this->assertNull(ValidadorCamposFiscais::cest('010010'));   // 6 dígitos
        $this->assertNull(ValidadorCamposFiscais::cest('01001000')); // 8 dígitos
        $this->assertNull(ValidadorCamposFiscais::cest(null));
    }

    public function test_cest_rejeita_lixo_apos_formatacao(): void
    {
        // Rejeita se houver caracteres não-dígito após remover separadores conhecidos
        $this->assertNull(ValidadorCamposFiscais::cest('0100100xyz'));
        $this->assertNull(ValidadorCamposFiscais::cest('01-001-00abc'));
        $this->assertNull(ValidadorCamposFiscais::cest('0100100 def'));
    }

    public function test_origem_valida(): void
    {
        $this->assertSame(0, ValidadorCamposFiscais::origem('0'));
        $this->assertSame(8, ValidadorCamposFiscais::origem('8'));
        $this->assertSame(3, ValidadorCamposFiscais::origem(3));
    }

    public function test_origem_invalida_devolve_null(): void
    {
        $this->assertNull(ValidadorCamposFiscais::origem('9'));
        $this->assertNull(ValidadorCamposFiscais::origem('-1'));
        $this->assertNull(ValidadorCamposFiscais::origem('x'));
        $this->assertNull(ValidadorCamposFiscais::origem(null));
    }
}
