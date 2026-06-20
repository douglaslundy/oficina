<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Configuracao;
use App\Models\Oficina;
use App\Models\Plano;
use App\Models\SaasConfig;
use App\Models\Usuario;
use App\Tenancy\TenancyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class TenantProvisionService
{
    public function __construct(
        private readonly AsaasService $asaas,
        private readonly MercadoPagoService $mercadoPago,
    ) {}

    public function provisionar(array $data): Oficina
    {
        return DB::transaction(function () use ($data) {
            $plano = Plano::findOrFail($data['plano_id']);

            // 1. Create oficina
            $oficina = Oficina::create([
                'nome'        => $data['nome'],
                'cnpj'        => preg_replace('/\D/', '', $data['cnpj']),
                'slug'        => $data['slug'],
                'plano_id'    => $plano->id,
                'status'      => 'ATIVA',
                'admin_email' => $data['admin_email'],
            ]);

            // 2. Create admin usuario scoped to tenant
            TenancyContext::set($oficina->id);

            $senha = $data['admin_senha'] ?? Str::random(12);

            Usuario::create([
                'nome'       => $data['admin_nome'],
                'email'      => $data['admin_email'],
                'cpf'        => preg_replace('/\D/', '', $data['admin_cpf']),
                'role'       => 'ADMIN',
                'status'     => 'ATIVO',
                'senha_hash' => Hash::make($senha),
                // oficina_id auto-set by HasTenantScope
            ]);

            // 3. Create empty Configuracao
            Configuracao::create([
                'razao_social'          => $data['nome'],
                'ambiente_fiscal'       => 'HOMOLOGACAO',
                'estoque_limite_padrao' => 5,
                'proximo_numero_nf'     => 1,
                // oficina_id auto-set by HasTenantScope
            ]);

            TenancyContext::clear();

            // 4. Call payment gateway for paid plans (non-blocking)
            $gateway = SaasConfig::get()->gateway_preferido ?? 'ASAAS';
            if ((float) $plano->preco_mensal > 0) {
                try {
                    if ($gateway === 'MERCADOPAGO') {
                        $customer = $this->mercadoPago->criarCustomer(
                            $data['admin_nome'],
                            $data['admin_email'],
                            $data['cnpj'],
                        );
                        $subscription = $this->mercadoPago->criarSubscription(
                            $customer['id'],
                            (float) $plano->preco_mensal,
                            now()->addDay()->format('Y-m-d')
                        );
                        $oficina->update([
                            'gateway'           => 'MERCADOPAGO',
                            'mp_customer_id'    => $customer['id'],
                            'mp_subscription_id'=> $subscription['id'],
                        ]);
                    } else {
                        $customer = $this->asaas->criarCustomer(
                            $data['admin_nome'],
                            $data['cnpj'],
                            $data['admin_email']
                        );
                        $subscription = $this->asaas->criarSubscription(
                            $customer['id'],
                            (float) $plano->preco_mensal,
                            now()->addDay()->format('Y-m-d')
                        );
                        $oficina->update([
                            'gateway'               => 'ASAAS',
                            'asaas_customer_id'     => $customer['id'],
                            'asaas_subscription_id' => $subscription['id'],
                        ]);
                    }
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning("Gateway provisioning skipped ({$gateway}): {$e->getMessage()}");
                }
            }

            // 5. Send welcome email (non-blocking)
            try {
                Mail::raw(
                    "Bem-vindo ao MecânicaPro!\n\nSua oficina foi criada.\nE-mail: {$data['admin_email']}\nSenha: {$senha}\n\nAcesse: " . config('app.url', 'http://localhost:3000'),
                    fn($msg) => $msg->to($data['admin_email'], $data['admin_nome'])
                                    ->subject('Bem-vindo ao MecânicaPro!')
                );
            } catch (\Throwable) {
                // Mail failure should not abort provisioning
            }

            return $oficina->load('plano');
        });
    }
}
