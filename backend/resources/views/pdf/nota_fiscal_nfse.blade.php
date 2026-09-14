<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<title>NFS-e {{ $nota->numero ?? '' }}</title>
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

  .section-lbl { font-size: 7px; font-weight: 900; text-transform: uppercase; letter-spacing: .04em; padding: 3px 0 3px 2px; border: 0.75px solid #000; border-bottom: none; background: #f2f2f2; }

  .h-title { text-align: center; }
  .h-title .nome { font-size: 13px; font-weight: 900; }
  .h-title .sub { font-size: 8px; margin-top: 3px; text-transform: uppercase; letter-spacing: .04em; }

  .discriminacao { min-height: 90px; font-size: 8.5px; line-height: 1.5; padding: 6px; }

  .footer-note { font-size: 7px; text-align: center; color: #333; margin-top: 8px; }
  .homolog-note { font-size: 8px; font-weight: 700; text-align: center; margin: 4px 0; }
  .status-badge { display: block; text-align: center; font-size: 9px; font-weight: 900; padding: 4px; border: 1.25px solid #000; margin-bottom: 6px; letter-spacing: .04em; }

  .verificacao { text-align: center; font-size: 7px; padding: 6px; border: 0.75px solid #000; border-top: none; }
  .verificacao .cod { font-size: 9px; font-weight: 700; margin-top: 2px; }
</style>
</head>
<body>

@php $emit = $empresa ?? []; $cli = $nota->cliente; $homologacao = ($nota->ambiente ?? 'PRODUCAO') !== 'PRODUCAO'; @endphp

@if($homologacao)
<div class="homolog-note">NFS-e SEM VALOR FISCAL — AMBIENTE DE HOMOLOGAÇÃO</div>
@endif

@if($nota->status === 'CANCELADA')
<div class="status-badge">NOTA CANCELADA</div>
@endif

<!-- Cabeçalho -->
<table class="grid">
  <tr>
    <td class="h-title" style="width:70%; border-right:0.75px solid #000;">
      {{-- Cabeçalho da NFS-e é da PREFEITURA (documento municipal, não do
           prestador) — modelo oficial de referência (doc_documentos_fiscais/
           modelo_nota/NotaServico.pdf) mostra "PREFEITURA MUNICIPAL DE
           {CIDADE}-{UF}" + "SECRETARIA MUNICIPAL DE FAZENDA" aqui; o nome da
           oficina (prestador) já aparece embaixo, na seção "Prestador de
           Serviços" — não deve se repetir no topo. --}}
      {{-- strtoupper() nativo não é multibyte-safe (deixa acento minúsculo,
           ex. "ILICíNEA" em vez de "ILICÍNEA") — mb_strtoupper() com UTF-8
           explícito é obrigatório pra nome de cidade brasileiro. --}}
      <div class="nome">PREFEITURA MUNICIPAL DE {{ mb_strtoupper(trim(($emit['cidade'] ?? '-') . '-' . ($emit['uf'] ?? '')), 'UTF-8') }}</div>
      <div class="sub">Secretaria Municipal de Fazenda</div>
      <div class="sub">Nota Fiscal de Serviços Eletrônica — NFS-e</div>
    </td>
    <td style="width:30%; text-align:right;">
      <div class="lbl">Nota fiscal</div>
      <div class="val" style="font-size:14px;">{{ $nota->numero ?? '-' }}</div>
      <div class="lbl" style="margin-top:3px;">Data e hora da emissão</div>
      <div class="val-sm">{{ $nota->emitido_em?->format('d/m/Y H:i') ?? '-' }}</div>
    </td>
  </tr>
</table>

<!-- Prestador -->
<div class="section-lbl">Prestador de Serviços</div>
<table class="grid">
  <tr>
    <td style="width:60%; border-right:0.75px solid #000;">
      <div class="lbl">Razão Social</div>
      <div class="val-sm">{{ $emit['razao_social'] ?? '-' }}</div>
    </td>
    <td style="width:40%;">
      <div class="lbl">Nome Fantasia</div>
      <div class="val-sm">{{ $emit['nome_fantasia'] ?? '-' }}</div>
    </td>
  </tr>
  <tr>
    <td style="width:60%; border-right:0.75px solid #000;">
      <div class="lbl">Endereço</div>
      <div class="val-sm">{{ trim(($emit['logradouro'] ?? $emit['endereco'] ?? '') . ', Nº ' . ($emit['numero'] ?? 'S/N') . ', ' . ($emit['bairro'] ?? '') . ', ' . ($emit['cidade'] ?? '') . '-' . ($emit['uf'] ?? '') . ', ' . ($emit['cep'] ?? ''), ' ,') }}</div>
    </td>
    <td style="width:40%;">
      <div class="lbl">CNPJ / CPF</div>
      <div class="val-sm">{{ $emit['cnpj'] ?? '-' }}</div>
    </td>
  </tr>
  <tr>
    <td style="width:60%; border-right:0.75px solid #000;">
      <div class="lbl">Inscrição Municipal</div>
      <div class="val-sm">{{ $emit['inscricao_municipal'] ?? '-' }}</div>
    </td>
    <td style="width:40%;">
      <div class="lbl">Inscrição Estadual</div>
      <div class="val-sm">{{ $emit['inscricao_estadual'] ?? '-' }}</div>
    </td>
  </tr>
</table>

<!-- Tomador -->
<div class="section-lbl">Tomador de Serviços</div>
<table class="grid">
  <tr>
    <td style="width:60%; border-right:0.75px solid #000;">
      <div class="lbl">Nome / Razão Social</div>
      <div class="val-sm">{{ $cli->nome ?? 'CONSUMIDOR NÃO IDENTIFICADO' }}</div>
    </td>
    <td style="width:40%;">
      <div class="lbl">CNPJ / CPF</div>
      <div class="val-sm">{{ $cli->cpf_cnpj ?? '-' }}</div>
    </td>
  </tr>
  <tr>
    <td style="width:60%; border-right:0.75px solid #000;">
      <div class="lbl">Endereço</div>
      <div class="val-sm">{{ trim(($cli->endereco ?? '-') . ', ' . ($cli->bairro ?? '') . ', ' . ($cli->cidade ?? '') . '-' . ($cli->uf ?? '') . ', ' . ($cli->cep ?? ''), ' ,') }}</div>
    </td>
    <td style="width:40%;">
      <div class="lbl">Telefone / E-mail</div>
      <div class="val-sm">{{ $cli->telefone ?? '-' }}{{ $cli->email ? ' — ' . $cli->email : '' }}</div>
    </td>
  </tr>
</table>

<!-- Discriminação -->
<div class="section-lbl">Discriminação dos Serviços</div>
<div class="grid discriminacao">{{ $nota->observacoes ?? 'Serviços automotivos prestados conforme acordado.' }}</div>

<!-- Valores -->
<div class="section-lbl">Valores</div>
<table class="grid">
  <tr>
    <td style="width:25%; border-right:0.75px solid #000;">
      <div class="lbl">Valor dos serviços</div>
      <div class="val-sm">R$ {{ number_format((float) ($nota->subtotal ?? $nota->valor_total ?? 0), 2, ',', '.') }}</div>
    </td>
    <td style="width:25%; border-right:0.75px solid #000;">
      <div class="lbl">Alíquota ISS</div>
      <div class="val-sm">{{ number_format((float) ($nota->aliquota_iss ?? 0), 2, ',', '.') }}%</div>
    </td>
    <td style="width:25%; border-right:0.75px solid #000;">
      <div class="lbl">Valor do ISS</div>
      <div class="val-sm">R$ {{ number_format((float) ($nota->valor_iss ?? 0), 2, ',', '.') }}</div>
    </td>
    <td style="width:25%;">
      <div class="lbl">Total da nota</div>
      <div class="val" style="font-size:11px;">R$ {{ number_format((float) ($nota->valor_total ?? 0), 2, ',', '.') }}</div>
    </td>
  </tr>
</table>
<table class="grid">
  <tr>
    <td style="width:50%; border-right:0.75px solid #000;">
      <div class="lbl">Forma de pagamento</div>
      <div class="val-sm">{{ $nota->forma_pagamento ?? '-' }}</div>
    </td>
    <td style="width:50%;">
      <div class="lbl">Desconto</div>
      <div class="val-sm">R$ {{ number_format((float) ($nota->desconto ?? 0), 2, ',', '.') }}</div>
    </td>
  </tr>
</table>

@if($nota->chave_acesso || $nota->protocolo)
<div class="verificacao">
  CÓDIGO DE VERIFICAÇÃO
  <div class="cod">{{ $nota->chave_acesso ?? $nota->protocolo }}</div>
</div>
@endif

<div class="footer-note">
  Documento emitido eletronicamente por {{ $emit['nome_fantasia'] ?? $emit['razao_social'] ?? '' }} em {{ now()->format('d/m/Y \à\s H:i') }}
</div>

</body>
</html>
