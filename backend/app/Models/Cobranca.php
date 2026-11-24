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
        'nota_fiscal_id',
        'fatura_id',
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

        // Itens excedentes agrupados numa fatura seguem o destino dela:
        // fatura paga quita os itens; fatura cancelada/estornada/apagada
        // devolve os itens para "a faturar" (entram na próxima fatura).
        static::updated(function (Cobranca $fatura) {
            if ($fatura->tipo !== 'ASSINATURA' || !$fatura->wasChanged('status')) {
                return;
            }
            if ($fatura->status === 'PAGA') {
                static::where('fatura_id', $fatura->id)->update(['status' => 'PAGA', 'pago_em' => $fatura->pago_em ?? now()]);
            } elseif (in_array($fatura->status, ['CANCELADA', 'ESTORNADA'], true)) {
                static::liberarItens($fatura->id);
            }
        });
        static::deleting(fn (Cobranca $fatura) => static::liberarItens($fatura->id));
    }

    public const TIPOS_EXCEDENTE = ['NOTA_EXCEDENTE'];

    /** Excedentes ainda não incluídos em nenhuma fatura. */
    public static function excedentesAFaturar(string $oficinaId)
    {
        return static::where('oficina_id', $oficinaId)
            ->whereIn('tipo', self::TIPOS_EXCEDENTE)
            ->whereIn('status', ['PENDENTE', 'VENCIDA'])
            ->whereNull('fatura_id');
    }

    private static function liberarItens(string $faturaId): void
    {
        static::where('fatura_id', $faturaId)->where('status', 'FATURADA')
            ->update(['status' => 'PENDENTE', 'fatura_id' => null]);
    }

    public function itens(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Cobranca::class, 'fatura_id');
    }

    public function oficina(): BelongsTo
    {
        return $this->belongsTo(Oficina::class);
    }
}
