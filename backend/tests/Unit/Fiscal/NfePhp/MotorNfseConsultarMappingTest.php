<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal\NfePhp;

use App\Services\Fiscal\NfePhp\MotorNfse;
use Nfse\Dto\Nfse\InfNfseData;
use Nfse\Dto\Nfse\NfseData;
use Nfse\Enums\CodigoStatus;
use Tests\TestCase;

/**
 * Testa MotorNfse::mapearResultadoConsulta() isoladamente — o método puro
 * (sem I/O) extraído de consultar() na rodada final de revisão desta etapa.
 *
 * Por que a bateria existe: consultar() original tratava QUALQUER resposta
 * parseada com sucesso como AUTORIZADA, ignorando tanto
 * infNfse->codigoStatus quanto eventos de cancelamento registrados para a
 * chave. Como o cancelamento na NFS-e nacional é um EVENTO separado
 * (101101) — Nfse\Enums\CodigoStatus só tem variantes de "gerada"
 * (100/101/102/103/107), confirmado lendo o enum real do vendor — uma nota
 * cancelada via MotorNfse::cancelar() e depois reconsultada voltaria
 * relatando AUTORIZADA. A regra central desta etapa fiscal é nunca "chutar"
 * um status de sucesso para um caso que não conseguimos classificar com
 * segurança — o fallback tem que ser ERRO, nunca AUTORIZADA.
 *
 * mapearResultadoConsulta() é private; usamos ReflectionMethod para
 * invocá-lo diretamente, seguindo o mesmo padrão já usado neste projeto em
 * tests/Unit/AlertaCondicoesTest.php para métodos privados puros.
 */
class MotorNfseConsultarMappingTest extends TestCase
{
    private function invocarMapeamento(NfseData $resultado, bool $temEventoCancelamento, ?string $referencia)
    {
        $motor = new MotorNfse();
        $m = new \ReflectionMethod(MotorNfse::class, 'mapearResultadoConsulta');
        $m->setAccessible(true);

        return $m->invoke($motor, $resultado, $temEventoCancelamento, $referencia);
    }

    public function test_status_gerada_sem_evento_de_cancelamento_retorna_autorizada(): void
    {
        $nfse = new NfseData([
            'infNfse' => new InfNfseData([
                'id' => 'NFS35503080000000000000000000000000000000000000000',
                'numeroNfse' => '123',
                'codigoStatus' => CodigoStatus::NfseGerada,
            ]),
            'nfseXml' => '<NFSe>...</NFSe>',
        ]);

        $resultado = $this->invocarMapeamento($nfse, false, 'nfse-ref-1');

        $this->assertSame('AUTORIZADA', $resultado->status);
        $this->assertSame('NFS35503080000000000000000000000000000000000000000', $resultado->chave);
        $this->assertSame('123', $resultado->numero);
        $this->assertSame('<NFSe>...</NFSe>', $resultado->xml);
        $this->assertSame('nfse-ref-1', $resultado->referenciaExterna);
        $this->assertNull($resultado->mensagemErro);
    }

    public function test_evento_101101_encontrado_retorna_cancelada_mesmo_com_status_gerada(): void
    {
        // Este é exatamente o cenário do bug original: a NFS-e ainda carrega
        // cStat=100 ("gerada") no seu próprio corpo — o cancelamento não
        // reescreve esse campo — mas existe um evento 101101 registrado
        // para a chave. A prioridade tem que ser do evento, não do cStat.
        $nfse = new NfseData([
            'infNfse' => new InfNfseData([
                'id' => 'NFS35503080000000000000000000000000000000000000000',
                'numeroNfse' => '123',
                'codigoStatus' => CodigoStatus::NfseGerada,
            ]),
            'nfseXml' => '<NFSe>...</NFSe>',
        ]);

        $resultado = $this->invocarMapeamento($nfse, true, 'nfse-ref-2');

        $this->assertSame('CANCELADA', $resultado->status);
        $this->assertSame('nfse-ref-2', $resultado->referenciaExterna);
        // EmissaoResultado::cancelada() não carrega chave/numero/xml — só o
        // status e a referência, igual ao já usado por cancelar().
        $this->assertNull($resultado->chave);
    }

    private function invocarResultadoAposVerificarCancelamento(
        NfseData $resultado,
        ?array $eventosCancelamento,
        ?\Throwable $falhaAoListarEventos,
        ?string $referencia,
    ) {
        $motor = new MotorNfse();
        $m = new \ReflectionMethod(MotorNfse::class, 'resultadoAposVerificarCancelamento');
        $m->setAccessible(true);

        return $m->invoke($motor, $resultado, $eventosCancelamento, $falhaAoListarEventos, $referencia);
    }

    public function test_falha_ao_listar_eventos_retorna_erro_nao_confirma_status_anterior(): void
    {
        // Risco parked desde a Rodada 20/22: a lib vendor não distingue
        // "sem eventos" de "erro real" ao listar eventos 101101 — antes
        // desta correção, uma falha de rede era silenciosamente tratada
        // como "não cancelado" e o cStat "gerada" da nota era reportado
        // como AUTORIZADA sem que ninguém tivesse confirmado que ela não
        // foi cancelada de verdade. Agora a incerteza vira ERRO, nunca um
        // status de sucesso não confirmado.
        $nfse = new NfseData([
            'infNfse' => new InfNfseData([
                'id' => 'NFS35503080000000000000000000000000000000000000000',
                'numeroNfse' => '123',
                'codigoStatus' => CodigoStatus::NfseGerada,
            ]),
            'nfseXml' => '<NFSe>...</NFSe>',
        ]);

        $resultado = $this->invocarResultadoAposVerificarCancelamento(
            $nfse, null, new \RuntimeException('timeout na SEFIN'), 'nfse-ref-4',
        );

        $this->assertSame('ERRO', $resultado->status);
        $this->assertSame('nfse-ref-4', $resultado->referenciaExterna);
        $this->assertStringContainsString('confirmar', $resultado->mensagemErro);
        $this->assertStringContainsString('timeout na SEFIN', $resultado->mensagemErro);
    }

    public function test_sem_falha_e_sem_eventos_delega_pro_mapeamento_normal(): void
    {
        $nfse = new NfseData([
            'infNfse' => new InfNfseData([
                'id' => 'NFS35503080000000000000000000000000000000000000000',
                'numeroNfse' => '123',
                'codigoStatus' => CodigoStatus::NfseGerada,
            ]),
            'nfseXml' => '<NFSe>...</NFSe>',
        ]);

        $resultado = $this->invocarResultadoAposVerificarCancelamento($nfse, [], null, 'nfse-ref-5');

        $this->assertSame('AUTORIZADA', $resultado->status);
    }

    public function test_sem_falha_e_com_eventos_delega_cancelada(): void
    {
        $nfse = new NfseData([
            'infNfse' => new InfNfseData([
                'id' => 'NFS35503080000000000000000000000000000000000000000',
                'numeroNfse' => '123',
                'codigoStatus' => CodigoStatus::NfseGerada,
            ]),
            'nfseXml' => '<NFSe>...</NFSe>',
        ]);

        $resultado = $this->invocarResultadoAposVerificarCancelamento($nfse, [['algum' => 'evento']], null, 'nfse-ref-6');

        $this->assertSame('CANCELADA', $resultado->status);
    }

    public function test_status_nao_reconhecido_retorna_erro_nao_autorizada(): void
    {
        // codigoStatus ausente (null) simula tanto um XML sem cStat quanto
        // um cStat futuro que este enum ainda não conhece (CodigoStatus::
        // tryFrom() devolve null para valores fora de 100/101/102/103/107) —
        // nos dois casos não podemos confiar que é uma nota válida/autorizada.
        $nfse = new NfseData([
            'infNfse' => new InfNfseData([
                'id' => 'NFS35503080000000000000000000000000000000000000000',
                'numeroNfse' => '123',
                'codigoStatus' => null,
            ]),
            'nfseXml' => '<NFSe>...</NFSe>',
        ]);

        $resultado = $this->invocarMapeamento($nfse, false, 'nfse-ref-3');

        $this->assertSame('ERRO', $resultado->status);
        $this->assertSame('nfse-ref-3', $resultado->referenciaExterna);
        $this->assertNotNull($resultado->mensagemErro);
        $this->assertStringContainsString('ausente', $resultado->mensagemErro);
    }
}
