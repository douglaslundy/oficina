<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Configuracao;
use App\Models\NotaFiscal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NfeService
{
    public function proximoNumeroNf(): int
    {
        return DB::transaction(function () {
            $config = Configuracao::lockForUpdate()->first();
            if (!$config) throw new \Exception('Configurações da empresa não encontradas.');
            $numero = $config->proximo_numero_nf;
            $config->increment('proximo_numero_nf');
            return $numero;
        });
    }

    public function emitir(NotaFiscal $nota): array
    {
        $config = Configuracao::first();
        $apiKey = config('services.nfeio.api_key', '');
        $url    = config('services.nfeio.url', '');

        if (empty($apiKey)) {
            // Simulate authorization for development/homologation without API key
            Log::info('NfeService: simulando emissão em modo desenvolvimento (sem API key).');
            return [
                'status'      => 'AUTORIZADA',
                'chave'       => 'SIMULADO-' . strtoupper(substr(md5(uniqid()), 0, 20)),
                'protocolo'   => 'SIMUL-' . now()->format('YmdHis'),
                'xml_retorno' => '<simulacao>Emissão simulada — ambiente de desenvolvimento</simulacao>',
            ];
        }

        $cnpj = preg_replace('/\D/', '', $config?->cnpj ?? '');
        $response = Http::withBasicAuth($apiKey, '')
            ->post("{$url}/companies/{$cnpj}/serviceinvoices", [
                'cityServiceCode'   => $config?->cnae,
                'description'       => $nota->observacoes ?? 'Serviços automotivos',
                'servicesAmount'    => $nota->valor_total,
                'borrower'          => [
                    'name'             => $nota->cliente?->nome,
                    'federalTaxNumber' => preg_replace('/\D/', '', $nota->cliente?->cpf_cnpj ?? ''),
                ],
            ]);

        if ($response->failed()) {
            throw new \Exception('Erro na SEFAZ: ' . ($response->json('message') ?? 'Erro desconhecido'));
        }

        return [
            'status'      => 'AUTORIZADA',
            'chave'       => $response->json('accessKey') ?? '',
            'protocolo'   => (string)($response->json('number') ?? ''),
            'xml_retorno' => $response->body(),
        ];
    }
}
