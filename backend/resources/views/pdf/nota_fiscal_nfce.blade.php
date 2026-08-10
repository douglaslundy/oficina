<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #1a1a1a; padding: 8px; }
  .center { text-align: center; }
  .bold { font-weight: 700; }
  .divider { border-top: 1px dashed #000; margin: 6px 0; }
  table { width: 100%; border-collapse: collapse; }
  th { text-align: left; font-size: 8px; border-bottom: 1px solid #000; padding: 2px 0; }
  td { font-size: 8px; padding: 2px 0; vertical-align: top; }
  .qtd { text-align: center; }
  .valor { text-align: right; }
  .total-row { display: flex; justify-content: space-between; font-size: 11px; font-weight: 700; margin-top: 6px; }
  .chave { font-size: 7px; word-break: break-all; text-align: center; margin-top: 6px; }
  .qr { text-align: center; margin-top: 8px; }
  .qr img { width: 110px; height: 110px; }
  .rodape { font-size: 7px; text-align: center; margin-top: 8px; color: #444; }
</style>
</head>
<body>

<div class="center bold" style="font-size:11px;">{{ $empresa['nome_fantasia'] ?? $empresa['razao_social'] ?? '' }}</div>
@if(!empty($empresa['cnpj']))<div class="center">CNPJ: {{ $empresa['cnpj'] }}</div>@endif
<div class="center">{{ $empresa['cidade'] ?? '' }}{{ !empty($empresa['uf']) ? ' - ' . $empresa['uf'] : '' }}</div>

<div class="divider"></div>
<div class="center bold">DANFE NFC-e</div>
<div class="center">Documento Auxiliar da Nota Fiscal de Consumidor Eletrônica</div>
<div class="divider"></div>

<table>
  <thead>
    <tr>
      <th>Item</th>
      <th class="qtd">Qtd</th>
      <th class="valor">Vl. Unit</th>
      <th class="valor">Vl. Total</th>
    </tr>
  </thead>
  <tbody>
    @foreach($nota->itens as $item)
    <tr>
      <td>{{ $item->descricao }}</td>
      <td class="qtd">{{ number_format($item->quantidade, 2, ',', '.') }}</td>
      <td class="valor">{{ number_format($item->valor_unitario, 2, ',', '.') }}</td>
      <td class="valor">{{ number_format($item->valor_total, 2, ',', '.') }}</td>
    </tr>
    @endforeach
  </tbody>
</table>

<div class="divider"></div>
<div class="total-row"><span>TOTAL</span><span>R$ {{ number_format($nota->valor_total ?? 0, 2, ',', '.') }}</span></div>
@if($nota->forma_pagamento)
<div style="font-size:8px; margin-top:4px;">Forma de pagamento: {{ $nota->forma_pagamento }}</div>
@endif

<div class="divider"></div>
<div class="center" style="font-size:8px;">
  NFC-e nº {{ $nota->numero ?? '-' }} — Série {{ $nota->serie }}<br>
  {{ $nota->cliente->nome ?? 'Consumidor não identificado' }} — {{ $nota->cliente->cpf_cnpj ?? '' }}<br>
  Emitida em {{ $nota->emitido_em?->format('d/m/Y H:i') ?? '-' }}
</div>

@if($nota->chave_acesso)
<div class="chave">{{ $nota->chave_acesso }}</div>
@endif

@if($qrCodeDataUri)
<div class="qr"><img src="{{ $qrCodeDataUri }}" alt="QR Code"></div>
@endif

<div class="rodape">Consulte pela Chave de Acesso no site da SEFAZ</div>

</body>
</html>
