<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<style>
  body { font-family: Arial, sans-serif; font-size: 13px; color: #222; margin: 40px; }
  .header { text-align: center; border-bottom: 2px solid #f5a623; padding-bottom: 16px; margin-bottom: 20px; }
  .header h1 { font-size: 22px; margin: 0 0 4px; color: #111; }
  .header p { margin: 2px 0; color: #555; font-size: 12px; }
  .title { font-size: 18px; font-weight: bold; text-align: center; margin: 16px 0; color: #333; letter-spacing: 1px; text-transform: uppercase; }
  table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
  td { padding: 8px 10px; border-bottom: 1px solid #eee; vertical-align: top; }
  td.label { color: #888; font-size: 12px; width: 160px; font-weight: 600; }
  td.value { font-weight: 600; color: #222; }
  .total-box { margin-top: 24px; border: 2px solid #43a047; border-radius: 8px; padding: 16px 20px; text-align: right; }
  .total-box .lbl { color: #888; font-size: 12px; margin: 0 0 4px; }
  .total-box .val { font-size: 28px; font-weight: 800; color: #43a047; margin: 0; font-family: monospace; }
  .saldo-box { margin-top: 12px; border: 1px solid #e53935; border-radius: 8px; padding: 10px 20px; text-align: right; }
  .saldo-box .lbl { color: #888; font-size: 12px; margin: 0 0 2px; }
  .saldo-box .val { font-size: 18px; font-weight: 700; color: #e53935; margin: 0; font-family: monospace; }
  .quitado { margin-top: 12px; color: #43a047; font-size: 13px; text-align: right; font-weight: 600; }
  .footer { margin-top: 40px; text-align: center; color: #aaa; font-size: 11px; border-top: 1px solid #eee; padding-top: 12px; }
</style>
</head>
<body>

<div class="header">
  <h1>{{ $empresa['nome_fantasia'] ?? $empresa['razao_social'] ?? 'Oficina Mecânica' }}</h1>
  @if(!empty($empresa['cnpj']))
    <p>CNPJ: {{ $empresa['cnpj'] }}</p>
  @endif
  @if(!empty($empresa['endereco']))
    <p>{{ $empresa['endereco'] }}{{ !empty($empresa['cidade']) ? ', ' . $empresa['cidade'] . '/' . $empresa['uf'] : '' }}</p>
  @endif
  @if(!empty($empresa['telefone']))
    <p>Tel: {{ $empresa['telefone'] }}</p>
  @endif
</div>

<div class="title">Recibo de Pagamento</div>

<table>
  <tr>
    <td class="label">Nº da OS</td>
    <td class="value">#{{ $os->numero }}</td>
  </tr>
  <tr>
    <td class="label">Cliente</td>
    <td class="value">{{ $os->cliente?->nome ?? '-' }}</td>
  </tr>
  <tr>
    <td class="label">Veículo</td>
    <td class="value">
      {{ $os->veiculo_descricao ?? '-' }}
      @if($os->veiculo_placa) — {{ $os->veiculo_placa }} @endif
    </td>
  </tr>
  <tr>
    <td class="label">Data do Recibo</td>
    <td class="value">{{ now()->format('d/m/Y') }}</td>
  </tr>
  <tr>
    <td class="label">Forma de Pagamento</td>
    <td class="value">{{ $os->forma_pagamento ?? '-' }}</td>
  </tr>
  <tr>
    <td class="label">Valor Total da OS</td>
    <td class="value">R$ {{ number_format((float)$os->valor_total, 2, ',', '.') }}</td>
  </tr>
</table>

<div class="total-box">
  <p class="lbl">VALOR RECEBIDO</p>
  <p class="val">R$ {{ number_format((float)$os->valor_pago, 2, ',', '.') }}</p>
</div>

@php $saldo = $os->getSaldoDevedorAttribute(); @endphp

@if($saldo > 0)
  <div class="saldo-box">
    <p class="lbl">Saldo em aberto</p>
    <p class="val">R$ {{ number_format($saldo, 2, ',', '.') }}</p>
  </div>
@else
  <p class="quitado">✓ Pagamento totalmente quitado</p>
@endif

<div class="footer">
  Emitido em {{ now()->format('d/m/Y \à\s H:i') }}
  — {{ $empresa['nome_fantasia'] ?? $empresa['razao_social'] ?? 'MecânicaPro' }}
</div>

</body>
</html>
