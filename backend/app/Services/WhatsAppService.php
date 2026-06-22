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

    /**
     * @return array{qrcode: string|null, error?: string}
     */
    public function qrCode(): array
    {
        if (!$this->config()) {
            return ['qrcode' => null, 'error' => 'Configuração do WhatsApp não encontrada. Salve a configuração primeiro.'];
        }

        try {
            // A Evolution não cria a instância automaticamente em /connect.
            // Se ela ainda não existe, criamos agora — o /create já devolve o QR.
            $qrCriacao = $this->garantirInstancia();
            if ($qrCriacao !== null) {
                return ['qrcode' => $qrCriacao];
            }

            // Instância já existia: solicita o connect para obter o QR atual.
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
            return ['qrcode' => null, 'error' => 'Falha ao conectar na Evolution (' . $this->config()->getRawOriginal('evolution_url') . '): ' . $e->getMessage()];
        }
    }

    /**
     * Garante que a instância exista na Evolution API.
     *
     * @return string|null  QR code em base64 se a instância foi criada agora;
     *                      null se ela já existia (use /instance/connect nesse caso).
     */
    private function garantirInstancia(): ?string
    {
        $instance = $this->instance();

        // Já existe? connectionState responde 2xx para instâncias existentes.
        $estado = $this->http()->get("/instance/connectionState/{$instance}");
        if ($estado->successful()) {
            return null;
        }

        // Cria a instância. Na Evolution v2, qrcode:true retorna o base64 na resposta.
        $resp = $this->http()->post('/instance/create', [
            'instanceName' => $instance,
            'integration'  => 'WHATSAPP-BAILEYS',
            'qrcode'       => true,
        ]);

        if (!$resp->successful()) {
            Log::warning("WhatsApp create instance falhou [{$instance}]: HTTP {$resp->status()} " . $resp->body());
            return null;
        }

        // Persiste o token (hash) gerado pela Evolution para esta instância.
        $hash = $resp->json('hash');
        $token = is_array($hash) ? ($hash['apikey'] ?? null) : $hash;
        if ($token && ($cfg = $this->config())) {
            $cfg->instance_token = $token;
            $cfg->save();
        }

        return $resp->json('qrcode.base64') ?? $resp->json('base64');
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

            // Credenciais válidas, porém a instância ainda não foi criada na Evolution.
            if ($resp->status() === 404 && str_contains($resp->body(), 'does not exist')) {
                return [
                    'ok'     => false,
                    'status' => 'nao_criada',
                    'error'  => 'Conexão com a Evolution OK, mas a instância "' . $instance . '" ainda não existe. Clique em "Escanear QR Code" para criá-la.',
                ];
            }

            return ['ok' => false, 'error' => "HTTP {$resp->status()}: " . $resp->body()];
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
     * Envia uma mensagem de teste para validar a integração.
     * Não exige o flag "ativo" (este controla apenas os alertas automáticos);
     * basta a configuração existir e a instância estar conectada.
     *
     * @return array{ok: bool, error?: string}
     */
    public function enviarTeste(string $telefone): array
    {
        $cfg = $this->config();
        if (!$cfg || empty($cfg->getRawOriginal('evolution_api_key'))) {
            return ['ok' => false, 'error' => 'Configuração do WhatsApp não encontrada. Salve a configuração primeiro.'];
        }

        $mensagem = "✅ *MecânicaPro*\n\nMensagem de teste. Se você recebeu isto, a integração com o WhatsApp está funcionando! 🚀";

        return $this->dispararMensagem($telefone, $mensagem, 'TESTE');
    }

    /**
     * Desconecta (logout) a sessão atual do WhatsApp, mantendo a instância.
     * Após o logout o status volta para "close" e o QR code fica disponível
     * novamente para reconectar.
     *
     * @return array{ok: bool, error?: string}
     */
    public function desconectar(): array
    {
        if (!$this->config()) {
            return ['ok' => false, 'error' => 'Configuração do WhatsApp não encontrada.'];
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

        // Grava log de envio
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
