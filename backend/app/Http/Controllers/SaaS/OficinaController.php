<?php
declare(strict_types=1);

namespace App\Http\Controllers\SaaS;

use App\Http\Controllers\Controller;
use App\Models\Cobranca;
use App\Models\Oficina;
use App\Models\OrdemServico;
use App\Models\Usuario;
use App\Services\AsaasService;
use App\Services\AssinaturaService;
use App\Services\EntitlementService;
use App\Services\CobrancaRecorrenteService;
use App\Services\MercadoPagoService;
use App\Services\TenantProvisionService;
use App\Models\SaasConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

class OficinaController extends Controller
{
    public function __construct(
        private TenantProvisionService $provisionService,
        private AsaasService $asaas,
        private MercadoPagoService $mercadoPago,
        private EntitlementService $ent,
        private AssinaturaService $assinatura,
        private CobrancaRecorrenteService $cobrancaRecorrente,
    ) {}

    /** Composição da mensalidade efetiva (plano + serviços avulsos ativos). */
    public function mensalidade(string $id): JsonResponse
    {
        $oficina = Oficina::with('plano')->findOrFail($id);
        $planoValor = (float) ($oficina->plano?->preco_mensal ?? 0);
        $adicional  = $this->ent->valorAdicionalMensal($id);
        $gateway    = $oficina->gateway ?: (SaasConfig::get()->gateway_preferido ?? 'ASAAS');
        $temAssinatura = $gateway === 'MERCADOPAGO'
            ? !empty($oficina->mp_subscription_id)
            : !empty($oficina->asaas_subscription_id);

        return response()->json([
            'plano_valor'    => round($planoValor, 2),
            'adicional'      => round($adicional, 2),
            'total'          => round($planoValor + $adicional, 2),
            'gateway'        => $gateway,
            'tem_assinatura' => $temAssinatura,
        ]);
    }

    /** Atualiza o valor da assinatura no gateway para a mensalidade efetiva. */
    public function sincronizarAssinatura(string $id): JsonResponse
    {
        $oficina = Oficina::with('plano')->findOrFail($id);
        $total   = round((float) ($oficina->plano?->preco_mensal ?? 0) + $this->ent->valorAdicionalMensal($id), 2);
        $gateway = $oficina->gateway ?: (SaasConfig::get()->gateway_preferido ?? 'ASAAS');

        try {
            if ($gateway === 'MERCADOPAGO') {
                if (empty($oficina->mp_subscription_id)) {
                    return response()->json(['message' => 'Oficina sem assinatura no Mercado Pago.'], 422);
                }
                $this->mercadoPago->atualizarSubscription($oficina->mp_subscription_id, $total);
            } else {
                if (empty($oficina->asaas_subscription_id)) {
                    return response()->json(['message' => 'Oficina sem assinatura no Asaas.'], 422);
                }
                $this->asaas->atualizarSubscription($oficina->asaas_subscription_id, $total);
            }
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Falha ao atualizar a assinatura: ' . $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Assinatura atualizada para R$ ' . number_format($total, 2, ',', '.') . '.']);
    }

    public function mudarCiclo(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate(['ciclo' => 'required|in:MENSAL,ANUAL']);
        $oficina   = Oficina::findOrFail($id);

        $this->assinatura->mudarCiclo($oficina, $validated['ciclo']);

        return response()->json(['message' => 'Ciclo de cobrança atualizado.', 'data' => [
            'ciclo_cobranca'     => $oficina->ciclo_cobranca,
            'proximo_vencimento' => $oficina->proximo_vencimento->toDateString(),
        ]]);
    }

    public function index(Request $request): JsonResponse
    {
        $oficinas = Oficina::with('plano')
            ->orderBy('criado_em', 'desc')
            ->paginate(15);

        $inicioMes = Carbon::now()->startOfMonth();

        $items = $oficinas->getCollection()->map(function (Oficina $oficina) use ($inicioMes) {
            return $this->formatOficina($oficina, $inicioMes);
        });

        return response()->json([
            'data' => $items,
            'meta' => [
                'total'        => $oficinas->total(),
                'per_page'     => $oficinas->perPage(),
                'current_page' => $oficinas->currentPage(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nome'        => 'required|string|max:150',
            'cnpj'        => ['required', 'string', new \App\Rules\Cnpj],
            'slug'        => 'required|string|max:60|unique:oficinas,slug|regex:/^[a-z0-9-]+$/',
            'plano_id'    => 'required|uuid|exists:planos,id',
            'admin_nome'  => 'required|string|max:120',
            'admin_email' => 'required|email|max:120',
            'admin_cpf'   => ['required', 'string', new \App\Rules\Cpf],
            'admin_senha' => 'nullable|string|min:8',
        ]);

        $oficina = $this->provisionService->provisionar($validated);
        $oficina->refresh();

        $inicioMes = Carbon::now()->startOfMonth();

        $customerId = $oficina->gateway === 'MERCADOPAGO' ? $oficina->mp_customer_id : $oficina->asaas_customer_id;
        $message = 'Oficina provisionada com sucesso.';
        if ((float) ($oficina->plano?->preco_mensal ?? 0) > 0 && !$customerId) {
            $message = "Oficina criada, mas houve falha ao configurar o cliente no gateway de pagamento ({$oficina->gateway}). Use o botão \"Criar cliente no gateway\" na tela da oficina para tentar novamente.";
        }

        return response()->json([
            'message' => $message,
            'data'    => $this->formatOficina($oficina, $inicioMes),
        ], 201);
    }

    public function show(string $id): JsonResponse
    {
        $oficina = Oficina::with('plano')->findOrFail($id);

        $inicioMes = Carbon::now()->startOfMonth();

        return response()->json([
            'data' => $this->formatOficina($oficina, $inicioMes),
        ]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $oficina = Oficina::findOrFail($id);

        $validated = $request->validate([
            'nome'         => 'sometimes|string|max:150',
            'plano_id'     => 'sometimes|uuid|exists:planos,id',
            'status'       => 'sometimes|string|in:ATIVA,SUSPENSA,CANCELADA',
            'admin_nome'   => 'sometimes|string|max:120',
            'admin_email'  => 'sometimes|email|max:120',
            'admin_senha'  => 'sometimes|nullable|string|min:8',
            'proximo_vencimento'         => 'sometimes|date',
            'dias_antecedencia_cobranca' => 'sometimes|nullable|integer|min:0',
            'dias_suspensao_vencido'     => 'sometimes|nullable|integer|min:0',
            'gateway'                    => 'sometimes|in:ASAAS,MERCADOPAGO',
        ]);

        $oficinaFields = array_intersect_key($validated, array_flip([
            'nome', 'plano_id', 'status', 'admin_email',
            'proximo_vencimento', 'dias_antecedencia_cobranca', 'dias_suspensao_vencido',
            'gateway',
        ]));
        if (!empty($oficinaFields)) {
            $oficina->update($oficinaFields);
        }
        $oficina->load('plano');

        $adminUser = Usuario::withoutGlobalScopes()
            ->where('oficina_id', $id)
            ->where('role', 'ADMIN')
            ->first();

        $adminUserCriado = false;

        if ($adminUser) {
            if (!empty($validated['admin_nome']))  $adminUser->nome       = strtoupper($validated['admin_nome']);
            if (!empty($validated['admin_email'])) $adminUser->email      = $validated['admin_email'];
            if (!empty($validated['admin_senha'])) $adminUser->senha_hash = Hash::make($validated['admin_senha']);
            $adminUser->save();
        } elseif (!empty($validated['admin_senha'])) {
            // Usuário admin não encontrado — auto-healing: cria com CPF armazenado na oficina
            $email = $validated['admin_email'] ?? $oficina->admin_email;
            $nome  = $validated['admin_nome']  ?? ($oficina->nome . ' ADMIN');
            $cpf   = $oficina->admin_cpf;

            if ($email && $cpf) {
                \App\Tenancy\TenancyContext::set($oficina->id);

                Usuario::create([
                    'nome'       => strtoupper($nome),
                    'email'      => $email,
                    'cpf'        => $cpf,
                    'role'       => 'ADMIN',
                    'status'     => 'ATIVO',
                    'senha_hash' => Hash::make($validated['admin_senha']),
                    // oficina_id auto-set by HasTenantScope
                ]);

                \App\Tenancy\TenancyContext::clear();

                $adminUserCriado = true;
            }
        }

        $inicioMes = Carbon::now()->startOfMonth();

        $message = 'Oficina atualizada com sucesso.';
        if ($adminUserCriado) {
            $message = 'Oficina atualizada. Usuário administrador criado com a nova senha.';
        }

        return response()->json([
            'message' => $message,
            'data'    => $this->formatOficina($oficina, $inicioMes),
        ]);
    }

    public function suspender(string $id): JsonResponse
    {
        $oficina = Oficina::findOrFail($id);
        $oficina->update(['status' => 'SUSPENSA']);

        return response()->json([
            'message' => 'Oficina suspensa com sucesso.',
            'data'    => ['id' => $oficina->id, 'status' => 'SUSPENSA'],
        ]);
    }

    public function reativar(string $id): JsonResponse
    {
        $oficina = Oficina::findOrFail($id);
        $oficina->update(['status' => 'ATIVA', 'voto_confianca_ate' => null]);

        return response()->json([
            'message' => 'Oficina reativada com sucesso.',
            'data'    => ['id' => $oficina->id, 'status' => 'ATIVA'],
        ]);
    }

    public function votoConfianca(string $id): JsonResponse
    {
        $oficina = Oficina::findOrFail($id);
        $dias    = SaasConfig::get()->voto_confianca_dias;

        $oficina->update([
            'status'             => 'ATIVA',
            'voto_confianca_ate' => now()->addDays($dias)->toDateString(),
        ]);

        $cobranca = Cobranca::where('oficina_id', $oficina->id)
            ->where('tipo', 'ASSINATURA')
            ->where('status', 'VENCIDA')
            ->orderByDesc('vencimento')
            ->first();

        $cobranca?->update(['voto_confianca_usado_em' => now()]);

        return response()->json([
            'message' => "Voto de confiança concedido. Acesso liberado por {$dias} dias.",
            'data'    => ['id' => $oficina->id, 'status' => 'ATIVA', 'voto_confianca_ate' => $oficina->voto_confianca_ate->toDateString()],
        ]);
    }

    public function asaasStatus(string $id): JsonResponse
    {
        $oficina = Oficina::findOrFail($id);
        $gateway = $oficina->gateway ?: (SaasConfig::get()->gateway_preferido ?? 'ASAAS');

        $subscription = null;
        $customer     = null;
        $pagamentos   = [];
        $customerId     = $gateway === 'MERCADOPAGO' ? $oficina->mp_customer_id : $oficina->asaas_customer_id;
        $subscriptionId = $gateway === 'MERCADOPAGO' ? $oficina->mp_subscription_id : $oficina->asaas_subscription_id;

        try {
            if ($gateway === 'MERCADOPAGO') {
                if ($customerId) {
                    $customer = $this->mercadoPago->buscarCustomer($customerId);
                }
                if ($subscriptionId) {
                    $subscription = $this->mercadoPago->buscarSubscription($subscriptionId);
                }
                // Não há endpoint de "pagamentos por subscription" no MP equivalente ao
                // Asaas — o histórico real fica na seção "Cobranças Locais" da tela, que
                // é alimentada pela nossa própria tabela `cobrancas` independente do gateway.
            } else {
                if ($customerId) {
                    $customer = $this->asaas->buscarCustomer($customerId);
                }
                if ($subscriptionId) {
                    $subscription = $this->asaas->buscarSubscription($subscriptionId);
                    $pagamentos   = $this->asaas->buscarPagamentos($subscriptionId, 5);
                }
            }
        } catch (\Throwable $e) {
            return response()->json([
                'error'   => true,
                'message' => "Falha ao consultar {$gateway}: " . $e->getMessage(),
            ], 502);
        }

        return response()->json([
            'gateway'                => $gateway,
            'customer_id'            => $customerId,
            'subscription_id'        => $subscriptionId,
            'asaas_customer_id'      => $oficina->asaas_customer_id,
            'asaas_subscription_id'  => $oficina->asaas_subscription_id,
            'customer'               => $customer,
            'subscription'           => $subscription,
            'ultimos_pagamentos'     => $pagamentos,
        ]);
    }

    /**
     * Recovery action: (re)cria o customer da oficina no gateway configurado.
     * Usado quando o provisionamento inicial falhou silenciosamente e a
     * oficina ficou sem customer_id em nenhum gateway.
     */
    public function criarCustomerGateway(string $id): JsonResponse
    {
        $oficina = Oficina::findOrFail($id);
        $gateway = $oficina->gateway ?: (SaasConfig::get()->gateway_preferido ?? 'ASAAS');
        $nomeGateway = $gateway === 'MERCADOPAGO' ? 'Mercado Pago' : 'Asaas';

        $adminUser = Usuario::withoutGlobalScopes()
            ->where('oficina_id', $id)
            ->where('role', 'ADMIN')
            ->first();
        $nome = $adminUser?->nome ?? $oficina->nome;

        if (!$oficina->admin_email || !$oficina->cnpj) {
            return response()->json(['message' => 'Oficina sem e-mail do admin ou CNPJ cadastrado — não é possível criar o customer.'], 422);
        }

        try {
            if ($gateway === 'MERCADOPAGO') {
                $customer = $this->mercadoPago->criarCustomer($nome, $oficina->admin_email, $oficina->cnpj);
                $oficina->update(['gateway' => 'MERCADOPAGO', 'mp_customer_id' => $customer['id']]);
            } else {
                $customer = $this->asaas->criarCustomer($nome, $oficina->cnpj, $oficina->admin_email);
                $oficina->update(['gateway' => 'ASAAS', 'asaas_customer_id' => $customer['id']]);
            }
        } catch (\Throwable $e) {
            return response()->json(['message' => "Falha ao criar cliente no {$nomeGateway}: " . $e->getMessage()], 502);
        }

        return response()->json([
            'message' => "Cliente criado com sucesso no {$nomeGateway}.",
            'data'    => [
                'gateway'                => $oficina->gateway,
                'asaas_customer_id'      => $oficina->asaas_customer_id,
                'mp_customer_id'         => $oficina->mp_customer_id,
            ],
        ]);
    }

    /**
     * Gera manualmente a cobrança de ASSINATURA do ciclo atual (mensal ou
     * anual), sem esperar a janela de antecedência do job automático. Não se
     * aplica a cobranças AVULSA (endpoint separado, sem essa restrição).
     */
    public function gerarCobrancaCiclo(string $id): JsonResponse
    {
        $oficina = Oficina::with('plano')->findOrFail($id);

        $resultado = $this->cobrancaRecorrente->gerarManual($oficina);

        if (!$resultado['ok']) {
            return response()->json(['message' => $resultado['message']], 422);
        }

        $oficina->refresh();

        return response()->json([
            'message' => $resultado['message'],
            'data'    => [
                'ciclo_cobranca'     => $oficina->ciclo_cobranca,
                'proximo_vencimento' => $oficina->proximo_vencimento?->toDateString(),
            ],
        ]);
    }

    public function sincronizarCobrancas(string $id): JsonResponse
    {
        $oficina = Oficina::findOrFail($id);

        if (!$oficina->asaas_subscription_id) {
            return response()->json(['message' => 'Oficina não possui assinatura no Asaas.'], 422);
        }

        try {
            $pagamentos = $this->asaas->buscarPagamentos($oficina->asaas_subscription_id, 24);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Falha ao consultar Asaas: ' . $e->getMessage()], 502);
        }

        $sincronizados = 0;

        foreach ($pagamentos as $p) {
            $asaasStatus = match ($p['status'] ?? '') {
                'RECEIVED', 'CONFIRMED' => 'PAGA',
                'OVERDUE'               => 'VENCIDO',
                default                 => 'PENDENTE',
            };

            $existing = Cobranca::where('asaas_payment_id', $p['id'])->first();

            if ($existing) {
                $existing->update(['status' => $asaasStatus, 'pago_em' => $asaasStatus === 'PAGA' ? ($p['paymentDate'] ?? now()) : null]);
            } else {
                Cobranca::create([
                    'oficina_id'       => $oficina->id,
                    'mes_referencia'   => Carbon::parse($p['dueDate'] ?? now())->startOfMonth(),
                    'valor'            => $p['value'] ?? 0,
                    'status'           => $asaasStatus,
                    'asaas_payment_id' => $p['id'],
                    'vencimento'       => $p['dueDate'] ?? null,
                    'pago_em'          => $asaasStatus === 'PAGA' ? ($p['paymentDate'] ?? null) : null,
                ]);
                $sincronizados++;
            }
        }

        return response()->json([
            'message'       => "Sincronização concluída. {$sincronizados} nova(s) cobrança(s) importada(s).",
            'sincronizados' => $sincronizados,
            'total'         => count($pagamentos),
        ]);
    }

    public function gerarCobrancaAvulsa(Request $request, string $id): JsonResponse
    {
        $oficina = Oficina::findOrFail($id);
        $gateway = $oficina->gateway ?: (SaasConfig::get()->gateway_preferido ?? 'ASAAS');
        $nomeGateway = $gateway === 'MERCADOPAGO' ? 'Mercado Pago' : 'Asaas';
        $customerId = $gateway === 'MERCADOPAGO' ? $oficina->mp_customer_id : $oficina->asaas_customer_id;

        if (!$customerId) {
            return response()->json(['message' => "Oficina não possui customer no {$nomeGateway}."], 422);
        }

        $validated = $request->validate([
            'valor'      => 'required|numeric|min:1',
            'vencimento' => 'required|date|after:yesterday',
        ]);

        $cobrancaId = (string) \Illuminate\Support\Str::uuid();

        try {
            $payment = $gateway === 'MERCADOPAGO'
                ? $this->mercadoPago->criarCobrancaAvulsa($customerId, (float) $validated['valor'], $validated['vencimento'], $cobrancaId)
                : $this->asaas->criarCobrancaAvulsa($customerId, (float) $validated['valor'], $validated['vencimento'], $cobrancaId);
        } catch (\Throwable $e) {
            return response()->json(['message' => "Falha ao criar cobrança no {$nomeGateway}: " . $e->getMessage()], 502);
        }

        $linkPagamento = $gateway === 'MERCADOPAGO'
            ? ($payment['init_point'] ?? null)
            : ($payment['invoiceUrl'] ?? null);

        $cobranca = Cobranca::create([
            'id'               => $cobrancaId,
            'oficina_id'       => $oficina->id,
            'mes_referencia'   => Carbon::parse($validated['vencimento'])->startOfMonth(),
            'valor'            => $validated['valor'],
            'tipo'             => 'AVULSA',
            'status'           => 'PENDENTE',
            'gateway'          => $gateway,
            'asaas_payment_id' => $gateway === 'ASAAS' ? ($payment['id'] ?? null) : null,
            'mp_payment_id'    => $gateway === 'MERCADOPAGO' ? ($payment['id'] ?? null) : null,
            'vencimento'       => $validated['vencimento'],
            'link_pagamento'   => $linkPagamento,
        ]);

        return response()->json([
            'message'  => 'Cobrança avulsa criada com sucesso.' . ($linkPagamento ? " Link de pagamento: {$linkPagamento}" : ''),
            'cobranca' => [
                'id'               => $cobranca->id,
                'gateway'          => $cobranca->gateway,
                'asaas_payment_id' => $cobranca->asaas_payment_id,
                'mp_payment_id'    => $cobranca->mp_payment_id,
                'link_pagamento'   => $linkPagamento,
                'valor'            => number_format($cobranca->valor, 2, '.', ''),
                'vencimento'       => $cobranca->vencimento?->toDateString(),
                'status'           => $cobranca->status,
            ],
        ], 201);
    }

    public function cancelarAssinatura(string $id): JsonResponse
    {
        $oficina = Oficina::findOrFail($id);
        $gateway = $oficina->gateway ?: (SaasConfig::get()->gateway_preferido ?? 'ASAAS');
        $nomeGateway = $gateway === 'MERCADOPAGO' ? 'Mercado Pago' : 'Asaas';
        $subscriptionId = $gateway === 'MERCADOPAGO' ? $oficina->mp_subscription_id : $oficina->asaas_subscription_id;

        if (!$subscriptionId) {
            return response()->json(['message' => "Oficina não possui assinatura no {$nomeGateway}."], 422);
        }

        try {
            if ($gateway === 'MERCADOPAGO') {
                $this->mercadoPago->cancelarSubscription($subscriptionId);
            } else {
                $this->asaas->cancelarSubscription($subscriptionId);
            }
        } catch (\Throwable $e) {
            return response()->json(['message' => "Falha ao cancelar no {$nomeGateway}: " . $e->getMessage()], 502);
        }

        $oficina->update(array_merge(
            ['status' => 'CANCELADA'],
            $gateway === 'MERCADOPAGO' ? ['mp_subscription_id' => null] : ['asaas_subscription_id' => null],
        ));

        return response()->json(['message' => 'Assinatura cancelada e oficina desativada.']);
    }

    public function updateFiscal(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'provedor_fiscal'     => ['nullable', 'in:SPEDY,FOCUS'],
            'emissao_fiscal_modo' => ['nullable', 'in:MANUAL,AUTOMATICO'],
        ]);
        $oficina = \App\Models\Oficina::findOrFail($id);
        $oficina->update($validated);
        return response()->json(['message' => 'Configuração fiscal da oficina atualizada.', 'data' => [
            'provedor_fiscal'     => $oficina->provedor_fiscal,
            'emissao_fiscal_modo' => $oficina->emissao_fiscal_modo,
        ]]);
    }

    private function formatOficina(Oficina $oficina, Carbon $inicioMes): array
    {
        $usersCount = Usuario::withoutGlobalScopes()
            ->where('oficina_id', $oficina->id)
            ->count();

        $osMesCount = OrdemServico::withoutGlobalScopes()
            ->where('oficina_id', $oficina->id)
            ->where('criado_em', '>=', $inicioMes)
            ->count();

        $adminUser = Usuario::withoutGlobalScopes()
            ->where('oficina_id', $oficina->id)
            ->where('role', 'ADMIN')
            ->select('nome')
            ->first();

        return [
            'id'          => $oficina->id,
            'nome'        => $oficina->nome,
            'cnpj'        => $this->formatCnpj($oficina->cnpj),
            'slug'        => $oficina->slug,
            'status'      => $oficina->status,
            'plano'       => $oficina->plano ? [
                'id'           => $oficina->plano->id,
                'nome'         => $oficina->plano->nome,
                'preco_mensal' => number_format((float) $oficina->plano->preco_mensal, 2, '.', ''),
            ] : null,
            'users_count'         => $usersCount,
            'os_mes_count'        => $osMesCount,
            'admin_nome'          => $adminUser?->nome,
            'admin_email'         => $oficina->admin_email,
            'admin_cpf'           => $oficina->admin_cpf ? $this->formatCpf($oficina->admin_cpf) : null,
            'criado_em'           => $oficina->criado_em?->toIso8601String(),
            'provedor_fiscal'     => $oficina->provedor_fiscal,
            'emissao_fiscal_modo' => $oficina->emissao_fiscal_modo,
            'ciclo_cobranca'             => $oficina->ciclo_cobranca,
            'proximo_vencimento'         => $oficina->proximo_vencimento?->toDateString(),
            'dias_antecedencia_cobranca' => $oficina->dias_antecedencia_cobranca,
            'dias_suspensao_vencido'     => $oficina->dias_suspensao_vencido,
            'gateway'                => $oficina->gateway ?: (SaasConfig::get()->gateway_preferido ?? 'ASAAS'),
            'asaas_customer_id'      => $oficina->asaas_customer_id,
            'asaas_subscription_id'  => $oficina->asaas_subscription_id,
            'mp_customer_id'         => $oficina->mp_customer_id,
            'mp_subscription_id'     => $oficina->mp_subscription_id,
        ];
    }

    public function destroy(string $id): JsonResponse
    {
        $oficina = Oficina::findOrFail($id);

        $temDados = \App\Models\Cliente::withoutGlobalScopes()->where('oficina_id', $oficina->id)->exists()
            || OrdemServico::withoutGlobalScopes()->where('oficina_id', $oficina->id)->exists()
            || \App\Models\Produto::withoutGlobalScopes()->where('oficina_id', $oficina->id)->exists()
            || \App\Models\NotaFiscal::withoutGlobalScopes()->where('oficina_id', $oficina->id)->exists();

        if ($temDados) {
            return response()->json([
                'message' => 'Não é possível excluir esta oficina pois ela possui dados cadastrados (clientes, OS, produtos ou notas fiscais).',
            ], 422);
        }

        // Remove usuários e depois a oficina
        Usuario::withoutGlobalScopes()->where('oficina_id', $oficina->id)->delete();
        $oficina->delete();

        return response()->json(['message' => 'Oficina excluída com sucesso.']);
    }

    private function formatCnpj(string $cnpj): string
    {
        $cnpj = preg_replace('/\D/', '', $cnpj);
        if (strlen($cnpj) === 14) {
            return preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $cnpj);
        }
        return $cnpj;
    }

    private function formatCpf(string $cpf): string
    {
        $cpf = preg_replace('/\D/', '', $cpf);
        if (strlen($cpf) === 11) {
            return preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $cpf);
        }
        return $cpf;
    }
}
