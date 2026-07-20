<?php
declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\Response;

class AsaasService
{
    private string $baseUrl;
    private string $apiKey;

    public function __construct()
    {
        $this->baseUrl = config('services.asaas.url', 'https://sandbox.asaas.com/api/v3');
        $this->apiKey  = config('services.asaas.api_key', '');
    }

    public function criarCustomer(string $nome, string $cpfCnpj, string $email): array
    {
        $response = Http::withHeaders(['access_token' => $this->apiKey])
            ->post("{$this->baseUrl}/customers", [
                'name'                 => $nome,
                'cpfCnpj'             => preg_replace('/\D/', '', $cpfCnpj),
                'email'               => $email,
                'notificationDisabled' => true,
            ]);

        $this->throwIfFailed($response, 'criar customer');
        return $response->json();
    }

    public function criarSubscription(string $customerId, float $value, string $nextDueDate): array
    {
        $response = Http::withHeaders(['access_token' => $this->apiKey])
            ->post("{$this->baseUrl}/subscriptions", [
                'customer'    => $customerId,
                'billingType' => 'BOLETO',
                'value'       => $value,
                'nextDueDate' => $nextDueDate,
                'cycle'       => 'MONTHLY',
            ]);

        $this->throwIfFailed($response, 'criar subscription');
        return $response->json();
    }

    public function cancelarSubscription(string $subscriptionId): bool
    {
        $response = Http::withHeaders(['access_token' => $this->apiKey])
            ->delete("{$this->baseUrl}/subscriptions/{$subscriptionId}");

        return $response->successful();
    }

    /** Atualiza o valor mensal da assinatura. */
    public function atualizarSubscription(string $subscriptionId, float $value): array
    {
        $response = Http::withHeaders(['access_token' => $this->apiKey])
            ->put("{$this->baseUrl}/subscriptions/{$subscriptionId}", [
                'value' => $value,
            ]);

        $this->throwIfFailed($response, 'atualizar subscription');
        return $response->json();
    }

    public function buscarSubscription(string $subscriptionId): array
    {
        $response = Http::withHeaders(['access_token' => $this->apiKey])
            ->get("{$this->baseUrl}/subscriptions/{$subscriptionId}");

        $this->throwIfFailed($response, 'buscar subscription');
        return $response->json();
    }

    public function buscarPagamentos(string $subscriptionId, int $limit = 10): array
    {
        $response = Http::withHeaders(['access_token' => $this->apiKey])
            ->get("{$this->baseUrl}/payments", [
                'subscription' => $subscriptionId,
                'limit'        => $limit,
                'sort'         => 'dueDate',
                'order'        => 'desc',
            ]);

        $this->throwIfFailed($response, 'buscar pagamentos');
        return $response->json('data', []);
    }

    public function criarCobrancaAvulsa(string $customerId, float $value, string $dueDate, ?string $externalReference = null): array
    {
        $payload = [
            'customer'    => $customerId,
            'billingType' => 'BOLETO',
            'value'       => $value,
            'dueDate'     => $dueDate,
            'description' => 'Cobrança avulsa — MecânicaPro',
        ];

        if ($externalReference !== null) {
            $payload['externalReference'] = $externalReference;
        }

        $response = Http::withHeaders(['access_token' => $this->apiKey])
            ->post("{$this->baseUrl}/payments", $payload);

        $this->throwIfFailed($response, 'criar cobrança avulsa');
        return $response->json();
    }

    public function cancelarPagamento(string $paymentId): bool
    {
        $response = Http::withHeaders(['access_token' => $this->apiKey])
            ->delete("{$this->baseUrl}/payments/{$paymentId}");

        return $response->successful();
    }

    /** Consulta o status atual de um pagamento direto na API — usado pra conciliação manual/ativa (não depende do webhook ter chegado). */
    public function buscarPagamento(string $paymentId): array
    {
        $response = Http::withHeaders(['access_token' => $this->apiKey])
            ->get("{$this->baseUrl}/payments/{$paymentId}");

        $this->throwIfFailed($response, 'buscar pagamento');
        return $response->json();
    }

    /** Estorna (total) um pagamento já confirmado/recebido. */
    public function estornarPagamento(string $paymentId): array
    {
        $response = Http::withHeaders(['access_token' => $this->apiKey])
            ->post("{$this->baseUrl}/payments/{$paymentId}/refund");

        $this->throwIfFailed($response, 'estornar pagamento');
        return $response->json();
    }

    public function buscarCustomer(string $customerId): array
    {
        $response = Http::withHeaders(['access_token' => $this->apiKey])
            ->get("{$this->baseUrl}/customers/{$customerId}");

        $this->throwIfFailed($response, 'buscar customer');
        return $response->json();
    }

    private function throwIfFailed(Response $response, string $operation): void
    {
        if ($response->failed()) {
            $msg = $response->json('message')
                ?? $response->json('errors.0.description')
                ?? 'Erro desconhecido';
            throw new \RuntimeException("Asaas — falha ao {$operation}: {$msg}");
        }
    }
}
