<?php
declare(strict_types=1);

namespace App\Services\Fiscal\Pdf;

use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Gera o PDF de cupom (DANFE NFC-e ou cupom não fiscal) no papel escolhido na
 * configuração da empresa: bobina térmica de 80 mm ou folha A4.
 *
 * 80 mm: o cupom térmico não tem altura fixa. A página é renderizada uma vez
 * numa altura grande só pra medir onde o conteúdo termina e uma segunda vez na
 * altura exata — sem folha em branco sobrando na bobina e sem quebrar em duas
 * páginas (estimar por contagem de itens falhava com descrições longas).
 * A4: o mesmo layout, em coluna de 80 mm centralizada.
 */
class CupomPdfService
{
    public const IMPRESSORA_80MM = '80MM';
    public const IMPRESSORA_A4   = 'A4';

    private const LARGURA_80MM_PT = 226.77; // 80 mm
    private const ALTURA_MEDICAO  = 6000.0;
    private const MARGEM_FINAL_PT = 14.0;

    public static function impressoraValida(?string $valor): string
    {
        return $valor === self::IMPRESSORA_A4 ? self::IMPRESSORA_A4 : self::IMPRESSORA_80MM;
    }

    /** @param array<string, mixed> $dados */
    public function gerar(string $view, array $dados, ?string $impressora): string
    {
        $impressora = self::impressoraValida($impressora);
        $dados['papel'] = $impressora;

        if ($impressora === self::IMPRESSORA_A4) {
            return Pdf::loadView($view, $dados)->setPaper('a4', 'portrait')->output();
        }

        $fimDoConteudo = 0.0;
        $medicao = Pdf::loadView($view, $dados)->setPaper([0, 0, self::LARGURA_80MM_PT, self::ALTURA_MEDICAO]);
        $medicao->getDomPDF()->setCallbacks([[
            'event' => 'end_frame',
            'f'     => function ($frame) use (&$fimDoConteudo): void {
                $y = (float) $frame->get_position('y') + (float) $frame->get_margin_height();
                if ($y > $fimDoConteudo) {
                    $fimDoConteudo = $y;
                }
            },
        ]]);
        $medicao->output();

        $altura = max(120.0, ceil($fimDoConteudo + self::MARGEM_FINAL_PT));

        return Pdf::loadView($view, $dados)->setPaper([0, 0, self::LARGURA_80MM_PT, $altura])->output();
    }
}
