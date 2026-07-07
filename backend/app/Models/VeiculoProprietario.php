<?php
declare(strict_types=1);

namespace App\Models;

use App\Tenancy\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class VeiculoProprietario extends Model
{
    use HasTenantScope;

    protected $table = 'veiculo_proprietarios';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'veiculo_id', 'cliente_id', 'oficina_id', 'data_inicio', 'data_fim',
    ];

    protected $casts = [
        'data_inicio' => 'datetime',
        'data_fim'    => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn($m) => $m->id = $m->id ?: (string) Str::uuid());
    }

    public function veiculo(): BelongsTo { return $this->belongsTo(Veiculo::class, 'veiculo_id'); }
    public function cliente(): BelongsTo { return $this->belongsTo(Cliente::class, 'cliente_id'); }
}
