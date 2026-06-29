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
    ];

    protected $casts = [
        'criado_em'     => 'datetime',
        'atualizado_em' => 'datetime',
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
}
