<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Cobranca extends Model
{
    protected $table = 'cobrancas';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'id',
        'oficina_id',
        'mes_referencia',
        'valor',
        'status',
        'tipo',
        'descricao',
        'gateway',
        'asaas_payment_id',
        'mp_payment_id',
        'vencimento',
        'link_pagamento',
        'voto_confianca_usado_em',
        'pago_em',
    ];

    protected $casts = [
        'valor'                    => 'decimal:2',
        'mes_referencia'           => 'date',
        'vencimento'               => 'date',
        'pago_em'                  => 'datetime',
        'voto_confianca_usado_em'  => 'datetime',
        'criado_em'                => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
            // mes_referencia é NOT NULL e o código de produção sempre o define
            // (a partir do vencimento). Default defensivo: 1º dia do mês do
            // vencimento, ou do mês corrente.
            if (empty($model->mes_referencia)) {
                $base = $model->vencimento ? \Illuminate\Support\Carbon::parse($model->vencimento) : now();
                $model->mes_referencia = $base->copy()->startOfMonth();
            }
        });
    }

    public function oficina(): BelongsTo
    {
        return $this->belongsTo(Oficina::class);
    }
}
