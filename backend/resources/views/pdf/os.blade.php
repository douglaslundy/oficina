<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #1a1a1a; padding: 24px; }
  .header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 24px; padding-bottom: 16px; border-bottom: 2px solid #f5a623; }
  .logo { font-size: 22px; font-weight: 900; color: #f5a623; }
  .empresa { font-size: 11px; color: #555; margin-top: 4px; }
  .os-number { font-size: 28px; font-weight: 900; color: #0e0f11; }
  .os-status { font-size: 12px; color: #555; margin-top: 4px; }
  .section { margin-bottom: 20px; }
  .section-title { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: #888; margin-bottom: 8px; }
  .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
  .info-item label { font-size: 10px; color: #888; display: block; margin-bottom: 2px; }
  .info-item span { font-size: 12px; color: #1a1a1a; font-weight: 500; }
  table { width: 100%; border-collapse: collapse; margin-top: 8px; }
  th { background: #f5f5f5; text-align: left; padding: 8px 10px; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: #555; border-bottom: 1px solid #ddd; }
  td { padding: 8px 10px; font-size: 11px; border-bottom: 1px solid #eee; }
  .tipo-peca { background: rgba(245,166,35,0.1); color: #b5790e; font-size: 10px; font-weight: 700; padding: 2px 6px; border-radius: 4px; }
  .tipo-servico { background: rgba(30,136,229,0.1); color: #1465a8; font-size: 10px; font-weight: 700; padding: 2px 6px; border-radius: 4px; }
  .totals { margin-top: 16px; }
  .total-row { display: flex; justify-content: space-between; padding: 4px 0; font-size: 12px; color: #555; }
  .total-final { display: flex; justify-content: space-between; padding: 10px 0; font-size: 16px; font-weight: 900; color: #0e0f11; border-top: 2px solid #0e0f11; margin-top: 8px; }
  .badge { display: inline-block; padding: 3px 10px; border-radius: 99px; font-size: 10px; font-weight: 700; text-transform: uppercase; }
  .badge-green  { background: rgba(67,160,71,0.15); color: #2e7d32; }
  .badge-amber  { background: rgba(245,166,35,0.15); color: #b5790e; }
  .badge-red    { background: rgba(229,57,53,0.15); color: #c62828; }
  .badge-blue   { background: rgba(30,136,229,0.15); color: #1465a8; }
  .footer { margin-top: 32px; padding-top: 16px; border-top: 1px solid #ddd; font-size: 10px; color: #888; text-align: center; }
</style>
</head>
<body>

<div class="header">
  <div>
    <div class="logo">MecânicaPro</div>
    <div class="empresa">{{ $empresa['nome_fantasia'] ?? $empresa['razao_social'] ?? 'Oficina Mecânica' }}</div>
    @if(!empty($empresa['cnpj']))
    <div class="empresa">CNPJ: {{ $empresa['cnpj'] }}</div>
    @endif
    @if(!empty($empresa['cidade']))
    <div class="empresa">{{ $empresa['cidade'] }} — {{ $empresa['uf'] }}</div>
    @endif
  </div>
  <div style="text-align: right;">
    <div class="os-number">OS #{{ $os->numero }}</div>
    <div class="os-status">
      @php
        $statusLabels = ['ABERTA' => 'Aberta', 'EM_ANDAMENTO' => 'Em Andamento', 'AGUARDANDO_PECAS' => 'Aguardando Peças', 'CONCLUIDA' => 'Concluída', 'CANCELADA' => 'Cancelada'];
        $statusClass  = ['ABERTA' => 'badge-blue', 'EM_ANDAMENTO' => 'badge-amber', 'AGUARDANDO_PECAS' => 'badge-amber', 'CONCLUIDA' => 'badge-green', 'CANCELADA' => 'badge-red'];
      @endphp
      <span class="badge {{ $statusClass[$os->status] ?? 'badge-blue' }}">{{ $statusLabels[$os->status] ?? $os->status }}</span>
    </div>
    <div class="os-status" style="margin-top:8px;">Emitido em: {{ now()->format('d/m/Y H:i') }}</div>
  </div>
</div>

<div class="section">
  <div class="section-title">Dados do Cliente e Veículo</div>
  <div class="info-grid">
    <div class="info-item"><label>Cliente</label><span>{{ $os->cliente->nome ?? 'N/A' }}</span></div>
    <div class="info-item"><label>Telefone</label><span>{{ $os->cliente->telefone ?? '-' }}</span></div>
    <div class="info-item"><label>Veículo</label><span>{{ $os->veiculo_descricao ?? ($os->cliente->veiculo_modelo ?? '-') }}</span></div>
    <div class="info-item"><label>Placa</label><span>{{ $os->veiculo_placa ?? ($os->cliente->veiculo_placa ?? '-') }}</span></div>
    @if($os->mecanicoResponsavel)
    <div class="info-item"><label>Mecânico</label><span>{{ $os->mecanicoResponsavel->nome }}</span></div>
    @endif
    @if($os->prazo_entrega)
    <div class="info-item"><label>Prazo de entrega</label><span>{{ $os->prazo_entrega->format('d/m/Y') }}</span></div>
    @endif
  </div>
  @if($os->problema_relatado)
  <div style="margin-top: 10px; padding: 10px; background: #f9f9f9; border-radius: 6px; font-size: 11px; color: #333;">
    <label style="font-size:10px; color:#888; font-weight:700; text-transform:uppercase; letter-spacing:0.05em;">Problema relatado</label>
    <p style="margin-top: 4px;">{{ $os->problema_relatado }}</p>
  </div>
  @endif
</div>

<div class="section">
  <div class="section-title">Serviços e Peças</div>
  <table>
    <thead>
      <tr>
        <th>Tipo</th>
        <th>Descrição</th>
        <th style="text-align:right">Qtd</th>
        <th style="text-align:right">Valor Unit.</th>
        <th style="text-align:right">Total</th>
      </tr>
    </thead>
    <tbody>
      @foreach($os->itens as $item)
      <tr>
        <td><span class="{{ $item->tipo === 'PECA' ? 'tipo-peca' : 'tipo-servico' }}">{{ $item->tipo === 'PECA' ? 'Peça' : 'Serviço' }}</span></td>
        <td>{{ $item->descricao }}</td>
        <td style="text-align:right">{{ number_format($item->quantidade, 0, ',', '.') }}</td>
        <td style="text-align:right">R$ {{ number_format($item->valor_unitario, 2, ',', '.') }}</td>
        <td style="text-align:right">R$ {{ number_format($item->valor_total, 2, ',', '.') }}</td>
      </tr>
      @endforeach
    </tbody>
  </table>

  <div class="totals">
    <div class="total-row"><span>Subtotal serviços</span><span>R$ {{ number_format($os->itens->where('tipo','SERVICO')->sum('valor_total'), 2, ',', '.') }}</span></div>
    <div class="total-row"><span>Subtotal peças</span><span>R$ {{ number_format($os->itens->where('tipo','PECA')->sum('valor_total'), 2, ',', '.') }}</span></div>
    <div class="total-final"><span>TOTAL</span><span>R$ {{ number_format($os->valor_total, 2, ',', '.') }}</span></div>
    <div class="total-row" style="margin-top:8px;"><span>Valor pago</span><span style="color:#2e7d32;">R$ {{ number_format($os->valor_pago, 2, ',', '.') }}</span></div>
    @if($os->saldo_devedor > 0)
    <div class="total-row"><span style="color:#c62828;font-weight:700;">Saldo devedor</span><span style="color:#c62828;font-weight:700;">R$ {{ number_format($os->saldo_devedor, 2, ',', '.') }}</span></div>
    @endif
  </div>

  @if($os->forma_pagamento)
  <p style="margin-top:12px; font-size:11px; color:#555;">Forma de pagamento: <strong>{{ $os->forma_pagamento }}</strong></p>
  @endif
</div>

<div class="footer">
  Documento gerado em {{ now()->format('d/m/Y \à\s H:i') }} — MecânicaPro Sistema de Gestão para Oficinas
</div>

</body>
</html>
