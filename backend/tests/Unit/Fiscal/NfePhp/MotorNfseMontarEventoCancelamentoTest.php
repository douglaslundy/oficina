<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal\NfePhp;

use App\Services\Fiscal\NfePhp\MotorNfse;
use Tests\TestCase;

/**
 * O pedido de cancelamento (evento 101101) identifica a nota por `chNFSe`,
 * que na API nacional são os 50 dígitos da chave. O que guardamos em
 * `notas_fiscais.chave_acesso` é o `Id` do infNFSe ("NFS" + 50 dígitos), o
 * mesmo defeito já corrigido em consultar() (2026-09-20) — mandar o prefixo
 * faria o cancelamento ser recusado.
 */
class MotorNfseMontarEventoCancelamentoTest extends TestCase
{
    private const CHAVE_50 = '31305072250388509000121000000000000126090586495160';

    private function montar(string $referencia, string $ambiente = 'PRODUCAO', string $motivo = 'Erro na emissao da nota'): object
    {
        $m = new \ReflectionMethod(MotorNfse::class, 'montarEventoCancelamento');
        $m->setAccessible(true);

        return $m->invoke(new MotorNfse(), $referencia, $motivo, $ambiente, '50388509000121');
    }

    public function test_chave_com_prefixo_nfs_vai_com_50_digitos_no_evento(): void
    {
        $evento = $this->montar('NFS' . self::CHAVE_50);

        $this->assertSame(self::CHAVE_50, $evento->infPedReg->chaveNfse);
    }

    public function test_chave_de_50_digitos_segue_intacta(): void
    {
        $evento = $this->montar(self::CHAVE_50);

        $this->assertSame(self::CHAVE_50, $evento->infPedReg->chaveNfse);
    }

    public function test_evento_e_de_cancelamento_com_ambiente_e_motivo_corretos(): void
    {
        $prod = $this->montar(self::CHAVE_50, 'PRODUCAO', 'Erro na emissao da nota');
        $homo = $this->montar(self::CHAVE_50, 'HOMOLOGACAO', 'Servico nao prestado ao cliente');

        $this->assertSame('101101', (string) $prod->infPedReg->tipoEvento);
        $this->assertSame(1, (int) $prod->infPedReg->tipoAmbiente);
        $this->assertSame(2, (int) $homo->infPedReg->tipoAmbiente);
        $this->assertSame('Erro na emissao da nota', $prod->infPedReg->e101101->motivo);
        $this->assertSame('1', (string) $prod->infPedReg->e101101->codigoMotivo);
        $this->assertSame('2', (string) $homo->infPedReg->e101101->codigoMotivo);
        $this->assertSame('50388509000121', $prod->infPedReg->cnpjAutor);
    }
}
