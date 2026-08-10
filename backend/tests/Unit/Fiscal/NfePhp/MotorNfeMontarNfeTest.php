<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal\NfePhp;

use App\Models\Configuracao;
use App\Services\Fiscal\Data\NotaFiscalData;
use App\Services\Fiscal\NfePhp\MotorNfe;
use Tests\TestCase;

/**
 * Testa MotorNfe::montarNfe() isoladamente — sem I/O, sem rede, sem
 * certificado (a Make do sped-nfe só monta o DOM em memória).
 *
 * Corrigido: o brief original usava PHPUnit\Framework\TestCase puro. Isso
 * quebra porque montarNfe() chama os helpers now() e config() do Laravel
 * (facades que exigem o container da aplicação de pé) — exatamente o mesmo
 * problema que MotorNfseMontarDpsTest.php já documentou e resolveu trocando
 * para Tests\TestCase (comprovadamente roda neste ambiente sem precisar de
 * Postgres).
 *
 * Corrigido: o brief não incluía 'cnpj' na Configuracao de teste. Sem CNPJ
 * (nem CPF) no emitente, Make::render() -> checkNFeKey() acessa
 * ->nodeValue num node inexistente (CPF/CNPJ) e quebra com \Error fatal,
 * não capturado pelo catch(\Exception) interno da Make — achado rodando o
 * teste, não hipotético (ver task-3-report.md).
 */
class MotorNfeMontarNfeTest extends TestCase
{
    private function notaVenda(): NotaFiscalData
    {
        return new NotaFiscalData(
            tipo: 'NFSE',
            tomador: [
                'nome' => 'Cliente Teste', 'cpf_cnpj' => '12345678000199',
                'uf' => 'MG', 'cidade' => 'Ilicínea', 'codigo_ibge' => '3132404',
                'logradouro' => 'Rua A', 'numero' => '10', 'bairro' => 'Centro', 'cep' => '37275000',
            ],
            descricao: 'Venda de peças',
            valorServicos: 0.0,
            aliquotaIss: 0.0,
            issRetido: false,
            codigoServicoFederal: '',
            codigoServicoMunicipal: '',
            naturezaOperacao: 'Venda de Mercadoria',
            referenciaExterna: 'nfe-1',
            modelo: 'NFE',
            itens: [[
                'produto_id' => 'prod-1', 'descricao' => 'Filtro de óleo',
                'ncm' => '84212300', 'cfop' => '5102', 'origem' => 0,
                'tributacao_icms' => 'NORMAL', 'cst_csosn' => '102',
                'quantidade' => 2, 'valor_unitario' => 35.50,
            ]],
        );
    }

    private function configuracaoSimplesNacional(): Configuracao
    {
        return new Configuracao([
            'razao_social' => 'Oficina Teste', 'regime_tributario' => 'Simples Nacional',
            'cnpj' => '11222333000181',
            'uf' => 'MG', 'codigo_ibge' => '3132404', 'cidade' => 'Ilicínea',
            'logradouro' => 'Av Central', 'numero' => '100', 'bairro' => 'Centro', 'cep' => '37275000',
            'inscricao_estadual' => '1234567',
        ]);
    }

    public function test_monta_xml_para_simples_nacional_sem_bloco_ibscbs(): void
    {
        $cfg = $this->configuracaoSimplesNacional();

        $motor = new MotorNfe();
        $xml = $motor->montarNfe($this->notaVenda(), $cfg, 'HOMOLOGACAO', 1, 1);

        $this->assertStringContainsString('<CSOSN>102</CSOSN>', $xml);
        $this->assertStringNotContainsString('<CST>', $xml);
        $this->assertStringContainsString('<CRT>1</CRT>', $xml);
    }

    public function test_uf_diferente_gera_iddest_interestadual(): void
    {
        $cfg = $this->configuracaoSimplesNacional();
        $nota = $this->notaVenda();
        // tomador em SP em vez de MG
        $notaInterestadual = new NotaFiscalData(
            tipo: $nota->tipo, tomador: array_merge($nota->tomador, ['uf' => 'SP']),
            descricao: $nota->descricao, valorServicos: $nota->valorServicos,
            aliquotaIss: $nota->aliquotaIss, issRetido: $nota->issRetido,
            codigoServicoFederal: $nota->codigoServicoFederal, codigoServicoMunicipal: $nota->codigoServicoMunicipal,
            naturezaOperacao: $nota->naturezaOperacao, referenciaExterna: $nota->referenciaExterna,
            modelo: $nota->modelo, itens: $nota->itens,
        );

        $motor = new MotorNfe();
        $xml = $motor->montarNfe($notaInterestadual, $cfg, 'HOMOLOGACAO', 1, 1);

        $this->assertStringContainsString('<idDest>2</idDest>', $xml);
    }
}
