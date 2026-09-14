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
  .contingencia-badge { display: block; text-align: center; font-size: 9px; font-weight: 900; padding: 4px; border: 1.25px dashed #000; margin-bottom: 6px; letter-spacing: .04em; }

  .items-table { border: 0.75px solid #000; margin-top: -1px; }
  .items-table th { background: #eee; font-size: 5.6px; text-transform: uppercase; padding: 4px 2px; border: 0.75px solid #000; text-align: left; }
  .items-table td { font-size: 7px; padding: 3px 2px; border: 0.75px solid #000; }
  .items-table .num { text-align: right; }
  .items-table .center { text-align: center; }

  .section-lbl { font-size: 7px; font-weight: 900; text-transform: uppercase; letter-spacing: .04em; padding: 3px 0 3px 2px; border: 0.75px solid #000; border-bottom: none; background: #f2f2f2; }

  .footer-note { font-size: 7px; text-align: center; color: #333; margin-top: 8px; }
  .homolog-note { font-size: 8px; font-weight: 700; text-align: center; margin: 4px 0; }
</style>
</head>
<body>

@include('pdf.partials.danfe_corpo', [
  'nota' => $nota,
  'empresa' => $empresa ?? [],
  'itens' => $itens ?? [],
  'barcodeDataUri' => $barcodeDataUri ?? null,
])

</body>
</html>
