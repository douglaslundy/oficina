<?php
declare(strict_types=1);

namespace App\Services\Fiscal\Data;

final class NotaFiscalData
{
    /**
     * @param array<int, array{
     *   produto_id: string, sku: string, descricao: string, unidade: string,
     *   ncm: string, cfop: string, origem: int, tributacao_icms: string,
     *   cst_csosn: string, quantidade: float, valor_unitario: float,
     * }> $itens Só populado quando $modelo === 'NFE'|'NFCE'.
     */
    public function __construct(
        public readonly string $tipo,                  // NFSE (Fase 1)
        public readonly array $tomador,
        public readonly string $descricao,
        public readonly float $valorServicos,
        public readonly float $aliquotaIss,
        public readonly bool $issRetido,
        public readonly string $codigoServicoFederal,
        public readonly string $codigoServicoMunicipal,
        public readonly string $naturezaOperacao,
        public readonly string $referenciaExterna,
        public readonly string $modelo = 'NFSE',
        public readonly array $itens = [],
        public readonly string $formaPagamento = '',
        // Finding 4 do fix wave pós-revisão da Etapa C2 (2026-08-11): quando
        // não-null, é o nNF já alocado numa tentativa anterior desta mesma
        // NotaFiscal que foi rejeitada/falhou — MotorNfe::emitir() reusa
        // esse valor em vez de queimar um novo número de
        // proximo_numero_nfe (spec Seção B, "nota rejeitada não queima o
        // número"). Só populado por NfeService::montarNotaData() pra
        // modelo NF-e + provedor NFEPHP (ver lá). Não existe
        // `serieReservada` irmã porque série não é alocada por
        // transação — é um valor de configuração fixo por oficina
        // (Configuracao::serie_nfe), lido direto por MotorNfe::emitir() a
        // cada tentativa; não há nada pra "reservar".
        public readonly ?string $numeroReservado = null,
        // Configuracao.regime_tributario (texto livre) — usado por
        // SpedyProvider::montarPayloadNfe() via CrtResolver pra decidir se um
        // item manda `cst` ou `csosn` (a Spedy separa os dois campos, ao
        // contrário do CST/CSOSN unificado que o resto do sistema usa em
        // cst_csosn). Só populado por NfeService::montarNotaData() quando
        // $modelo é NFE/NFCE (mesma condição de $itens).
        public readonly string $regimeTributario = '',
    ) {}
}
