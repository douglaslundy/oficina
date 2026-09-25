{{--
  Corpo do DANFE compartilhado entre pdf/nota_fiscal_nfe.blade.php (Spedy/
  Focus) e pdf/danfe.blade.php (NFePHP) — extraído nesta análise visual
  (2026-09-14, comparando com os modelos oficiais em
  doc_documentos_fiscais/modelo_nota/NotaProduto.pdf) pra nunca mais deixar
  os dois divergirem, mesmo raciocínio de ProcessaRespostaSefaz (trait) já
  aplicado no lado do motor fiscal.

  Contrato de variáveis esperado por quem faz @include:
  - $nota: NotaFiscal (numero, serie, chave_acesso, natureza_operacao,
    protocolo, emitido_em, status, ambiente, observacoes, forma_pagamento,
    subtotal, desconto, valor_total, cliente opcional)
  - $empresa: array (nome_fantasia, razao_social, logradouro/endereco,
    numero, bairro, cep, cidade, uf, cnpj, inscricao_estadual)
  - $itens: array de arrays associativos (codigo, descricao, ncm,
    cst_csosn, cfop, unidade, quantidade, valor_unitario, valor_total)
  - $barcodeDataUri: string|null (data URI do código de barras Code-128C)

  Elementos abaixo que NÃO existiam antes desta análise (comparados contra
  o modelo oficial, não adicionados por suposição):
  - Código de barras da chave de acesso.
  - Linha compacta de IE/CNPJ do emitente logo após natureza/protocolo
    (layout oficial não repete o emitente numa seção própria — só no
    cabeçalho + esta linha).
  - Seção "Transportador / Volumes Transportados".
  - Campos expandidos de "Cálculo do Imposto" (Base ICMS, Valor ICMS, Base
    ICMS ST, Valor ICMS ST, Frete, Seguro, Outras Despesas, IPI, PIS,
    COFINS) — valores zerados batendo com o que o XML de verdade já envia
    pra Simples Nacional (CRT=1, grupo ICMSSN, PIS/COFINS CST 49 zerado —
    ver MotorNfe::montarNfe()), não um valor inventado.
  - Colunas expandidas da tabela de itens (Vl. Desconto, Base Cálc. ICMS,
    Vl. ICMS, Vl. IPI, Alíq. ICMS%, Alíq. IPI%) — mesmo raciocínio, zeradas
    porque é isso que o sistema realmente calcula hoje.
--}}
@php
  $emit = $empresa ?? [];
  $cli  = $nota->cliente ?? null;
  $homologacao = ($nota->ambiente ?? 'PRODUCAO') !== 'PRODUCAO';
  $vProdutos = collect($itens)->sum(fn ($i) => (float) ($i['valor_total'] ?? 0));
  $vTotalNota = (float) ($nota->valor_total ?? $vProdutos);
@endphp

@if($homologacao)
<div class="homolog-note">NF-e SEM VALOR FISCAL — AMBIENTE DE HOMOLOGAÇÃO</div>
@endif

@if($nota->status === 'CANCELADA')
<div class="status-badge">DOCUMENTO CANCELADO</div>
@elseif($nota->status === 'CONTINGENCIA')
<div class="contingencia-badge">DOCUMENTO EMITIDO EM CONTINGÊNCIA — AGUARDANDO RETRANSMISSÃO</div>
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
    <td style="width:38%; border-right:0.75px solid #000;">
      <div class="val" style="font-size:11px;">{{ $emit['nome_fantasia'] ?? $emit['razao_social'] ?? '-' }}</div>
      <div style="font-size:7.5px; margin-top:3px; line-height:1.4;">
        {{ trim(($emit['logradouro'] ?? $emit['endereco'] ?? '') . ' ' . ($emit['numero'] ?? ''), ' ,') }}<br>
        {{ $emit['bairro'] ?? '' }} — {{ $emit['cep'] ?? '' }}<br>
        {{ $emit['cidade'] ?? '' }} - {{ $emit['uf'] ?? '' }}
      </div>
    </td>
    <td class="h-title" style="width:22%; border-right:0.75px solid #000;">
      <div class="danfe">DANFE</div>
      <div class="sub">Documento Auxiliar da<br>Nota Fiscal Eletrônica</div>
      <div style="font-size:6px; margin-top:5px;">0 - Entrada&nbsp;&nbsp;1 - Saída</div>
      <div class="tipo">1</div>
      <div style="font-size:7px; margin-top:5px;">Nº {{ str_pad((string) ($nota->numero ?? 0), 9, '0', STR_PAD_LEFT) }}</div>
      <div style="font-size:7px;">Série {{ $nota->serie ?? '1' }} — Fls. 1/1</div>
    </td>
    <td class="chave-box" style="width:40%;">
      @if(!empty($barcodeDataUri))
      <img src="{{ $barcodeDataUri }}" style="width:100%; max-height:32px; margin-bottom:2px;">
      @endif
      <div class="lbl">Chave de acesso</div>
      <div class="chave-val">{{ $nota->chave_acesso ? implode(' ', str_split((string) $nota->chave_acesso, 4)) : implode(' ', str_split(str_repeat('0', 44), 4)) }}</div>
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
  <tr>
    <td style="width:20%; border-right:0.75px solid #000;">
      <div class="lbl">Inscrição Estadual</div>
      <div class="val-sm">{{ $emit['inscricao_estadual'] ?? '-' }}</div>
    </td>
    <td style="width:20%; border-right:0.75px solid #000;">
      <div class="lbl">Inscrição Estadual do Subst. Tributário</div>
      <div class="val-sm">-</div>
    </td>
    <td style="width:20%;">
      <div class="lbl">CNPJ / CPF</div>
      <div class="val-sm">{{ $emit['cnpj'] ?? '-' }}</div>
    </td>
  </tr>
</table>

<!-- Destinatário -->
<div class="section-lbl">Destinatário / Remetente</div>
<table class="grid">
  <tr>
    <td style="width:45%; border-right:0.75px solid #000;">
      <div class="lbl">Nome / Razão Social</div>
      <div class="val-sm">{{ $cli->nome ?? 'CONSUMIDOR NÃO IDENTIFICADO' }}</div>
    </td>
    <td style="width:20%; border-right:0.75px solid #000;">
      <div class="lbl">CNPJ / CPF</div>
      <div class="val-sm">{{ $cli->cpf_cnpj ?? '-' }}</div>
    </td>
    <td style="width:17.5%; border-right:0.75px solid #000;">
      <div class="lbl">Data da emissão</div>
      <div class="val-sm">{{ $nota->emitido_em?->format('d/m/Y') ?? '-' }}</div>
    </td>
    <td style="width:17.5%;">
      <div class="lbl">Data da saída</div>
      <div class="val-sm">{{ $nota->emitido_em?->format('d/m/Y') ?? '-' }}</div>
    </td>
  </tr>
  <tr>
    <td style="width:45%; border-right:0.75px solid #000;">
      <div class="lbl">Endereço</div>
      <div class="val-sm">{{ $cli->endereco ?? '-' }}</div>
    </td>
    <td style="width:20%; border-right:0.75px solid #000;">
      <div class="lbl">Bairro / Distrito</div>
      <div class="val-sm">{{ $cli->bairro ?? '-' }}</div>
    </td>
    <td style="width:17.5%; border-right:0.75px solid #000;">
      <div class="lbl">CEP</div>
      <div class="val-sm">{{ $cli->cep ?? '-' }}</div>
    </td>
    <td style="width:17.5%;">
      <div class="lbl">Hora da saída</div>
      <div class="val-sm">{{ $nota->emitido_em?->format('H:i') ?? '-' }}</div>
    </td>
  </tr>
  <tr>
    <td style="width:45%; border-right:0.75px solid #000;">
      <div class="lbl">Município</div>
      <div class="val-sm">{{ $cli->cidade ?? '-' }}</div>
    </td>
    <td style="width:20%; border-right:0.75px solid #000;">
      <div class="lbl">UF</div>
      <div class="val-sm">{{ $cli->uf ?? '-' }}</div>
    </td>
    <td style="width:35%;" colspan="2">
      <div class="lbl">Telefone / Fax</div>
      <div class="val-sm">{{ $cli->telefone ?? '-' }}</div>
    </td>
  </tr>
</table>

<!-- Cálculo do imposto -->
<div class="section-lbl">Cálculo do Imposto</div>
<table class="grid">
  <tr>
    <td style="width:20%; border-right:0.75px solid #000;">
      <div class="lbl">Base cálc. do ICMS</div>
      <div class="val-sm">0,00</div>
    </td>
    <td style="width:20%; border-right:0.75px solid #000;">
      <div class="lbl">Valor do ICMS</div>
      <div class="val-sm">0,00</div>
    </td>
    <td style="width:20%; border-right:0.75px solid #000;">
      <div class="lbl">Base cálc. ICMS subst.</div>
      <div class="val-sm">0,00</div>
    </td>
    <td style="width:20%; border-right:0.75px solid #000;">
      <div class="lbl">Valor ICMS subst.</div>
      <div class="val-sm">0,00</div>
    </td>
    <td style="width:20%;">
      <div class="lbl">Valor total dos produtos</div>
      <div class="val-sm">{{ number_format($vProdutos, 2, ',', '.') }}</div>
    </td>
  </tr>
  <tr>
    <td style="width:20%; border-right:0.75px solid #000;">
      <div class="lbl">Valor do frete</div>
      <div class="val-sm">0,00</div>
    </td>
    <td style="width:20%; border-right:0.75px solid #000;">
      <div class="lbl">Valor do seguro</div>
      <div class="val-sm">0,00</div>
    </td>
    <td style="width:20%; border-right:0.75px solid #000;">
      <div class="lbl">Desconto</div>
      <div class="val-sm">{{ number_format((float) ($nota->desconto ?? 0), 2, ',', '.') }}</div>
    </td>
    <td style="width:20%; border-right:0.75px solid #000;">
      <div class="lbl">Outras despesas</div>
      <div class="val-sm">0,00</div>
    </td>
    <td style="width:20%;">
      <div class="lbl">Valor do IPI</div>
      <div class="val-sm">0,00</div>
    </td>
  </tr>
  <tr>
    <td style="width:20%; border-right:0.75px solid #000;">
      <div class="lbl">Valor do PIS</div>
      <div class="val-sm">0,00</div>
    </td>
    <td style="width:20%; border-right:0.75px solid #000;">
      <div class="lbl">Valor do COFINS</div>
      <div class="val-sm">0,00</div>
    </td>
    <td style="width:20%; border-right:0.75px solid #000;" colspan="2">
      &nbsp;
    </td>
    <td style="width:20%;">
      <div class="lbl">Valor total da nota</div>
      <div class="val" style="font-size:11px;">{{ number_format($vTotalNota, 2, ',', '.') }}</div>
    </td>
  </tr>
</table>

<!-- Transportador -->
<div class="section-lbl">Transportador / Volumes Transportados</div>
<table class="grid">
  <tr>
    <td style="width:20%; border-right:0.75px solid #000;">
      <div class="lbl">Frete por conta</div>
      <div class="val-sm">9 - Sem Frete</div>
    </td>
    <td style="width:20%; border-right:0.75px solid #000;">
      <div class="lbl">Código ANTT</div>
      <div class="val-sm">-</div>
    </td>
    <td style="width:20%; border-right:0.75px solid #000;">
      <div class="lbl">Placa do veículo</div>
      <div class="val-sm">-</div>
    </td>
    <td style="width:20%; border-right:0.75px solid #000;">
      <div class="lbl">UF</div>
      <div class="val-sm">-</div>
    </td>
    <td style="width:20%;">
      <div class="lbl">CNPJ / CPF</div>
      <div class="val-sm">-</div>
    </td>
  </tr>
  <tr>
    <td style="width:60%; border-right:0.75px solid #000;" colspan="3">
      <div class="lbl">Nome / Razão Social</div>
      <div class="val-sm">-</div>
    </td>
    <td style="width:20%; border-right:0.75px solid #000;">
      <div class="lbl">Município / UF</div>
      <div class="val-sm">-</div>
    </td>
    <td style="width:20%;">
      <div class="lbl">Inscrição Estadual</div>
      <div class="val-sm">-</div>
    </td>
  </tr>
  <tr>
    <td style="width:20%; border-right:0.75px solid #000;">
      <div class="lbl">Quantidade</div>
      <div class="val-sm">-</div>
    </td>
    <td style="width:20%; border-right:0.75px solid #000;">
      <div class="lbl">Espécie</div>
      <div class="val-sm">-</div>
    </td>
    <td style="width:20%; border-right:0.75px solid #000;">
      <div class="lbl">Peso bruto</div>
      <div class="val-sm">-</div>
    </td>
    <td style="width:20%; border-right:0.75px solid #000;">
      <div class="lbl">Peso líquido</div>
      <div class="val-sm">-</div>
    </td>
    <td style="width:20%;">
      <div class="lbl">Marca / Numeração</div>
      <div class="val-sm">-</div>
    </td>
  </tr>
</table>

<!-- Itens -->
<div class="section-lbl">Dados dos Produtos / Serviços</div>
<table class="items-table">
  <thead>
    <tr>
      <th style="width:8%;">Código</th>
      <th style="width:23%;">Descrição</th>
      <th style="width:8%;">NCM/SH</th>
      <th style="width:7%;">O/CSOSN</th>
      <th style="width:6%;">CFOP</th>
      <th style="width:5%;">Un.</th>
      <th style="width:6%;">Qtde.</th>
      <th style="width:7%;">Vl. Unit.</th>
      <th style="width:7%;">Vl. Total</th>
      <th style="width:6%;">Vl. Desc.</th>
      <th style="width:6%;">B.Cálc ICMS</th>
      <th style="width:5%;">Vl. ICMS</th>
      <th style="width:5%;">Vl. IPI</th>
      <th style="width:6%;">Alíq ICMS/IPI</th>
    </tr>
  </thead>
  <tbody>
    @forelse($itens as $item)
    <tr>
      <td>{{ $item['codigo'] ?? '-' }}</td>
      <td>{{ $item['descricao'] }}</td>
      <td class="center">{{ $item['ncm'] }}</td>
      <td class="center">{{ $item['cst_csosn'] ?? '-' }}</td>
      <td class="center">{{ $item['cfop'] }}</td>
      <td class="center">{{ $item['unidade'] ?? 'UN' }}</td>
      <td class="num">{{ number_format((float) $item['quantidade'], 4, ',', '.') }}</td>
      <td class="num">{{ number_format((float) $item['valor_unitario'], 2, ',', '.') }}</td>
      <td class="num">{{ number_format((float) $item['valor_total'], 2, ',', '.') }}</td>
      <td class="num">0,00</td>
      <td class="num">0,00</td>
      <td class="num">0,00</td>
      <td class="num">0,00</td>
      <td class="center">0,00 / 0,00</td>
    </tr>
    @empty
    <tr><td colspan="14" style="text-align:center; padding:8px;">Nenhum item registrado nesta nota.</td></tr>
    @endforelse
  </tbody>
</table>

<!-- Dados adicionais -->
<div class="section-lbl">Dados Adicionais</div>
<table class="grid">
  <tr>
    <td style="width:65%; border-right:0.75px solid #000; min-height:34px;">
      <div class="lbl">Informações complementares</div>
      <div style="font-size:8px; margin-top:3px; line-height:1.4;">{{ $nota->informacoes_complementares_xml ?? $nota->observacoes ?? '-' }}</div>
    </td>
    <td style="width:35%;">
      <div class="lbl">Reservado ao Fisco</div>
      <div style="font-size:8px; margin-top:3px;">&nbsp;</div>
    </td>
  </tr>
</table>

<div class="footer-note">
  Documento emitido eletronicamente por {{ $emit['nome_fantasia'] ?? $emit['razao_social'] ?? '' }} em {{ now()->format('d/m/Y \à\s H:i') }}
</div>
