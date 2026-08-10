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
 * ->nodeValue num node inexistente (CPF/CNPJ). Achado inicialmente rodando
 * o teste sob PHPUnit, que converteu o E_WARNING resultante numa exceção
 * catchável — mas revisão posterior confirmou que em PHP puro (produção)
 * isso NÃO lança nada: a chave de acesso é montada em silêncio a partir de
 * um CNPJ vazio. MotorNfe::montarNfe() agora tem uma guarda explícita
 * (InvalidArgumentException) pra não depender desse comportamento
 * inconsistente entre ambientes — ver test_lanca_excecao_quando_cnpj_ausente()
 * abaixo e task-3-report.md ("Fix report").
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
        // Corrigido (Task 4): a asserção original era `assertStringNotContainsString('<CST>', $xml)`
        // — testava (corretamente, na época) que o grupo ICMS usa CSOSN em vez de
        // CST pra CRT=1. Ficou imprecisa demais depois que esta task adicionou os
        // grupos PIS/COFINS obrigatórios por item (exigidos pelo XSD independente
        // do CRT — ver test_monta_xml_com_grupos_pis_e_cofins_por_item abaixo),
        // que legitimamente usam <CST> (PIS/COFINS sempre usam CST, mesmo no
        // Simples Nacional — CSOSN é específico de ICMS). Restrita ao grupo ICMS
        // pra preservar a intenção original da asserção.
        preg_match('/<ICMS>.*?<\/ICMS>/', $xml, $blocoIcms);
        $this->assertStringNotContainsString('<CST>', $blocoIcms[0] ?? '');
        $this->assertStringContainsString('<CRT>1</CRT>', $xml);
    }

    /**
     * Task 4 — achado da revisão da Task 3: Make::addTagDet() só inclui
     * <PIS>/<COFINS> quando aPIS[item]/aCOFINS[item] são populados por
     * tagPIS()/tagCOFINS() explícitos (confirmado em Make.php ~743-755); o
     * XSD da NF-e v4.00 exige os dois grupos por item, independente do CRT.
     * CST 49 com base/valor zerados é o padrão pra Simples Nacional (CRT=1,
     * regime real da oficina) — PIS/COFINS é pago via DAS, não calculado
     * por operação.
     */
    public function test_monta_xml_com_grupos_pis_e_cofins_por_item(): void
    {
        $cfg = $this->configuracaoSimplesNacional();

        $motor = new MotorNfe();
        $xml = $motor->montarNfe($this->notaVenda(), $cfg, 'HOMOLOGACAO', 1, 1);

        $this->assertStringContainsString('<PIS>', $xml);
        $this->assertStringContainsString('<PISOutr>', $xml);
        $this->assertStringContainsString('<COFINS>', $xml);
        $this->assertStringContainsString('<COFINSOutr>', $xml);
        // CST 49 aparece duas vezes (PIS e COFINS) além do CSOSN — checa
        // via contagem pra não depender de qual aparece primeiro no XML.
        $this->assertSame(2, substr_count($xml, '<CST>49</CST>'));
        $this->assertStringContainsString('<vPIS>0.00</vPIS>', $xml);
        $this->assertStringContainsString('<vCOFINS>0.00</vCOFINS>', $xml);
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

    /**
     * Guarda contra "chutar" a chave de acesso da NF-e com um CNPJ vazio.
     * Sem essa guarda explícita, em PHP puro (fora do conversor de
     * E_WARNING->exceção do PHPUnit) a Make/sped-nfe monta a chave em
     * silêncio a partir de um CNPJ zero-padded, sem lançar nada e sem
     * popular getErrors() — corrompendo um valor fiscal legalmente
     * significativo sem avisar ninguém.
     */
    public function test_lanca_excecao_quando_cnpj_ausente(): void
    {
        $cfg = new Configuracao([
            'razao_social' => 'Oficina Teste', 'regime_tributario' => 'Simples Nacional',
            // 'cnpj' deliberadamente ausente
            'uf' => 'MG', 'codigo_ibge' => '3132404', 'cidade' => 'Ilicínea',
            'logradouro' => 'Av Central', 'numero' => '100', 'bairro' => 'Centro', 'cep' => '37275000',
            'inscricao_estadual' => '1234567',
        ]);

        $motor = new MotorNfe();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('CNPJ da empresa não configurado');

        $motor->montarNfe($this->notaVenda(), $cfg, 'HOMOLOGACAO', 1, 1);
    }
}
