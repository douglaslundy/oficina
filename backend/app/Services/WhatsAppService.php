<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\AlertaLog;
use App\Models\Oficina;
use App\Models\SaasConfig;
use App\Models\WhatsAppConfig;
use App\Tenancy\TenancyContext;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppService
{
    private ?WhatsAppConfig $config = null;

    /** Configuração por tenant (instance_name, ativo). */
    private function tenantConfig(): ?WhatsAppConfig
    {
        if ($this->config) return $this->config;
        $id = TenancyContext::get();
        if (!$id) return null;
        return $this->config = WhatsAppConfig::where('oficina_id', $id)->first();
    }

    /** Credenciais globais da Evolution API (gerenciadas pelo SaaS Admin). */
    private function saasConfig(): SaasConfig
    {
        return SaasConfig::get();
    }

    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        $saas = $this->saasConfig();
        return Http::baseUrl(rtrim((string)($saas->getRawOriginal('evolution_url') ?? ''), '/'))
            ->withHeaders(['apikey' => $saas->getRawOriginal('evolution_api_key') ?? ''])
            ->timeout(10);
    }

    private function instance(): string
    {
        return $this->tenantConfig()?->instance_name ?? 'mecanicapro';
    }

    public function estaAtivo(): bool
    {
        $cfg  = $this->tenantConfig();
        $saas = $this->saasConfig();
        return $cfg !== null
            && $cfg->ativo
            && !empty($saas->getRawOriginal('evolution_url'))
            && !empty($saas->getRawOriginal('evolution_api_key'));
    }

    /** Verifica se as credenciais globais (SaaS) estão configuradas. */
    public function credenciaisConfiguradas(): bool
    {
        $saas = $this->saasConfig();
        return !empty($saas->getRawOriginal('evolution_url'))
            && !empty($saas->getRawOriginal('evolution_api_key'));
    }

    public function statusInstancia(): array
    {
        if (!$this->credenciaisConfiguradas() || !$this->tenantConfig()) {
            return ['status' => 'disconnected', 'number' => null];
        }
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

    /**
     * @return array{qrcode: string|null, error?: string}
     */
    public function qrCode(): array
    {
        if (!$this->credenciaisConfiguradas()) {
            return ['qrcode' => null, 'error' => 'A Evolution API ainda não foi configurada pelo administrador da plataforma.'];
        }

        $oficinaId = TenancyContext::get();
        if (!$oficinaId) {
            return ['qrcode' => null, 'error' => 'Contexto de oficina não encontrado.'];
        }

        // Auto-cria o WhatsAppConfig do tenant se ainda não existir.
        if (!$this->tenantConfig()) {
            $slug = Oficina::find($oficinaId)?->slug ?? substr($oficinaId, 0, 8);
            $cfg  = WhatsAppConfig::create([
                'oficina_id'    => $oficinaId,
                'instance_name' => 'mec-' . $slug,
                'ativo'         => false,
            ]);
            $this->config = $cfg;
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

            Log::warning("WhatsApp connect falhou [{$this->instance()}]: HTTP {$resp->status()} " . $resp->body());
            return ['qrcode' => null, 'error' => "Evolution retornou HTTP {$resp->status()}: " . substr($resp->body(), 0, 200)];
        } catch (\Throwable $e) {
            Log::warning('WhatsApp QR code failed: ' . $e->getMessage());
            $url = $this->saasConfig()->getRawOriginal('evolution_url') ?? '';
            return ['qrcode' => null, 'error' => "Falha ao conectar na Evolution ({$url}): " . $e->getMessage()];
        }
    }

    /**
     * Garante que a instância exista na Evolution API.
     *
     * @return string|null  QR code em base64 se a instância foi criada agora;
     *                      null se ela já existia.
     */
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
            Log::warning("WhatsApp create instance falhou [{$instance}]: HTTP {$resp->status()} " . $resp->body());
            return null;
        }

        $hash  = $resp->json('hash');
        $token = is_array($hash) ? ($hash['apikey'] ?? null) : $hash;
        if ($token && ($cfg = $this->tenantConfig())) {
            $cfg->instance_token = $token;
            $cfg->save();
        }

        return $resp->json('qrcode.base64') ?? $resp->json('base64');
    }

    public function testarConexao(string $url, string $apiKey): array
    {
        try {
            $resp = Http::baseUrl(rtrim($url, '/'))
                ->withHeaders(['apikey' => $apiKey])
                ->timeout(8)
                ->get('/instance/fetchInstances');

            if ($resp->successful()) {
                return ['ok' => true, 'status' => 'conectado'];
            }

            // 401 = API Key inválida
            if ($resp->status() === 401) {
                return ['ok' => false, 'error' => 'API Key inválida ou sem permissão.'];
            }

            return ['ok' => false, 'error' => "HTTP {$resp->status()}: " . substr($resp->body(), 0, 200)];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function enviarMensagem(string $telefone, string $mensagem, string $tipo = 'MANUAL', ?string $destinatarioTipo = null): bool
    {
        if (!$this->estaAtivo()) return false;

        return $this->dispararMensagem($telefone, $mensagem, $tipo, $destinatarioTipo)['ok'];
    }

    /**
     * Envia uma mensagem de teste. Não exige o flag "ativo".
     *
     * @return array{ok: bool, error?: string}
     */
    public function enviarTeste(string $telefone): array
    {
        if (!$this->credenciaisConfiguradas()) {
            return ['ok' => false, 'error' => 'A Evolution API ainda não foi configurada pelo administrador da plataforma.'];
        }

        $cfg = $this->tenantConfig();
        if (!$cfg) {
            return ['ok' => false, 'error' => 'WhatsApp ainda não configurado para esta oficina. Escaneie o QR code primeiro.'];
        }

        $mensagem = "✅ *MecânicaPro*\n\nMensagem de teste. Se você recebeu isto, a integração com o WhatsApp está funcionando! 🚀";

        return $this->dispararMensagem($telefone, $mensagem, 'TESTE');
    }

    /**
     * Desconecta a sessão atual do WhatsApp, mantendo a instância.
     *
     * @return array{ok: bool, error?: string}
     */
    public function desconectar(): array
    {
        if (!$this->tenantConfig()) {
            return ['ok' => false, 'error' => 'WhatsApp ainda não configurado para esta oficina.'];
        }

        try {
            $resp = $this->http()->delete("/instance/logout/{$this->instance()}");
            if ($resp->successful()) {
                return ['ok' => true];
            }
            Log::warning("WhatsApp logout falhou [{$this->instance()}]: HTTP {$resp->status()} " . $resp->body());
            return ['ok' => false, 'error' => "Evolution retornou HTTP {$resp->status()}: " . substr($resp->body(), 0, 200)];
        } catch (\Throwable $e) {
            Log::warning('WhatsApp logout failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Falha ao desconectar: ' . $e->getMessage()];
        }
    }

    /**
     * Dispara uma mensagem de texto pela Evolution e registra no log de alertas.
     *
     * @return array{ok: bool, error?: string}
     */
    private function dispararMensagem(string $telefone, string $mensagem, string $tipo, ?string $destinatarioTipo = null): array
    {
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

        $oficinaId = TenancyContext::get();
        if ($oficinaId) {
            AlertaLog::create([
                'oficina_id'        => $oficinaId,
                'tipo'              => $tipo,
                'canal'             => 'WHATSAPP',
                'destinatario'      => $numero,
                'destinatario_tipo' => $destinatarioTipo,
                'mensagem'          => $mensagem,
                'sucesso'           => $sucesso,
                'erro'              => $erro,
            ]);
        }

        return $sucesso ? ['ok' => true] : ['ok' => false, 'error' => $erro ?? 'Falha ao enviar a mensagem.'];
    }
}
