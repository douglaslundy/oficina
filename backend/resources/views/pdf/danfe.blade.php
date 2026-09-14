<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<title>NF-e {{ $nota->numero ?? '' }}</title>
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: "DejaVu Sans", sans-serif; font-size: 9px; color: #000; padding: 14px; }

  table { width: 100%; border-collapse: collapse; }
  td, th { vertical-align: top; }

  .lbl { font-size: 6.5px; text-transform: uppercase; letter-spacing: .03em; color: #444; }
  .val { font-size: 9.5px; font-weight: 700; margin-top: 2px; }
  .val-sm { font-size: 8.5px; font-weight: 700; margin-top: 2px; }

  .grid { border: 0.75px solid #000; border-collapse: collapse; margin-top: -1px; }
  .grid td { border: 0.75px solid #000; padding: 4px 6px; }

  .h-title { text-align: center; }
  .h-title .danfe { font-size: 13px; font-weight: 900; }
  .h-title .sub { font-size: 6.5px; margin-top: 2px; }

  .chave-box { text-align: center; }
  .chave-val { font-size: 9px; font-weight: 700; letter-spacing: .03em; margin: 3px 0; word-break: break-all; }
  .chave-box .consulta { font-size: 6px; color: #333; line-height: 1.3; }

  .status-badge { display: block; text-align: center; font-size: 9px; font-weight: 900; padding: 4px; border: 1.25px solid #000; margin-bottom: 6px; letter-spacing: .04em; }
  .contingencia-badge { display: block; text-align: center; font-size: 9px; font-weight: 900; padding: 4px; border: 1.25px dashed #000; margin-bottom: 6px; letter-spacing: .04em; }

  .items-table { border: 0.75px solid #000; margin-top: -1px; }
  .items-table th { background: #eee; font-size: 6.3px; text-transform: uppercase; padding: 4px 3px; border: 0.75px solid #000; text-align: left; }
  .items-table td { font-size: 8px; padding: 3px 3px; border: 0.75px solid #000; }
  .items-table .num { text-align: right; }
  .items-table .center { text-align: center; }

  .section-lbl { font-size: 7px; font-weight: 900; text-transform: uppercase; letter-spacing: .04em; padding: 3px 0 3px 2px; border: 0.75px solid #000; border-bottom: none; background: #f2f2f2; }

  .footer-note { font-size: 7px; text-align: center; color: #333; margin-top: 8px; }
  .homolog-note { font-size: 8px; font-weight: 700; text-align: center; margin: 4px 0; }
</style>
</head>
<body>

@php $emit = $empresa ?? []; $cli = $nota->cliente; $homologacao = ($nota->ambiente ?? 'PRODUCAO') !== 'PRODUCAO'; @endphp

@if($homologacao)
<div class="homolog-note">NF-e SEM VALOR FISCAL — AMBIENTE DE HOMOLOGAÇÃO</div>
@endif

@if($nota->status === 'CANCELADA')
<div class="status-badge">DOCUMENTO CANCELADO</div>
@elseif($nota->status === 'CONTINGENCIA')
<div class="contingencia-badge">DOCUMENTO EMITIDO EM CONTINGÊNCIA (EPEC) — AGUARDANDO RETRANSMISSÃO</div>
@endif

<!-- Cabeçalho principal -->
<table class="grid">
  <tr>
    <td style="width:40%; border-right:0.75px solid #000;">
      <div class="val" style="font-size:11px;">{{ $emit['nome_fantasia'] ?? $emit['razao_social'] ?? '-' }}</div>
      <div style="font-size:7.5px; margin-top:3px; line-height:1.4;">
        {{ trim(($emit['logradouro'] ?? $emit['endereco'] ?? '') . ' ' . ($emit['numero'] ?? ''), ' ,') }}<br>
        {{ $emit['bairro'] ?? '' }} — {{ $emit['cep'] ?? '' }}<br>
        {{ $emit['cidade'] ?? '' }} - {{ $emit['uf'] ?? '' }}
      </div>
    </td>
    <td class="h-title" style="width:24%; border-right:0.75px solid #000;">
      <div class="danfe">DANFE</div>
      <div class="sub">Documento Auxiliar da<br>Nota Fiscal Eletrônica<br>(NFePHP)</div>
      <div style="font-size:7px; margin-top:8px;">Nº {{ str_pad((string) ($nota->numero ?? 0), 9, '0', STR_PAD_LEFT) }}</div>
      <div style="font-size:7px;">Série {{ $nota->serie ?? '1' }}</div>
    </td>
    <td class="chave-box" style="width:36%;">
      <div class="lbl">Chave de acesso</div>
      <div class="chave-val">{{ $nota->chave_acesso ? implode(' ', str_split($nota->chave_acesso, 4)) : '-' }}</div>
      <div class="consulta">Consulta de autenticidade no portal nacional da NF-e<br>www.nfe.fazenda.gov.br/portal ou no site da SEFAZ autorizadora</div>
    </td>
  </tr>
</table>

<table class="grid">
  <tr>
    <td style="width:60%; border-right:0.75px solid #000;">
      <div class="lbl">Natureza da operação</div>
      <div class="val-sm">{{ $nota->natureza_operacao ?? 'Venda de Mercadoria' }}</div>
    </td>
    <td style="width:40%;">
      <div class="lbl">Protocolo de autorização de uso</div>
      <div class="val-sm">{{ $nota->protocolo ?? '-' }}{{ $nota->emitido_em ? ' — ' . $nota->emitido_em->format('d/m/Y H:i:s') : '' }}</div>
    </td>
  </tr>
</table>

<!-- Destinatário -->
<div class="section-lbl">Destinatário / Remetente</div>
<table class="grid">
  <tr>
    <td style="width:55%; border-right:0.75px solid #000;">
      <div class="lbl">Nome / Razão Social</div>
      <div class="val-sm">{{ $cli->nome ?? 'CONSUMIDOR NÃO IDENTIFICADO' }}</div>
    </td>
    <td style="width:25%; border-right:0.75px solid #000;">
      <div class="lbl">CNPJ / CPF</div>
      <div class="val-sm">{{ $cli->cpf_cnpj ?? '-' }}</div>
    </td>
    <td style="width:20%;">
      <div class="lbl">Data da emissão</div>
      <div class="val-sm">{{ $nota->emitido_em?->format('d/m/Y') ?? '-' }}</div>
    </td>
  </tr>
</table>

<!-- Itens -->
<div class="section-lbl">Dados dos Produtos / Serviços</div>
<table class="items-table">
  <thead>
    <tr>
      <th style="width:44%;">Descrição</th>
      <th style="width:12%;">NCM/SH</th>
      <th style="width:10%;">CFOP</th>
      <th style="width:12%;">Qtde.</th>
      <th style="width:11%;">Vl. Unitário</th>
      <th style="width:11%;">Vl. Total</th>
    </tr>
  </thead>
  <tbody>
    @forelse($itens as $item)
    <tr>
      <td>{{ $item['descricao'] }}</td>
      <td class="center">{{ $item['ncm'] }}</td>
      <td class="center">{{ $item['cfop'] }}</td>
      <td class="num">{{ number_format((float) $item['quantidade'], 4, ',', '.') }}</td>
      <td class="num">{{ number_format((float) $item['valor_unitario'], 2, ',', '.') }}</td>
      <td class="num">{{ number_format((float) $item['valor_total'], 2, ',', '.') }}</td>
    </tr>
    @empty
    <tr><td colspan="6" style="text-align:center; padding:8px;">Nenhum item registrado nesta nota.</td></tr>
    @endforelse
  </tbody>
</table>

<!-- Total -->
<table class="grid">
  <tr>
    <td style="width:70%; border-right:0.75px solid #000;">
      <div class="lbl">Informações complementares</div>
      <div style="font-size:8px; margin-top:3px; line-height:1.4;">{{ $nota->observacoes ?? '-' }}</div>
    </td>
    <td style="width:30%; text-align:right;">
      <div class="lbl">Valor total da nota</div>
      <div class="val" style="font-size:13px;">R$ {{ number_format((float) ($nota->valor_total ?? 0), 2, ',', '.') }}</div>
    </td>
  </tr>
</table>

<div class="footer-note">
  Documento emitido eletronicamente por {{ $emit['nome_fantasia'] ?? $emit['razao_social'] ?? '' }} em {{ now()->format('d/m/Y \à\s H:i') }}
</div>

</body>
</html>
