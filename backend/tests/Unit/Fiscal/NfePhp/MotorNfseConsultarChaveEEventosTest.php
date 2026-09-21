<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal\NfePhp;

use App\Services\Fiscal\NfePhp\MotorNfse;
use Nfse\Http\Exceptions\NfseApiException;
use Tests\TestCase;

/**
 * Dois defeitos reais de MotorNfse::consultar(), achados em 2026-09-20 ao
 * conferir a 1ª NFS-e de produção da stuntmotos contra o SEFIN Nacional:
 *
 * 1. A chave guardada em `notas_fiscais.chave_acesso` é o `Id` do infNFSe
 *    ("NFS" + 50 dígitos = 53 chars). `GET /nfse/{chave}` só aceita os 50
 *    dígitos — com o prefixo devolve "não encontrada" (confirmado ao vivo:
 *    a mesma nota foi achada só sem o "NFS").
 * 2. A checagem de cancelamento chamava `/eventos/101101`, rota que NÃO
 *    existe (a especificação oficial é `/eventos/{tipo}/{numSeq}`); o 404 era
 *    uma página HTML do IIS, tratado como falha → toda consulta de nota
 *    autorizada voltava ERRO. A rota certa devolve, quando não há evento,
 *    404 com um JSON de envelope da API ("Nenhum evento encontrado").
 *
 * Métodos privados puros, invocados por ReflectionMethod (mesmo padrão de
 * MotorNfseConsultarMappingTest).
 */
class MotorNfseConsultarChaveEEventosTest extends TestCase
{
    private const CHAVE_50 = '31305072250388509000121000000000000126090586495160';

    private function invocar(string $metodo, mixed ...$args): mixed
    {
        $m = new \ReflectionMethod(MotorNfse::class, $metodo);
        $m->setAccessible(true);

        return $m->invoke(new MotorNfse(), ...$args);
    }

    // --- chave ---------------------------------------------------------

    public function test_chave_com_prefixo_nfs_vira_os_50_digitos(): void
    {
        $this->assertSame(self::CHAVE_50, $this->invocar('chaveNfse50', 'NFS' . self::CHAVE_50));
    }

    public function test_chave_que_ja_tem_50_digitos_fica_como_esta(): void
    {
        $this->assertSame(self::CHAVE_50, $this->invocar('chaveNfse50', self::CHAVE_50));
    }

    public function test_referencia_que_nao_e_chave_nao_e_alterada(): void
    {
        // Só o prefixo de uma chave de verdade é removido; qualquer outra
        // coisa passa intacta pra API responder o que tiver de responder.
        $this->assertSame('nfse-ref-4', $this->invocar('chaveNfse50', 'nfse-ref-4'));
        $this->assertSame('NFS123', $this->invocar('chaveNfse50', 'NFS123'));
    }

    // --- evento de cancelamento ---------------------------------------

    public function test_evento_encontrado_significa_nota_cancelada(): void
    {
        $this->assertTrue($this->invocar('existeEventoCancelamento', fn () => new \stdClass()));
    }

    public function test_404_com_json_da_api_significa_sem_evento(): void
    {
        $corpo = '{ "dataHoraProcessamento": "2026-09-20T23:42:49-03:00", "tipoAmbiente": 1, "versaoAplicativo": "SefinNacional_1.6.0" }';

        $existe = $this->invocar('existeEventoCancelamento', function () use ($corpo) {
            throw NfseApiException::requestError('404 Not Found', 404, $corpo);
        });

        $this->assertFalse($existe);
    }

    public function test_404_com_html_do_iis_e_rota_inexistente_nao_e_sem_evento(): void
    {
        // Nunca supor "sem evento" quando o servidor nem reconheceu a rota.
        $this->expectException(NfseApiException::class);

        $this->invocar('existeEventoCancelamento', function () {
            throw NfseApiException::requestError('404', 404, '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN"><html>404 - File or directory not found.</html>');
        });
    }

    public function test_404_sem_corpo_nao_e_sem_evento(): void
    {
        $this->expectException(NfseApiException::class);

        $this->invocar('existeEventoCancelamento', function () {
            throw NfseApiException::requestError('404', 404, null);
        });
    }

    public function test_erro_de_servidor_continua_sendo_incerteza(): void
    {
        $this->expectException(NfseApiException::class);

        $this->invocar('existeEventoCancelamento', function () {
            throw NfseApiException::requestError('500', 500, '{"tipoAmbiente":1}');
        });
    }
}
