<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\SaasConfig;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * WhatsApp da PLATAFORMA (não de uma oficina) — usado só pra notificar o
 * admin do SaaS de eventos internos (ex: pagamento recebido). Instância
 * própria (`whatsapp_admin_*` em saas_config), independente das instâncias
 * por oficina em `whatsapp_configs`, que são tenant-scoped e não servem pra
 * esse caso (o admin do SaaS não é uma oficina).
 */
class AdminWhatsAppService
{
    private function cfg(): SaasConfig
    {
        return SaasConfig::get();
    }

    private function http(): PendingRequest
    {
        $cfg = $this->cfg();
        return Http::baseUrl(rtrim((string) ($cfg->getRawOriginal('evolution_url') ?? ''), '/'))
            ->withHeaders(['apikey' => $cfg->getRawOriginal('evolution_api_key') ?? ''])
            ->timeout(10);
    }

    private function instance(): string
    {
        return $this->cfg()->whatsapp_admin_instance ?: 'mecanicapro-plataforma';
    }

    public function credenciaisConfiguradas(): bool
    {
        $cfg = $this->cfg();
        return !empty($cfg->getRawOriginal('evolution_url')) && !empty($cfg->getRawOriginal('evolution_api_key'));
    }

    public function estaAtivo(): bool
    {
        $cfg = $this->cfg();
        return $this->credenciaisConfiguradas() && (bool) $cfg->whatsapp_admin_ativo && !empty($cfg->whatsapp_admin_numero);
    }

    public function statusInstancia(): array
    {
        if (!$this->credenciaisConfiguradas()) {
            return ['status' => 'disconnected', 'number' => null];
        }
        try {
            $resp = $this->http()->get("/instance/connectionState/{$this->instance()}");
            if ($resp->successful()) {
                $data = $resp->json();
                return [
                    'status' => $data['instance']['state'] ?? 'unknown',
                    'number' => $data['instance']['wuid'] ?? null,
                ];
            }
        } catch (\Throwable $e) {
            Log::warning('AdminWhatsApp status check failed: ' . $e->getMessage());
        }
        return ['status' => 'disconnected', 'number' => null];
    }

    /** @return array{qrcode: string|null, error?: string} */
    public function qrCode(): array
    {
        if (!$this->credenciaisConfiguradas()) {
            return ['qrcode' => null, 'error' => 'Configure a Evolution API (URL e API Key) em Configurações antes de conectar o WhatsApp do admin.'];
        }

        try {
            $qrCriacao = $this->garantirInstancia();
            if ($qrCriacao !== null) {
                return ['qrcode' => $qrCriacao];
            }

            $resp = $this->http()->get("/instance/connect/{$this->instance()}");
            if ($resp->successful()) {
                $qr = $resp->json('base64') ?? $resp->json('qrcode.base64');
                if ($qr) {
                    return ['qrcode' => $qr];
                }
                return ['qrcode' => null, 'error' => 'A Evolution não retornou QR code (a instância pode já estar conectada).'];
            }

            Log::warning("AdminWhatsApp connect falhou [{$this->instance()}]: HTTP {$resp->status()} " . $resp->body());
            return ['qrcode' => null, 'error' => "Evolution retornou HTTP {$resp->status()}: " . substr($resp->body(), 0, 200)];
        } catch (\Throwable $e) {
            Log::warning('AdminWhatsApp QR code failed: ' . $e->getMessage());
            return ['qrcode' => null, 'error' => 'Falha ao conectar na Evolution: ' . $e->getMessage()];
        }
    }

    private function garantirInstancia(): ?string
    {
        $instance = $this->instance();

        $estado = $this->http()->get("/instance/connectionState/{$instance}");
        if ($estado->successful()) {
            return null;
        }

        $resp = $this->http()->post('/instance/create', [
            'instanceName' => $instance,
            'integration'  => 'WHATSAPP-BAILEYS',
            'qrcode'       => true,
        ]);

        if (!$resp->successful()) {
            Log::warning("AdminWhatsApp create instance falhou [{$instance}]: HTTP {$resp->status()} " . $resp->body());
            return null;
        }

        $hash  = $resp->json('hash');
        $token = is_array($hash) ? ($hash['apikey'] ?? null) : $hash;
        if ($token) {
            $this->cfg()->update(['whatsapp_admin_instance_token' => $token]);
        }

        return $resp->json('qrcode.base64') ?? $resp->json('base64');
    }

    /** @return array{ok: bool, error?: string} */
    public function desconectar(): array
    {
        try {
            $resp = $this->http()->delete("/instance/logout/{$this->instance()}");
            if ($resp->successful()) {
                return ['ok' => true];
            }
            Log::warning("AdminWhatsApp logout falhou [{$this->instance()}]: HTTP {$resp->status()} " . $resp->body());
            return ['ok' => false, 'error' => "Evolution retornou HTTP {$resp->status()}: " . substr($resp->body(), 0, 200)];
        } catch (\Throwable $e) {
            Log::warning('AdminWhatsApp logout failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Falha ao desconectar: ' . $e->getMessage()];
        }
    }

    /** @return array{ok: bool, error?: string} */
    public function enviarTeste(): array
    {
        if (empty($this->cfg()->whatsapp_admin_numero)) {
            return ['ok' => false, 'error' => 'Configure o número de destino das notificações antes de testar.'];
        }

        $mensagem = "✅ *MecânicaPro*\n\nMensagem de teste do WhatsApp do admin da plataforma. Se você recebeu isto, a integração está funcionando! 🚀";

        return $this->enviarMensagemForcado($mensagem);
    }

    /** Envia respeitando o flag `whatsapp_admin_ativo` — usado nas notificações automáticas. */
    public function enviarMensagem(string $mensagem): array
    {
        if (!$this->estaAtivo()) {
            return ['ok' => false, 'error' => 'WhatsApp do admin não está configurado/ativo.'];
        }
        return $this->enviarMensagemForcado($mensagem);
    }

    /** Envia ignorando o flag `whatsapp_admin_ativo` — usado só pelo botão de teste. */
    private function enviarMensagemForcado(string $mensagem): array
    {
        $numero = preg_replace('/\D/', '', (string) $this->cfg()->whatsapp_admin_numero);
        if ($numero === '') {
            return ['ok' => false, 'error' => 'Número de destino não configurado.'];
        }
        if (!str_starts_with($numero, '55')) {
            $numero = '55' . $numero;
        }

        try {
            $resp = $this->http()->post("/message/sendText/{$this->instance()}", [
                'number'  => $numero,
                'text'    => $mensagem,
                'options' => ['delay' => 500],
            ]);

            if ($resp->successful()) {
                return ['ok' => true];
            }

            Log::warning("AdminWhatsApp send failed [{$numero}]: " . $resp->body());
            return ['ok' => false, 'error' => "HTTP {$resp->status()}: " . substr($resp->body(), 0, 300)];
        } catch (\Throwable $e) {
            Log::error("AdminWhatsApp exception [{$numero}]: " . $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
