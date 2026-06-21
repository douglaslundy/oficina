<?php
declare(strict_types=1);

namespace App\Models;

use App\Tenancy\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SolicitacaoServico extends Model
{
    use HasTenantScope;

    protected $table      = 'solicitacoes_servico';
    protected $primaryKey = 'id';
    public    $incrementing = false;
    protected $keyType    = 'string';
    public    $timestamps = false;

    protected $fillable = [
        'oficina_id', 'pacote_id', 'status', 'observacao', 'respondido_em',
    ];

    protected $casts = [
        'criado_em'     => 'datetime',
        'respondido_em' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn ($m) => $m->id ??= (string) Str::uuid());
    }

    public function pacote(): BelongsTo
    {
        return $this->belongsTo(PacoteServico::class, 'pacote_id');
    }

    public function oficina(): BelongsTo
    {
        return $this->belongsTo(Oficina::class, 'oficina_id');
    }
}
