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
        'oficina_id',
        'mes_referencia',
        'valor',
        'status',
        'asaas_payment_id',
        'vencimento',
        'pago_em',
    ];

    protected $casts = [
        'valor'          => 'decimal:2',
        'mes_referencia' => 'date',
        'vencimento'     => 'date',
        'pago_em'        => 'datetime',
        'criado_em'      => 'datetime',
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

    public function oficina(): BelongsTo
    {
        return $this->belongsTo(Oficina::class);
    }
}
