<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #1a1a1a; padding: 24px; }
  .header { border-bottom: 2px solid #f5a623; padding-bottom: 16px; margin-bottom: 24px; display: flex; justify-content: space-between; }
  .logo { font-size: 20px; font-weight: 900; color: #f5a623; }
  .nf-number { font-size: 24px; font-weight: 900; text-align: right; }
  .section { margin-bottom: 20px; }
  .section-title { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: #888; margin-bottom: 10px; padding-bottom: 4px; border-bottom: 1px solid #eee; }
  .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
  .info-item label { font-size: 10px; color: #888; display: block; }
  .info-item span { font-size: 12px; font-weight: 500; }
  table { width: 100%; border-collapse: collapse; }
  th { background: #f5f5f5; padding: 8px; text-align: left; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid #ddd; }
  td { padding: 8px; font-size: 11px; border-bottom: 1px solid #eee; }
  .total-section { margin-top: 16px; }
  .total-row { display: flex; justify-content: space-between; padding: 4px 0; font-size: 12px; color: #555; }
  .grand-total { display: flex; justify-content: space-between; padding: 10px 0; font-size: 16px; font-weight: 900; border-top: 2px solid #0e0f11; margin-top: 8px; }
  .footer { margin-top: 32px; padding-top: 12px; border-top: 1px solid #ddd; font-size: 10px; color: #888; text-align: center; }
  .chave { font-size: 9px; word-break: break-all; color: #666; margin-top: 8px; padding: 6px; background: #f5f5f5; border-radius: 4px; }
</style>
</head>
<body>

<div class="header">
  <div>
    <div class="logo">MecânicaPro</div>
    <div style="font-size:11px; color:#555; margin-top:4px;">{{ $empresa['nome_fantasia'] ?? $empresa['razao_social'] ?? '' }}</div>
    @if(!empty($empresa['cnpj']))<div style="font-size:11px; color:#555;">CNPJ: {{ $empresa['cnpj'] }}</div>@endif
    <div style="font-size:11px; color:#555;">{{ $empresa['cidade'] ?? '' }}{{ !empty($empresa['uf']) ? ' — ' . $empresa['uf'] : '' }}</div>
    <div style="font-size:11px; color:#555; margin-top:4px;">Regime: {{ $empresa['regime_tributario'] ?? '-' }}</div>
  </div>
  <div style="text-align:right;">
    <div class="nf-number">{{ $nota->modelo }} Nº {{ $nota->numero ?? 'RASCUNHO' }}</div>
    <div style="font-size:11px; color:#555; margin-top:4px;">Série: {{ $nota->serie }}</div>
    @if($nota->emitido_em)<div style="font-size:11px; color:#555;">Emitida: {{ $nota->emitido_em->format('d/m/Y H:i') }}</div>@endif
  </div>
</div>

<div class="section">
  <div class="section-title">Destinatário</div>
  <div class="info-grid">
    <div class="info-item"><label>Nome</label><span>{{ $nota->cliente->nome ?? 'N/A' }}</span></div>
    <div class="info-item"><label>CPF/CNPJ</label><span>{{ $nota->cliente->cpf_cnpj ?? '-' }}</span></div>
    <div class="info-item"><label>Cidade</label><span>{{ ($nota->cliente->cidade ?? '-') . ' — ' . ($nota->cliente->uf ?? '') }}</span></div>
    <div class="info-item"><label>Natureza da operação</label><span>{{ $nota->natureza_operacao ?? '-' }}</span></div>
  </div>
</div>

<div class="section">
  <div class="section-title">Discriminação dos Serviços</div>
  <div style="padding: 10px; background: #f9f9f9; border-radius: 6px; font-size: 11px; line-height: 1.5;">
    {{ $nota->observacoes ?? 'Serviços automotivos prestados conforme acordado.' }}
  </div>
</div>

<div class="section">
  <div class="section-title">Valores</div>
  <div class="total-section">
    <div class="total-row"><span>Subtotal</span><span>R$ {{ number_format($nota->subtotal ?? 0, 2, ',', '.') }}</span></div>
    @if($nota->desconto > 0)
    <div class="total-row"><span>Desconto</span><span>- R$ {{ number_format($nota->desconto, 2, ',', '.') }}</span></div>
    @endif
    <div class="total-row"><span>ISS ({{ $nota->aliquota_iss }}%)</span><span>R$ {{ number_format($nota->valor_iss ?? 0, 2, ',', '.') }}</span></div>
    <div class="grand-total"><span>TOTAL</span><span>R$ {{ number_format($nota->valor_total ?? 0, 2, ',', '.') }}</span></div>
    @if($nota->forma_pagamento)
    <p style="margin-top:10px; font-size:11px; color:#555;">Forma de pagamento: <strong>{{ $nota->forma_pagamento }}</strong></p>
    @endif
  </div>
</div>

@if($nota->chave_acesso)
<div class="section">
  <div class="section-title">Chave de Acesso / Protocolo</div>
  <div class="chave">{{ $nota->chave_acesso }}</div>
  @if($nota->protocolo)<p style="font-size:10px; color:#888; margin-top:4px;">Protocolo: {{ $nota->protocolo }}</p>@endif
</div>
@endif

<div class="footer">
  Documento gerado em {{ now()->format('d/m/Y \à\s H:i') }} — MecânicaPro Sistema de Gestão para Oficinas
</div>

</body>
</html>
