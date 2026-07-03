<?php
declare(strict_types=1);

namespace App\Models;

use App\Tenancy\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use App\Tenancy\TenancyContext;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Produto extends Model
{
    use HasTenantScope, LogsActivity;

    protected $table = 'produtos';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'nome', 'sku', 'codigo_barras', 'categoria', 'unidade',
        'qty_atual', 'qty_minima', 'preco_custo', 'preco_venda', 'ativo', 'oficina_id',
    ];

    protected $casts = [
        'ativo'       => 'boolean',
        'criado_em'   => 'datetime',
        'preco_custo' => 'float',
        'preco_venda' => 'float',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName(TenancyContext::getSlug() ?? 'default');
    }

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn($m) => $m->id = $m->id ?: (string) Str::uuid());
    }

    public function getStatusEstoqueAttribute(): string
    {
        if ($this->qty_atual <= 0)                       return 'SEM_ESTOQUE';
        if ($this->qty_atual < $this->qty_minima * 0.4) return 'CRITICO';
        if ($this->qty_atual < $this->qty_minima)        return 'BAIXO';
        return 'NORMAL';
    }
}
