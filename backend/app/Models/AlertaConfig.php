<?php
declare(strict_types=1);

namespace App\Models;

use App\Tenancy\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AlertaConfig extends Model
{
    use HasTenantScope;

    protected $table      = 'alerta_configs';
    protected $primaryKey = 'id';
    public    $incrementing = false;
    protected $keyType    = 'string';
    public    $timestamps = false;

    protected $fillable = [
        'oficina_id',
        'tipo',
        'nome',
        'pre_definido',
        'ativo',
        'template_mensagem',
        'destinatarios',
        'emails',
        'canais',
        'enviar_cliente',
        'enviar_mecanico',
    ];

    protected $casts = [
        'pre_definido'    => 'boolean',
        'ativo'           => 'boolean',
        'destinatarios'   => 'array',
        'emails'          => 'array',
        'canais'          => 'array',
        'enviar_cliente'  => 'boolean',
        'enviar_mecanico' => 'boolean',
        'criado_em'       => 'datetime',
        'atualizado_em'   => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn($m) => $m->id ??= (string) Str::uuid());
    }

    // Tipos pré-definidos com nome legível e template padrão
    public static function TIPOS_PRE_DEFINIDOS(): array
    {
        return [
            'ESTOQUE_BAIXO'           => ['nome' => 'Estoque Baixo',              'template' => '⚠️ *Estoque Baixo*: O produto *{produto}* atingiu o estoque mínimo. Qtd atual: {quantidade} {unidade}.'],
            'ESTOQUE_CRITICO'         => ['nome' => 'Estoque Crítico/Zerado',     'template' => '🚨 *Estoque Crítico*: O produto *{produto}* está sem estoque! Providencie reposição urgente.'],
            'CLIENTE_DEVEDOR'         => ['nome' => 'Cliente com Dívida',         'template' => '💸 *Dívida em Aberto*: O cliente *{cliente}* possui saldo devedor de *{valor}* referente à OS #{os_numero}.'],
            'DIVIDA_VENCIDA'          => ['nome' => 'Dívida Vencida',             'template' => '🔴 *Dívida Vencida*: O cliente *{cliente}* possui uma dívida de *{valor}* vencida em {vencimento}. OS #{os_numero}.'],
            'OS_NOVA'                 => ['nome' => 'Nova OS Criada',             'template' => '🔧 *Nova OS #*{os_numero}*: Veículo {veiculo} do cliente {cliente}. Problema: {problema}.'],
            'OS_STATUS_MUDOU'         => ['nome' => 'OS Mudou de Status',         'template' => '📋 *OS #*{os_numero}* atualizada*: Status alterado para *{status}*. Cliente: {cliente} · Veículo: {veiculo}.'],
            'OS_VENCIDA'              => ['nome' => 'OS com Prazo Vencido',       'template' => '⏰ *OS Vencida*: A OS #{os_numero} do cliente {cliente} estava com prazo em {vencimento} e ainda não foi concluída.'],
            'AGENDAMENTO_CONFIRMADO'  => ['nome' => 'Agendamento Confirmado',     'template' => '✅ *Agendamento Confirmado*: {cliente} agendado para {data} às {hora}. Serviço: {servico}. OS #{os_numero} criada.'],
            'AGENDAMENTO_LEMBRETE'    => ['nome' => 'Lembrete de Agendamento',    'template' => '📅 *Lembrete*: Você tem um agendamento amanhã ({data}) às {hora} com {cliente}. Serviço: {servico}.'],
            'PAGAMENTO_RECEBIDO'      => ['nome' => 'Pagamento Recebido',         'template' => '✅ *Pagamento Recebido*: OS #{os_numero} · {cliente} · *{valor}* via {forma_pagamento}. Saldo: {saldo_devedor}.'],
            'PAGAMENTO_PARCIAL'       => ['nome' => 'Pagamento Parcial',          'template' => '⚡ *Pagamento Parcial*: OS #{os_numero} · {cliente} pagou *{valor}*. Saldo devedor: *{saldo_devedor}*.'],
            'NF_AUTORIZADA'           => ['nome' => 'Nota Fiscal Autorizada',     'template' => '🧾 *NF Autorizada*: NF #{nf_numero} emitida para {cliente}. Valor: {valor}. Chave: {chave_acesso}.'],
            'ORCAMENTO_APROVADO'      => ['nome' => 'Orçamento Aprovado',         'template' => '✅ *Orçamento Aprovado*: O cliente *{cliente}* aprovou o orçamento da OS #{os_numero}. Serviços aprovados: {servicos_aprovados}. Valor aprovado: {valor}.'],
            'ORCAMENTO_RECUSADO'      => ['nome' => 'Orçamento Recusado',         'template' => '❌ *Orçamento Recusado*: O cliente *{cliente}* recusou o orçamento da OS #{os_numero}.'],
        ];
    }
}
