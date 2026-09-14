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

  .box { border: 0.75px solid #000; padding: 4px 6px; }
  .box + .box { border-left: none; }
  .grid { border: 0.75px solid #000; border-collapse: collapse; margin-top: -1px; }
  .grid td { border: 0.75px solid #000; padding: 4px 6px; }

  .canhoto { border: 0.75px solid #000; padding: 5px 6px; margin-bottom: 6px; }
  .canhoto .txt { font-size: 7.5px; line-height: 1.4; }
  .canhoto-row { border-top: 0.75px solid #000; margin-top: 4px; padding-top: 4px; }

  .h-title { text-align: center; }
  .h-title .danfe { font-size: 13px; font-weight: 900; }
  .h-title .sub { font-size: 6.5px; margin-top: 2px; }
  .h-title .tipo { display: inline-block; border: 0.75px solid #000; width: 16px; height: 16px; line-height: 16px; font-weight: 900; margin-top: 4px; }

  .chave-box { text-align: center; }
  .chave-box .lbl { text-align: center; }
  .chave-val { font-size: 9px; font-weight: 700; letter-spacing: .03em; margin: 3px 0; word-break: break-all; }
  .chave-box .consulta { font-size: 6px; color: #333; line-height: 1.3; }

  .status-badge { display: block; text-align: center; font-size: 9px; font-weight: 900; padding: 4px; border: 1.25px solid #000; margin-bottom: 6px; letter-spacing: .04em; }

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

@php
  $emit = $empresa ?? [];
  $cli  = $nota->cliente;
  $homologacao = ($nota->ambiente ?? 'PRODUCAO') !== 'PRODUCAO';
  $entradaSaida = 1; // Saída — única operação que este sistema emite.
@endphp

@if($homologacao)
<div class="homolog-note">NF-e SEM VALOR FISCAL — AMBIENTE DE HOMOLOGAÇÃO</div>
@endif

@if($nota->status === 'CANCELADA')
<div class="status-badge">DOCUMENTO CANCELADO</div>
@endif

<!-- Canhoto de recebimento -->
<div class="canhoto">
  <div class="txt">Recebemos de <strong>{{ $emit['nome_fantasia'] ?? $emit['razao_social'] ?? '-' }}</strong> os produtos e/ou serviços constantes da Nota Fiscal Eletrônica indicada ao lado.</div>
  <table class="canhoto-row">
    <tr>
      <td style="width:65%; border-right:0.75px solid #000; padding-right:8px;">
        <div class="lbl">Data do recebimento</div><br>
        <div class="lbl">Identificação e assinatura do recebedor</div>
      </td>
      <td style="width:35%; padding-left:8px; text-align:right;">
        <div class="lbl">NF-e</div>
        <div class="val">Nº {{ str_pad((string) ($nota->numero ?? 0), 9, '0', STR_PAD_LEFT) }}</div>
        <div class="lbl" style="margin-top:2px;">Série {{ $nota->serie ?? '1' }}</div>
      </td>
    </tr>
  </table>
</div>

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
      <div class="sub">Documento Auxiliar da<br>Nota Fiscal Eletrônica</div>
      <div style="font-size:6px; margin-top:5px;">0 - Entrada&nbsp;&nbsp;1 - Saída</div>
      <div class="tipo">{{ $entradaSaida }}</div>
      <div style="font-size:7px; margin-top:5px;">Nº {{ str_pad((string) ($nota->numero ?? 0), 9, '0', STR_PAD_LEFT) }}</div>
      <div style="font-size:7px;">Série {{ $nota->serie ?? '1' }} — Fls. 1/1</div>
    </td>
    <td class="chave-box" style="width:36%;">
      <div class="lbl">Chave de acesso</div>
      <div class="chave-val">{{ implode(' ', str_split((string) ($nota->chave_acesso ?? str_repeat('0', 44)), 4)) }}</div>
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

<!-- Emitente -->
<div class="section-lbl">Emitente</div>
<table class="grid">
  <tr>
    <td style="width:55%; border-right:0.75px solid #000;">
      <div class="lbl">Nome / Razão Social</div>
      <div class="val-sm">{{ $emit['razao_social'] ?? '-' }}</div>
    </td>
    <td style="width:25%; border-right:0.75px solid #000;">
      <div class="lbl">CNPJ / CPF</div>
      <div class="val-sm">{{ $emit['cnpj'] ?? '-' }}</div>
    </td>
    <td style="width:20%;">
      <div class="lbl">Inscrição Estadual</div>
      <div class="val-sm">{{ $emit['inscricao_estadual'] ?? '-' }}</div>
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
  <tr>
    <td style="width:55%; border-right:0.75px solid #000;">
      <div class="lbl">Endereço</div>
      <div class="val-sm">{{ $cli->endereco ?? '-' }}</div>
    </td>
    <td style="width:25%; border-right:0.75px solid #000;">
      <div class="lbl">Bairro / CEP</div>
      <div class="val-sm">{{ $cli->bairro ?? '-' }} — {{ $cli->cep ?? '-' }}</div>
    </td>
    <td style="width:20%;">
      <div class="lbl">Município / UF</div>
      <div class="val-sm">{{ $cli->cidade ?? '-' }} / {{ $cli->uf ?? '-' }}</div>
    </td>
  </tr>
</table>

<!-- Cálculo do imposto -->
<div class="section-lbl">Cálculo do Imposto</div>
<table class="grid">
  <tr>
    <td style="width:33%; border-right:0.75px solid #000;">
      <div class="lbl">Valor total dos produtos</div>
      <div class="val-sm">R$ {{ number_format((float) ($nota->subtotal ?? $nota->valor_total ?? 0), 2, ',', '.') }}</div>
    </td>
    <td style="width:33%; border-right:0.75px solid #000;">
      <div class="lbl">Desconto</div>
      <div class="val-sm">R$ {{ number_format((float) ($nota->desconto ?? 0), 2, ',', '.') }}</div>
    </td>
    <td style="width:34%;">
      <div class="lbl">Valor total da nota</div>
      <div class="val" style="font-size:11px;">R$ {{ number_format((float) ($nota->valor_total ?? 0), 2, ',', '.') }}</div>
    </td>
  </tr>
</table>

<!-- Itens -->
<div class="section-lbl">Dados dos Produtos / Serviços</div>
<table class="items-table">
  <thead>
    <tr>
      <th style="width:10%;">Código</th>
      <th style="width:32%;">Descrição</th>
      <th style="width:10%;">NCM/SH</th>
      <th style="width:9%;">CST/CSOSN</th>
      <th style="width:7%;">CFOP</th>
      <th style="width:7%;">Unid.</th>
      <th style="width:8%;">Qtde.</th>
      <th style="width:8.5%;">Vl. Unitário</th>
      <th style="width:8.5%;">Vl. Total</th>
    </tr>
  </thead>
  <tbody>
    @forelse($nota->itens as $item)
    <tr>
      <td>{{ $item->sku ?? '-' }}</td>
      <td>{{ $item->descricao }}</td>
      <td class="center">{{ $item->ncm }}</td>
      <td class="center">{{ $item->cst_csosn }}</td>
      <td class="center">{{ $item->cfop }}</td>
      <td class="center">{{ $item->unidade ?? 'UN' }}</td>
      <td class="num">{{ number_format($item->quantidade, 4, ',', '.') }}</td>
      <td class="num">{{ number_format($item->valor_unitario, 2, ',', '.') }}</td>
      <td class="num">{{ number_format($item->valor_total, 2, ',', '.') }}</td>
    </tr>
    @empty
    <tr><td colspan="9" style="text-align:center; padding:8px;">Nenhum item registrado nesta nota.</td></tr>
    @endforelse
  </tbody>
</table>

<!-- Dados adicionais -->
<div class="section-lbl">Dados Adicionais</div>
<table class="grid">
  <tr>
    <td style="width:65%; border-right:0.75px solid #000; min-height:34px;">
      <div class="lbl">Informações complementares</div>
      <div style="font-size:8px; margin-top:3px; line-height:1.4;">{{ $nota->observacoes ?? '-' }}</div>
    </td>
    <td style="width:35%;">
      <div class="lbl">Forma de pagamento</div>
      <div class="val-sm">{{ $nota->forma_pagamento ?? '-' }}</div>
    </td>
  </tr>
</table>

<div class="footer-note">
  Documento emitido eletronicamente por {{ $emit['nome_fantasia'] ?? $emit['razao_social'] ?? '' }} em {{ now()->format('d/m/Y \à\s H:i') }}
</div>

</body>
</html>
