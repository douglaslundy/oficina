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
