<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<title>{{ $titulo }}</title>
<style>
  /* DANFSe v2.0 — clone do PDF oficial (ver App\Services\Fiscal\Pdf\DanfseRenderer). Tudo em pt, posição absoluta. */
  @page { margin: 0; size: 595pt 842pt; }
  * { margin: 0; padding: 0; }
  body { margin: 0; padding: 0; font-family: Helvetica, Arial, sans-serif; color: #000; }
  .abs { position: absolute; }
  .t { position: absolute; white-space: nowrap; line-height: 1; }
  .b { font-weight: bold; }
</style>
</head>
<body>
@php
    // Distância do topo da caixa (line-height: 1) até a linha base, por tamanho de fonte (calibrado contra o PDF oficial).
    $K = 0.79;
@endphp

{{-- moldura externa (traço de 1pt centrado em x=5..590, y=5..837) --}}
<div class="abs" style="left:{{ $borda['x'] }}pt; top:{{ $borda['y'] }}pt; width:{{ $borda['w'] }}pt; height:{{ $borda['h'] }}pt; border:1pt solid #000;"></div>

{{-- células cinza --}}
@foreach($retangulos as $r)
<div class="abs" style="left:{{ $r['x'] }}pt; top:{{ $r['y'] }}pt; width:{{ $r['w'] }}pt; height:{{ $r['h'] }}pt; background:#f2f2f2;"></div>
@endforeach

{{-- filetes horizontais de 0,5pt --}}
@foreach($filetes as $f)
<div class="abs" style="left:{{ $f['x'] }}pt; top:{{ $f['y'] - 0.25 }}pt; width:{{ $f['w'] }}pt; height:0.5pt; background:#000;"></div>
@endforeach

{{-- caixa do rodapé --}}
<div class="abs" style="left:{{ $rodape['x'] }}pt; top:{{ $rodape['y'] }}pt; width:{{ $rodape['w'] }}pt; height:{{ $rodape['h'] }}pt; border:1pt solid #000;"></div>
<div class="abs" style="left:{{ $rodape['sep1'] - 0.5 }}pt; top:{{ $rodape['y'] + 0.5 }}pt; width:1pt; height:21.1pt; background:#000;"></div>
<div class="abs" style="left:{{ $rodape['sep2'] - 0.5 }}pt; top:{{ $rodape['y'] + 0.5 }}pt; width:1pt; height:21.1pt; background:#000;"></div>

{{-- imagens: logo (canto superior esquerdo) e QR code --}}
<img class="abs" src="{{ $imagens['logo'] }}" style="left:11.91pt; top:10.32pt; width:115.65pt; height:22.92pt;">
@if(!empty($imagens['qr']))
<img class="abs" src="{{ $imagens['qr'] }}" style="left:491.99pt; top:44.76pt; width:45pt; height:45pt;">
@endif

{{-- textos, posicionados pela linha base --}}
@foreach($textos as $t)
<div class="t {{ $t['bold'] ? 'b' : '' }}" style="left:{{ $t['x'] }}pt; top:{{ round($t['y'] - $t['size'] * $K - 0.16, 2) }}pt; font-size:{{ $t['size'] }}pt; @if($t['w']) width:{{ $t['w'] }}pt; text-align:{{ $t['align'] }}; @endif">{{ $t['t'] }}</div>
@endforeach

</body>
</html>
