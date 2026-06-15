<?php
declare(strict_types=1);

namespace App\Models;

use App\Tenancy\HasTenantScope;
use App\Tenancy\TenancyContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Veiculo extends Model
{
    use HasTenantScope, LogsActivity;

    protected $table = 'veiculos';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'cliente_id', 'oficina_id', 'modelo', 'ano', 'placa', 'chassi', 'ativo',
    ];

    protected $casts = [
        'criado_em' => 'datetime',
        'ativo'     => 'boolean',
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

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'cliente_id');
    }
}
