<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\SaasConfig;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\Response;

class MercadoPagoService
{
    private string $baseUrl;
    private string $accessToken;

    public function __construct()
    {
        $cfg = SaasConfig::get();
        $token = $cfg->getRawOriginal('mp_access_token');

        // Fallback para env caso a tabela ainda não tenha sido configurada
        $this->accessToken = $token ?: (string) config('services.mercadopago.access_token', '');

        $ambiente = $cfg->mp_ambiente ?? 'sandbox';
        $this->baseUrl = 'https://api.mercadopago.com';
        unset($ambiente);
    }

    public function criarCustomer(string $nome, string $email, string $cpfCnpj): array
    {
        $response = $this->http()->post('/v1/customers', [
            'email'           => $email,
            'first_name'      => $nome,
            'identification'  => [
                'type'   => strlen(preg_replace('/\D/', '', $cpfCnpj)) === 11 ? 'CPF' : 'CNPJ',
                'number' => preg_replace('/\D/', '', $cpfCnpj),
            ],
        ]);

        $this->throwIfFailed($response, 'criar customer');
        return $response->json();
    }

    /**
     * Cria uma assinatura (preapproval) recorrente mensal.
     */
    public function criarSubscription(string $customerId, float $valor, string $nextDate): array
    {
        $response = $this->http()->post('/preapproval', [
            'payer_id'            => $customerId,
            'auto_recurring'      => [
                'frequency'       => 1,
                'frequency_type'  => 'months',
                'transaction_amount' => $valor,
                'currency_id'     => 'BRL',
                'start_date'      => $nextDate . 'T00:00:00.000-03:00',
            ],
            'back_url'            => config('app.url'),
            'reason'              => 'Assinatura MecânicaPro',
            'status'              => 'authorized',
        ]);

        $this->throwIfFailed($response, 'criar subscription');
        return $response->json();
    }

    public function cancelarSubscription(string $subscriptionId): bool
    {
        $response = $this->http()->put("/preapproval/{$subscriptionId}", [
            'status' => 'cancelled',
        ]);
        return $response->successful();
    }

    /** Atualiza o valor mensal da assinatura (preapproval). */
    public function atualizarSubscription(string $subscriptionId, float $valor): array
    {
        $response = $this->http()->put("/preapproval/{$subscriptionId}", [
            'auto_recurring' => [
                'transaction_amount' => $valor,
                'currency_id'        => 'BRL',
            ],
        ]);

        $this->throwIfFailed($response, 'atualizar subscription');
        return $response->json();
    }

    public function buscarSubscription(string $subscriptionId): array
    {
        $response = $this->http()->get("/preapproval/{$subscriptionId}");
        $this->throwIfFailed($response, 'buscar subscription');
        return $response->json();
    }

    /**
     * Cria uma cobrança avulsa via Checkout Pro — gera um link de pagamento
     * (PIX, cartão ou boleto) com vencimento definido pela data de expiração.
     */
    public function criarCobrancaAvulsa(string $customerId, float $valor, string $vencimento): array
    {
        $response = $this->http()->post('/checkout/preferences', [
            'items' => [[
                'title'       => 'Cobrança avulsa — MecânicaPro',
                'quantity'    => 1,
                'currency_id' => 'BRL',
                'unit_price'  => $valor,
            ]],
            'external_reference' => $customerId,
            'expires'             => true,
            'expiration_date_to'  => $vencimento . 'T23:59:59.000-03:00',
        ]);

        $this->throwIfFailed($response, 'criar cobrança avulsa');
        $data = $response->json();

        return [
            'id'         => $data['id'] ?? null,
            'init_point' => $data['init_point'] ?? null,
        ];
    }

    private function http()
    {
        return Http::withToken($this->accessToken)
            ->baseUrl($this->baseUrl)
            ->acceptJson();
    }

    private function throwIfFailed(Response $response, string $operation): void
    {
        if ($response->failed()) {
            $msg = $response->json('message')
                ?? $response->json('error')
                ?? 'Erro desconhecido';
            throw new \RuntimeException("Mercado Pago — falha ao {$operation}: {$msg}");
        }
    }
}
