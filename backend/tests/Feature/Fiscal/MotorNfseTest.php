<?php
declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Models\Configuracao;
use App\Services\Fiscal\Data\NotaFiscalData;
use App\Services\Fiscal\NfePhp\MotorNfse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MotorNfseTest extends TestCase
{
    use RefreshDatabase;

    private function configuracaoValida(): Configuracao
    {
        return Configuracao::create([
            'razao_social' => 'Oficina Teste Ltda', 'cnpj' => '12345678000199',
            'codigo_ibge' => '3550308', 'ambiente_fiscal' => 'HOMOLOGACAO',
            'aliquota_iss' => 5.00, 'regime_tributario' => 'Simples Nacional',
            'proximo_numero_nf' => 1, 'estoque_limite_padrao' => 5, 'alertas_email' => false,
            'certificado_pfx_encrypted' => 'placeholder-precisa-de-certificado-real-de-teste',
            'certificado_senha_encrypted' => \Illuminate\Support\Facades\Crypt::encryptString('senha'),
        ]);
    }

    private function notaServico(): NotaFiscalData
    {
        return new NotaFiscalData(
            tipo: 'NFSE',
            tomador: ['nome' => 'Cliente Teste', 'cpf_cnpj' => '12345678909', 'email' => 'c@x.com'],
            descricao: 'Troca de óleo', valorServicos: 150.00, aliquotaIss: 5.0, issRetido: false,
            codigoServicoFederal: '14.01', codigoServicoMunicipal: '1401',
            naturezaOperacao: 'Prestação de Serviços', referenciaExterna: 'nfse-1',
        );
    }

    /**
     * CONFIRMADO nesta sessão (lendo vendor/nfse-nacional/nfse-php/src/Http/Client/SefinClient.php):
     * o cliente HTTP (Guzzle) é criado dentro do construtor de SefinClient a
     * partir de NfseContext, com certificado cliente TLS configurado via
     * CURLOPT_SSLCERT/CURLOPT_SSLCERTPASSWD (mTLS obrigatório da SEFIN
     * Nacional) — não há nenhum ponto de injeção de client HTTP, handler do
     * Guzzle ou client PSR-18 em NfseContext, Nfse ou ContribuinteService.
     * Ou seja, a suposição do brief ("se o pacote permitir client injetado,
     * usar Http::fake() equivalente") não se aplica: não há como interceptar
     * essas chamadas sem sandbox de homologação real ou um certificado A1 de
     * teste válido (mTLS exige um handshake de certificado de cliente real
     * até para bater num servidor fake).
     *
     * Este teste também precisa de Postgres (RefreshDatabase), que não está
     * disponível neste ambiente. Cobertura real e executável de montarDps()
     * (a única parte sem I/O) está em
     * tests/Unit/Fiscal/NfePhp/MotorNfseMontarDpsTest.php.
     */
    public function test_emitir_monta_dps_com_dados_da_configuracao_e_da_nota(): void
    {
        $this->markTestSkipped(
            'Precisa de certificado .pfx de teste válido (mTLS é exigido pela SEFIN Nacional já '.
            'na camada de transporte, dentro de SefinClient — confirmado no vendor) e de acesso de '.
            'rede ao sandbox de homologação — não executável localmente (sem Postgres) nem contra '.
            'a rede real nesta sessão. O pacote nfse-nacional/nfse-php NÃO expõe nenhum ponto de '.
            'injeção de client HTTP (confirmado lendo SefinClient::createHttpClient(), que sempre '.
            'instancia um GuzzleHttp\\Client próprio a partir do NfseContext), então um '.
            'Http::fake() equivalente não é possível aqui; a única forma real de testar esta '.
            'chamada é contra o sandbox de homologação com um certificado A1 válido.',
        );
    }

    /**
     * Idem: precisa de Postgres + certificado real + rede de homologação.
     * Mantido apenas como documentação executável da assinatura esperada.
     */
    public function test_consultar_retorna_erro_quando_biblioteca_nao_encontra_ou_falha(): void
    {
        $this->markTestSkipped(
            'Mesmas limitações do teste de emissão acima — sem Postgres/certificado/rede aqui. '.
            'Nota de implementação confirmada no vendor: Nfse\\Service\\ContribuinteService::consultar() '.
            'engole qualquer NfseApiException internamente e devolve null tanto para "não encontrada" '.
            'quanto para falha de API — MotorNfse::consultar() mapeia esse null para EmissaoResultado::erro().',
        );
    }

    /**
     * Idem. Cancelamento real via evento 101101 — formato confirmado em
     * examples/contribuinte/cancelar.php do próprio pacote (não é mais um
     * placeholder "não implementado" como o brief original previa para o
     * caso de não-confirmação).
     */
    public function test_cancelar_registra_evento_101101(): void
    {
        $this->markTestSkipped(
            'Mesmas limitações — sem Postgres/certificado/rede aqui. MotorNfse::cancelar() já monta '.
            'o payload real do evento 101101 (PedRegEventoData com grupo e101101), confirmado contra '.
            'vendor/nfse-nacional/nfse-php/examples/contribuinte/cancelar.php.',
        );
    }
}
