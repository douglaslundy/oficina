<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\AlertaLog;
use App\Models\WhatsAppConfig;
use App\Tenancy\TenancyContext;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppService
{
    private ?WhatsAppConfig $config = null;

    private function config(): ?WhatsAppConfig
    {
        if ($this->config) return $this->config;
        $id = TenancyContext::get();
        if (!$id) return null;
        return $this->config = WhatsAppConfig::where('oficina_id', $id)->first();
    }

    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        $cfg = $this->config();
        return Http::baseUrl(rtrim((string)($cfg?->getRawOriginal('evolution_url') ?? ''), '/'))
            ->withHeaders(['apikey' => $cfg?->getRawOriginal('evolution_api_key') ?? ''])
            ->timeout(10);
    }

    private function instance(): string
    {
        return $this->config()?->instance_name ?? 'mecanicapro';
    }

    public function estaAtivo(): bool
    {
        $cfg = $this->config();
        return $cfg !== null && $cfg->ativo && !empty($cfg->getRawOriginal('evolution_api_key'));
    }

    public function statusInstancia(): array
    {
        try {
            $resp = $this->http()->get("/instance/connectionState/{$this->instance()}");
            if ($resp->successful()) {
                $data = $resp->json();
                return [
                    'status'  => $data['instance']['state'] ?? 'unknown',
                    'number'  => $data['instance']['wuid'] ?? null,
                ];
            }
        } catch (\Throwable $e) {
            Log::warning('WhatsApp status check failed: ' . $e->getMessage());
        }
        return ['status' => 'disconnected', 'number' => null];
    }

    public function qrCode(): ?string
    {
        try {
            $resp = $this->http()->get("/instance/connect/{$this->instance()}");
            if ($resp->successful()) {
                return $resp->json('base64') ?? $resp->json('qrcode.base64');
            }
        } catch (\Throwable $e) {
            Log::warning('WhatsApp QR code failed: ' . $e->getMessage());
        }
        return null;
    }

    public function testarConexao(string $url, string $apiKey, string $instance): array
    {
        try {
            $resp = Http::baseUrl(rtrim($url, '/'))
                ->withHeaders(['apikey' => $apiKey])
                ->timeout(8)
                ->get("/instance/connectionState/{$instance}");

            if ($resp->successful()) {
                $state = $resp->json('instance.state') ?? 'open';
                return ['ok' => true, 'status' => $state];
            }
            return ['ok' => false, 'error' => "HTTP {$resp->status()}: " . $resp->body()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function enviarMensagem(string $telefone, string $mensagem, string $tipo = 'MANUAL'): bool
    {
        if (!$this->estaAtivo()) return false;

        $numero = preg_replace('/\D/', '', $telefone);
        if (!str_starts_with($numero, '55')) {
            $numero = '55' . $numero;
        }

        $sucesso = false;
        $erro    = null;

        try {
            $resp = $this->http()->post("/message/sendText/{$this->instance()}", [
                'number'  => $numero,
                'text'    => $mensagem,
                'options' => ['delay' => 500],
            ]);

            $sucesso = $resp->successful();
            if (!$sucesso) {
                $erro = "HTTP {$resp->status()}: " . substr($resp->body(), 0, 300);
                Log::warning("WhatsApp send failed [{$numero}]: " . $resp->body());
            }
        } catch (\Throwable $e) {
            $erro = $e->getMessage();
            Log::error("WhatsApp exception [{$numero}]: " . $e->getMessage());
        }

        // Grava log de envio
        $oficinaId = TenancyContext::get();
        if ($oficinaId) {
            AlertaLog::create([
                'oficina_id'   => $oficinaId,
                'tipo'         => $tipo,
                'destinatario' => $numero,
                'mensagem'     => $mensagem,
                'sucesso'      => $sucesso,
                'erro'         => $erro,
            ]);
        }

        return $sucesso;
    }
}
