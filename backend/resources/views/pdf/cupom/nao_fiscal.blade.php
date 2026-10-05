<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
@include('pdf.cupom._estilo')
</head>
<body>
<div class="cupom">

@include('pdf.cupom.cabecalho')

<div class="sep"></div>
<div class="c b gg">CUPOM NÃO FISCAL</div>
<div class="aviso">*** SEM VALOR FISCAL ***</div>
<div class="sep"></div>

<table>
  <tr><td>Venda / OS nº</td><td class="r b">{{ $os_numero }}</td></tr>
  <tr><td>Data</td><td class="r">{{ $data }}</td></tr>
  <tr><td>Cliente</td><td class="r">{{ $cliente }}</td></tr>
  @if($veiculo !== '')<tr><td>Veículo</td><td class="r">{{ $veiculo }}</td></tr>@endif
</table>
<div class="sep"></div>

<table>
  <tr class="b"><td style="width:75%">DESCRIÇÃO / QTDE x VL UNIT</td><td class="r" style="width:25%">VL TOTAL</td></tr>
</table>
<div class="sep"></div>
<table>
@foreach($itens as $i)
  <tr class="item"><td colspan="2">{{ $i['descricao'] }}</td></tr>
  <tr><td>{{ rtrim(rtrim(number_format($i['qtd'], 2, ',', '.'), '0'), ',') }} x {{ number_format($i['vl_unit'], 2, ',', '.') }}</td><td class="r">{{ number_format($i['vl_total'], 2, ',', '.') }}</td></tr>
@endforeach
</table>
<div class="sep"></div>

<table>
  @if($desconto > 0)
  <tr><td>Subtotal R$</td><td class="r">{{ number_format($subtotal, 2, ',', '.') }}</td></tr>
  <tr><td>Desconto R$</td><td class="r">{{ number_format($desconto, 2, ',', '.') }}</td></tr>
  @endif
  <tr class="b g"><td>TOTAL R$</td><td class="r">{{ number_format($total, 2, ',', '.') }}</td></tr>
  @foreach($pagamentos as $p)
  <tr><td>{{ $p['forma'] }}</td><td class="r">{{ number_format($p['valor'], 2, ',', '.') }}</td></tr>
  @endforeach
  <tr><td>Valor pago R$</td><td class="r">{{ number_format($valor_pago, 2, ',', '.') }}</td></tr>
  @if($saldo > 0)<tr class="b"><td>Saldo a pagar R$</td><td class="r">{{ number_format($saldo, 2, ',', '.') }}</td></tr>@endif
</table>

<div class="sep"></div>
<div class="aviso">NÃO É DOCUMENTO FISCAL</div>
<div class="c">Obrigado pela preferência!</div>

</div>
</body>
</html>
