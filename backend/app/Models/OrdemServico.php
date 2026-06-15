<?php
declare(strict_types=1);

namespace App\Models;

use App\Tenancy\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class OrdemServico extends Model
{
    use HasTenantScope;

    protected $table = 'ordens_servico';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'cliente_id', 'mecanico_id', 'veiculo_descricao', 'veiculo_placa',
        'problema_relatado', 'status', 'forma_pagamento', 'prazo_entrega',
        'valor_total', 'valor_pago', 'numero', 'oficina_id',
        'venda_a_prazo', 'prazo_pagamento_dias', 'data_vencimento_pagamento',
    ];

    protected $casts = [
        'prazo_entrega'              => 'date',
        'data_vencimento_pagamento'  => 'date',
        'criado_em'                  => 'datetime',
        'atualizado_em'              => 'datetime',
        'valor_total'                => 'float',
        'valor_pago'                 => 'float',
        'venda_a_prazo'              => 'boolean',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function ($model) {
            if (empty($model->id)) $model->id = (string) Str::uuid();
            if (empty($model->numero)) {
                $max = static::max('numero') ?? 0;
                $model->numero = $max + 1;
            }
        });
    }

    public function cliente(): BelongsTo { return $this->belongsTo(Cliente::class, 'cliente_id'); }
    public function mecanico(): BelongsTo { return $this->belongsTo(Usuario::class, 'mecanico_id'); }
    public function itens(): HasMany { return $this->hasMany(OsItem::class, 'os_id'); }

    public function getSaldoDevedorAttribute(): float
    {
        return max(0.0, (float)$this->valor_total - (float)$this->valor_pago);
    }
}
