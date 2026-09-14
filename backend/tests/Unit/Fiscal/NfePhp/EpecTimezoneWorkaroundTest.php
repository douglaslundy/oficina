<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal\NfePhp;

use PHPUnit\Framework\TestCase;

/**
 * Bug real de produção (2026-09-14, achado investigando "por que a NF-e
 * está como Contingência"): `dhCont` (data/hora de entrada em contingência)
 * saiu gravado 3 HORAS À FRENTE do horário real — confirmado lendo o XML
 * real salvo (`dhEmi=13:30:39-03:00`, `dhCont=16:30:47-03:00`, quando o
 * horário real de Brasília no momento era ~13:30). A SEFAZ rejeitou a
 * retransmissão com "cStat=558: Data de entrada em contingência posterior
 * a data de recebimento" — consequência direta desse deslocamento.
 *
 * Causa raiz confirmada lendo o vendor
 * (`vendor/nfephp-org/sped-nfe/src/Factories/ContingencyNFe.php:62`):
 * `new \DateTime(gmdate("Y-m-d H:i:s", $contingency->timestamp))` —
 * `gmdate()` formata o timestamp Unix como dígitos de relógio em GMT/UTC
 * (corretos), mas o construtor de `\DateTime`, recebendo uma STRING sem
 * timezone explícito, interpreta esses dígitos usando o timezone PADRÃO do
 * processo PHP. Como `config('app.timezone')` deste projeto é
 * `America/Sao_Paulo` (-03:00), o Laravel já chamou
 * `date_default_timezone_set('America/Sao_Paulo')` no boot — então os
 * dígitos GMT (corretos) são reinterpretados como se já fossem hora LOCAL,
 * resultando num `DateTime` 3h à frente do valor real. Bug na biblioteca
 * vendor, não editável (seria sobrescrito no próximo `composer install`).
 *
 * Este teste prova a TÉCNICA do workaround em isolamento (sem precisar de
 * certificado real): reproduz o padrão exato do bug do vendor, confirma
 * que ele realmente produz o deslocamento errado, e confirma que rodar o
 * MESMO padrão com o timezone padrão temporariamente em UTC produz o
 * horário correto — é exatamente essa troca temporária que
 * `MotorNfe::tentarEpec()` agora faz ao redor da chamada real ao vendor
 * (`Tools::signNFe()` com `contingency->type='EPEC'`).
 */
class EpecTimezoneWorkaroundTest extends TestCase
{
    /** Reproduz o padrão exato de ContingencyNFe.php:62. */
    private function reproduzirPadraoDoVendor(int $timestamp): \DateTime
    {
        return new \DateTime(gmdate('Y-m-d H:i:s', $timestamp));
    }

    public function test_bug_do_vendor_produz_horario_3h_a_frente_com_timezone_sao_paulo(): void
    {
        $tzOriginal = date_default_timezone_get();
        date_default_timezone_set('America/Sao_Paulo');

        try {
            // 2026-09-14 13:30:47 America/Sao_Paulo == 2026-09-14 16:30:47 UTC.
            $timestamp = (new \DateTimeImmutable('2026-09-14 13:30:47', new \DateTimeZone('America/Sao_Paulo')))->getTimestamp();

            $dtComBug = $this->reproduzirPadraoDoVendor($timestamp);

            // O bug: em vez de reconstituir 13:30:47, o DateTime resultante
            // mostra 16:30:47 — os dígitos GMT relidos como se já fossem
            // horário local.
            $this->assertSame('16:30:47', $dtComBug->format('H:i:s'));
        } finally {
            date_default_timezone_set($tzOriginal);
        }
    }

    public function test_workaround_com_timezone_utc_temporario_corrige_o_horario(): void
    {
        $tzOriginal = date_default_timezone_get();
        date_default_timezone_set('America/Sao_Paulo');

        try {
            $timestamp = (new \DateTimeImmutable('2026-09-14 13:30:47', new \DateTimeZone('America/Sao_Paulo')))->getTimestamp();

            // Mesmo workaround usado em MotorNfe::tentarEpec(): timezone
            // padrão do processo em UTC só durante a chamada ao vendor.
            $tzAntesDoVendor = date_default_timezone_get();
            date_default_timezone_set('UTC');
            try {
                $dtCorrigido = $this->reproduzirPadraoDoVendor($timestamp);
            } finally {
                date_default_timezone_set($tzAntesDoVendor);
            }

            // Agora o DateTime é construído corretamente como UTC — quando
            // convertido de volta pra America/Sao_Paulo, bate com o horário
            // local real (13:30:47), não mais 16:30:47.
            $dtCorrigido->setTimezone(new \DateTimeZone('America/Sao_Paulo'));
            $this->assertSame('13:30:47', $dtCorrigido->format('H:i:s'));
        } finally {
            date_default_timezone_set($tzOriginal);
        }
    }
}
