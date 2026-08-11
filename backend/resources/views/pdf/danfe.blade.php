<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1a1a1a; padding: 24px; }
  .header { border-bottom: 2px solid #f5a623; padding-bottom: 16px; margin-bottom: 24px; display: flex; justify-content: space-between; }
  .logo { font-size: 20px; font-weight: 900; color: #f5a623; }
  .nf-number { font-size: 22px; font-weight: 900; text-align: right; }
  .section { margin-bottom: 20px; }
  .section-title { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: #888; margin-bottom: 10px; padding-bottom: 4px; border-bottom: 1px solid #eee; }
  table { width: 100%; border-collapse: collapse; }
  th { background: #f5f5f5; padding: 6px; text-align: left; font-size: 9px; font-weight: 700; text-transform: uppercase; border-bottom: 1px solid #ddd; }
  td { padding: 6px; font-size: 10px; border-bottom: 1px solid #eee; }
  .chave { font-size: 9px; word-break: break-all; color: #666; margin-top: 8px; padding: 8px; background: #f5f5f5; border-radius: 4px; text-align: center; }
  .contingencia-badge { display: inline-block; padding: 4px 10px; background: #f5a623; color: #000; font-weight: 700; font-size: 10px; border-radius: 4px; margin-bottom: 8px; }
</style>
</head>
<body>

<div class="header">
  <div>
    <div class="logo">MecânicaPro</div>
    <div style="font-size:11px; color:#555;">NF-e emitida via NFePHP</div>
  </div>
  <div style="text-align:right;">
    <div class="nf-number">NF-e Nº {{ $nota->numero ?? 'RASCUNHO' }}</div>
    <div style="font-size:11px; color:#555;">Série: {{ $nota->serie ?? '1' }}</div>
  </div>
</div>

@if($nota->status === 'CONTINGENCIA')
<div class="contingencia-badge">DOCUMENTO EMITIDO EM CONTINGÊNCIA (EPEC) — AGUARDANDO RETRANSMISSÃO</div>
@endif

<div class="section">
  <div class="section-title">Destinatário</div>
  <p>{{ $nota->cliente->nome ?? 'N/A' }} — {{ $nota->cliente->cpf_cnpj ?? '-' }}</p>
</div>

<div class="section">
  <div class="section-title">Itens</div>
  <table>
    <thead>
      <tr><th>Descrição</th><th>NCM</th><th>CFOP</th><th>Qtd</th><th>Vl. Unit.</th><th>Vl. Total</th></tr>
    </thead>
    <tbody>
      @foreach($itens as $item)
      <tr>
        <td>{{ $item['descricao'] }}</td>
        <td>{{ $item['ncm'] }}</td>
        <td>{{ $item['cfop'] }}</td>
        <td>{{ $item['quantidade'] }}</td>
        <td>{{ number_format((float)$item['valor_unitario'], 2, ',', '.') }}</td>
        <td>{{ number_format((float)$item['valor_total'], 2, ',', '.') }}</td>
      </tr>
      @endforeach
    </tbody>
  </table>
</div>

<div class="section">
  <div class="section-title">Total</div>
  <p style="font-size:16px; font-weight:900;">R$ {{ number_format((float)($nota->valor_total ?? 0), 2, ',', '.') }}</p>
</div>

@if($nota->chave_acesso)
<div class="chave">Chave de Acesso: {{ $nota->chave_acesso }}</div>
@endif

</body>
</html>
