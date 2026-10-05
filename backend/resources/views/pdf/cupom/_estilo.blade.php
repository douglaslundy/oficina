<style>
  @if(($papel ?? '80MM') === 'A4')
  @page { margin: 12mm; }
  .cupom { width: 80mm; margin: 0 auto; }
  @else
  @page { margin: 3mm 3mm 0 3mm; }
  .cupom { width: 100%; }
  @endif
  * { margin: 0; padding: 0; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 7.2pt; line-height: 1.25; color: #000; }
  .c { text-align: center; }
  .r { text-align: right; }
  .b { font-weight: 700; }
  .g { font-size: 8.6pt; }
  .gg { font-size: 10pt; }
  .sep { border-top: 1px dashed #000; margin: 3px 0; height: 0; }
  table { width: 100%; border-collapse: collapse; }
  td { vertical-align: top; padding: 0; }
  .item td { padding-top: 1.5px; }
  .chave { font-size: 7.6pt; letter-spacing: 0.2px; word-spacing: 2px; }
  .qr { text-align: center; margin: 3px 0; }
  .qr img { width: 105pt; height: 105pt; }
  .aviso { text-align: center; font-weight: 700; margin: 3px 0; }
</style>
