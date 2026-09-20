<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<style>
  @page { margin: 24px 22px; }
  body { font-family: Arial, sans-serif; font-size: 8px; color: #222; }
  .header { text-align: center; border-bottom: 2px solid #f5a623; padding-bottom: 8px; margin-bottom: 10px; }
  .header h1 { font-size: 15px; margin: 0 0 2px; color: #111; }
  .header p { margin: 1px 0; color: #555; font-size: 9px; }
  .title { font-size: 12px; font-weight: bold; margin: 6px 0 2px; color: #333; }
  .meta { color: #666; font-size: 9px; margin-bottom: 8px; }
  table { width: 100%; border-collapse: collapse; table-layout: fixed; }
  thead { display: table-header-group; }
  th { background: #f2f2f2; text-align: left; padding: 4px 4px; border-bottom: 1px solid #999; font-size: 8px; }
  td { padding: 3px 4px; border-bottom: 1px solid #e6e6e6; vertical-align: top; word-wrap: break-word; }
  tr { page-break-inside: avoid; }
  .mono { font-family: monospace; }
  .sem-fiscal td { background: #fdeceb; }
  .sit-ok { color: #2e7d32; font-weight: bold; }
  .sit-ruim { color: #c62828; font-weight: bold; }
  .sit-aviso { color: #b26a00; font-weight: bold; }
  .footer { margin-top: 10px; text-align: center; color: #aaa; font-size: 8px; }
</style>
</head>
<body>

<div class="header">
  <h1>{{ $empresa['nome_fantasia'] ?? $empresa['razao_social'] ?? 'Oficina Mecânica' }}</h1>
  @if(!empty($empresa['cnpj']))
    <p>CNPJ: {{ $empresa['cnpj'] }}</p>
  @endif
</div>

<div class="title">Produtos e dados fiscais</div>
<div class="meta">
  Gerado em {{ $geradoEm }} · {{ count($linhas) }} produto(s)
  @if(!empty($categoria)) · Categoria: {{ $categoria }} @endif
  · Produtos sem nenhum campo fiscal preenchido aparecem por último.
</div>

<table>
  <thead>
    <tr>
      @foreach($colunas as $chave => $titulo)
        <th style="width: {{ $larguras[$chave] }}%">{{ $titulo }}</th>
      @endforeach
    </tr>
  </thead>
  <tbody>
    @foreach($linhas as $linha)
      @php
        $semFiscal = $linha['ncm'] === null && $linha['cest'] === null && $linha['origem'] === null && $linha['tributacao_icms'] === null;
        $sit = $linha['situacao_fiscal'];
        $cls = $sit === 'Completo' ? 'sit-ok' : (in_array($sit, ['Sem NCM', 'ICMS-ST sem CEST'], true) ? 'sit-ruim' : 'sit-aviso');
      @endphp
      <tr class="{{ $semFiscal ? 'sem-fiscal' : '' }}">
        @foreach($colunas as $chave => $titulo)
          @if($chave === 'situacao_fiscal')
            <td class="{{ $cls }}">{{ $sit }}</td>
          @elseif(in_array($chave, ['sku', 'codigo_barras', 'ncm', 'cest'], true))
            <td class="mono">{{ $linha[$chave] ?? '—' }}</td>
          @else
            {{-- origem 0 (nacional) é valor válido: só vira "—" quando for null de verdade --}}
            <td>{{ $linha[$chave] === null ? '—' : $linha[$chave] }}</td>
          @endif
        @endforeach
      </tr>
    @endforeach
  </tbody>
</table>

<div class="footer">MecânicaPro · relatório gerado automaticamente</div>

</body>
</html>
