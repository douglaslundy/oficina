<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Oficina extends Model
{
    protected $table = 'oficinas';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'nome',
        'cnpj',
        'slug',
        'plano_id',
        'status',
        'gateway',
        'asaas_customer_id',
        'asaas_subscription_id',
        'mp_customer_id',
        'mp_subscription_id',
        'admin_email',
        'admin_cpf',
        'provedor_fiscal',
        'emissao_fiscal_modo',
        'ciclo_cobranca',
        'proximo_vencimento',
        'dias_antecedencia_cobranca',
        'dias_suspensao_vencido',
        'alerta_cobranca_exibicoes_hoje',
        'alerta_cobranca_ultima_exibicao_em',
        'voto_confianca_ate',
    ];

    protected $casts = [
        'criado_em'                          => 'datetime',
        'atualizado_em'                      => 'datetime',
        'proximo_vencimento'                 => 'date',
        'dias_antecedencia_cobranca'         => 'integer',
        'dias_suspensao_vencido'             => 'integer',
        'alerta_cobranca_exibicoes_hoje'     => 'integer',
        'alerta_cobranca_ultima_exibicao_em' => 'date',
        'voto_confianca_ate'                 => 'date',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    public function plano(): BelongsTo
    {
        return $this->belongsTo(Plano::class);
    }

    public function cobrancas(): HasMany
    {
        return $this->hasMany(Cobranca::class);
    }

    public function calcularProximoVencimento(): \Illuminate\Support\Carbon
    {
        $meses = $this->ciclo_cobranca === 'ANUAL' ? 12 : 1;
        return $this->proximo_vencimento->copy()->addMonths($meses);
    }

    public function avancarVencimento(): void
    {
        $this->update(['proximo_vencimento' => $this->calcularProximoVencimento()]);
    }
}
