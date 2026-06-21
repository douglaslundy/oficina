<?php
declare(strict_types=1);

namespace App\Models;

use App\Tenancy\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Orcamento extends Model
{
    use HasTenantScope;

    protected $table      = 'orcamentos';
    protected $primaryKey = 'id';
    public    $incrementing = false;
    protected $keyType    = 'string';
    public    $timestamps = false;

    protected $fillable = [
        'oficina_id', 'os_id', 'token', 'status', 'canal_envio',
        'enviado_em', 'respondido_em',
    ];

    protected $casts = [
        'enviado_em'    => 'datetime',
        'respondido_em' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn ($m) => $m->id ??= (string) Str::uuid());
    }

    public function ordemServico(): BelongsTo
    {
        return $this->belongsTo(OrdemServico::class, 'os_id');
    }

    public static function gerarToken(): string
    {
        return Str::random(48);
    }
}
