<?php
declare(strict_types=1);

namespace App\Http\Controllers\SaaS;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class VpsController extends Controller
{
    public function status(): JsonResponse
    {
        return response()->json([
            'memoria' => $this->getMemoria(),
            'cpu'     => $this->getCpu(),
            'disco'   => $this->getDisco(),
            'uptime'  => $this->getUptime(),
            'loadavg' => $this->getLoadavg(),
        ]);
    }

    private function getMemoria(): array
    {
        $raw = (string) file_get_contents('/proc/meminfo');
        preg_match('/MemTotal:\s+(\d+)/', $raw, $total);
        preg_match('/MemAvailable:\s+(\d+)/', $raw, $available);

        $totalKb     = (int) ($total[1] ?? 0);
        $availableKb = (int) ($available[1] ?? 0);
        $usedKb      = $totalKb - $availableKb;

        return [
            'total_mb'      => round($totalKb / 1024, 1),
            'usado_mb'      => round($usedKb / 1024, 1),
            'disponivel_mb' => round($availableKb / 1024, 1),
            'percentual'    => $totalKb > 0 ? round(($usedKb / $totalKb) * 100, 1) : 0,
        ];
    }

    private function getCpu(): array
    {
        $cpuinfo = (string) file_get_contents('/proc/cpuinfo');
        preg_match('/model name\s*:\s*(.+)/m', $cpuinfo, $model);
        $nucleos = substr_count($cpuinfo, 'processor');
        $loadavg = $this->getLoadavg();

        return [
            'modelo'         => trim($model[1] ?? 'Desconhecido'),
            'nucleos'        => $nucleos,
            'uso_percentual' => min(round(($loadavg['1min'] / max($nucleos, 1)) * 100, 1), 100),
        ];
    }

    private function getDisco(): array
    {
        $output = [];
        exec('df -k / 2>/dev/null', $output);

        if (count($output) < 2) {
            return ['total_gb' => 0, 'usado_gb' => 0, 'disponivel_gb' => 0, 'percentual' => 0];
        }

        $parts      = preg_split('/\s+/', trim($output[1])) ?: [];
        $totalKb    = (int) ($parts[1] ?? 0);
        $usadoKb    = (int) ($parts[2] ?? 0);
        $disponKb   = (int) ($parts[3] ?? 0);
        $percentual = (int) str_replace('%', '', $parts[4] ?? '0');

        return [
            'total_gb'      => round($totalKb / 1024 / 1024, 1),
            'usado_gb'      => round($usadoKb / 1024 / 1024, 1),
            'disponivel_gb' => round($disponKb / 1024 / 1024, 1),
            'percentual'    => $percentual,
        ];
    }

    private function getUptime(): array
    {
        $raw     = (string) file_get_contents('/proc/uptime');
        $seconds = (int) explode(' ', $raw)[0];

        $days    = intdiv($seconds, 86400);
        $hours   = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return [
            'segundos' => $seconds,
            'dias'     => $days,
            'horas'    => $hours,
            'minutos'  => $minutes,
            'texto'    => "{$days}d {$hours}h {$minutes}m",
        ];
    }

    private function getLoadavg(): array
    {
        $raw   = (string) file_get_contents('/proc/loadavg');
        $parts = explode(' ', $raw);

        return [
            '1min'  => (float) ($parts[0] ?? 0),
            '5min'  => (float) ($parts[1] ?? 0),
            '15min' => (float) ($parts[2] ?? 0),
        ];
    }
}
