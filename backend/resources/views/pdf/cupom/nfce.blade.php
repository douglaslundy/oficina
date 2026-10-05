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
<div class="c b">DANFE NFC-e - Documento Auxiliar da Nota Fiscal de Consumidor Eletrônica</div>
<div class="c">Não permite aproveitamento de crédito de ICMS</div>
<div class="sep"></div>

<table>
  <tr class="b">
    <td style="width:75%">CÓD. / DESCRIÇÃO / QTDE UN x VL UNIT</td>
    <td class="r" style="width:25%">VL TOTAL</td>
  </tr>
</table>
<div class="sep"></div>
<table>
@foreach($itens as $i)
  <tr class="item"><td colspan="2">{{ $i['codigo'] !== '' ? $i['codigo'] . ' ' : '' }}{{ $i['descricao'] }}</td></tr>
  <tr><td>{{ rtrim(rtrim(number_format($i['qtd'], 4, ',', '.'), '0'), ',') }} {{ $i['un'] }} x {{ number_format($i['vl_unit'], 2, ',', '.') }}</td><td class="r">{{ number_format($i['vl_total'], 2, ',', '.') }}</td></tr>
@endforeach
</table>
<div class="sep"></div>

<table>
  <tr><td>Qtd. total de itens</td><td class="r">{{ $qtd_itens }}</td></tr>
  @if($desconto > 0)
  <tr><td>Valor total R$</td><td class="r">{{ number_format($valor_total_itens, 2, ',', '.') }}</td></tr>
  <tr><td>Desconto R$</td><td class="r">{{ number_format($desconto, 2, ',', '.') }}</td></tr>
  @endif
  <tr class="b g"><td>Valor a pagar R$</td><td class="r">{{ number_format($valor_a_pagar, 2, ',', '.') }}</td></tr>
  <tr class="b"><td>FORMA DE PAGAMENTO</td><td class="r">Valor pago R$</td></tr>
  @foreach($pagamentos as $p)
  <tr><td>{{ $p['forma'] }}</td><td class="r">{{ number_format($p['valor'], 2, ',', '.') }}</td></tr>
  @endforeach
  @if($troco > 0)
  <tr><td>Troco R$</td><td class="r">{{ number_format($troco, 2, ',', '.') }}</td></tr>
  @endif
</table>

@if($tributos_totais !== null)
<div class="sep"></div>
<div>Informação dos Tributos Totais Incidentes (Lei Federal 12.741/2012): R$ {{ number_format($tributos_totais, 2, ',', '.') }}</div>
@endif

@foreach($mensagens_fiscais as $m)
<div class="aviso">{{ $m }}</div>
@endforeach

<div class="sep"></div>
<div class="c b">ÁREA DE CONSULTA VIA CHAVE DE ACESSO</div>
@if($url_consulta !== '')<div class="c" style="word-break: break-all;">{{ $url_consulta }}</div>@else<div class="c">Consulte pela chave de acesso no portal da SEFAZ</div>@endif
@if($chave_formatada !== '')
<div class="c b">CHAVE DE ACESSO</div>
<div class="c chave">{{ $chave_formatada }}</div>
@endif

<div class="sep"></div>
<div class="c b">
@if($consumidor_doc !== '')CONSUMIDOR - {{ strlen(preg_replace('/\D/', '', $consumidor_doc)) === 14 ? 'CNPJ' : 'CPF' }} {{ $consumidor_doc }}@else CONSUMIDOR NÃO IDENTIFICADO @endif
</div>
@if($consumidor_nome !== '')<div class="c">{{ $consumidor_nome }}</div>@endif

<div class="sep"></div>
<div class="c b">NFC-e nº {{ $numero }} Série {{ $serie }} {{ $emissao }}</div>
<div class="c">Via Consumidor</div>
@if($protocolo !== '')
<div class="c">Protocolo de autorização: {{ $protocolo }}</div>
@if($data_autorizacao !== '')<div class="c">Data de autorização: {{ $data_autorizacao }}</div>@endif
@endif

@if($qr_code)
<div class="qr"><img src="{{ $qr_code }}" alt="QR Code"></div>
@endif

@if(!empty($info_adicional))
<div class="sep"></div>
<div class="b">INFORMAÇÕES ADICIONAIS DE INTERESSE DO CONTRIBUINTE</div>
<div>{{ $info_adicional }}</div>
@endif

</div>
</body>
</html>
