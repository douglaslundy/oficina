<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Services\Fiscal\Data\NotaFiscalData;
use PHPUnit\Framework\TestCase;

class NotaFiscalDataTest extends TestCase
{
    public function test_construcao_nfse_sem_novos_campos_mantem_default(): void
    {
        $nota = new NotaFiscalData(
            tipo: 'NFSE',
            tomador: ['nome' => 'Cliente'],
            descricao: 'Serviço',
            valorServicos: 100.0,
            aliquotaIss: 5.0,
            issRetido: false,
            codigoServicoFederal: '14.01',
            codigoServicoMunicipal: '1401',
            naturezaOperacao: 'Prestação de Serviços',
            referenciaExterna: 'ref-1',
        );

        $this->assertSame('NFSE', $nota->modelo);
        $this->assertSame([], $nota->itens);
    }

    public function test_construcao_nfe_com_itens(): void
    {
        $itens = [[
            'produto_id' => 'prod-1', 'descricao' => 'Filtro de óleo',
            'ncm' => '84212300', 'cfop' => '5102', 'origem' => 0,
            'tributacao_icms' => 'NORMAL', 'cst_csosn' => '102',
            'quantidade' => 2, 'valor_unitario' => 35.0,
        ]];

        $nota = new NotaFiscalData(
            tipo: 'NFSE',
            tomador: ['nome' => 'Cliente'],
            descricao: 'Venda de peças',
            valorServicos: 0.0,
            aliquotaIss: 0.0,
            issRetido: false,
            codigoServicoFederal: '',
            codigoServicoMunicipal: '',
            naturezaOperacao: 'Venda de Mercadoria',
            referenciaExterna: 'ref-2',
            modelo: 'NFE',
            itens: $itens,
        );

        $this->assertSame('NFE', $nota->modelo);
        $this->assertSame($itens, $nota->itens);
    }
}
