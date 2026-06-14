<?php
declare(strict_types=1);

namespace App\Models;

use App\Tenancy\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Agendamento extends Model
{
    use HasTenantScope;

    protected $table = 'agendamentos';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'cliente_id', 'mecanico_id', 'tipo_servico', 'observacoes',
        'data_hora_inicio', 'data_hora_fim', 'status', 'os_id', 'oficina_id',
    ];

    protected $casts = [
        'data_hora_inicio' => 'datetime',
        'data_hora_fim'    => 'datetime',
        'criado_em'        => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn($m) => $m->id = $m->id ?: (string) Str::uuid());
    }

    public function cliente(): BelongsTo { return $this->belongsTo(Cliente::class, 'cliente_id'); }
    public function mecanico(): BelongsTo { return $this->belongsTo(Usuario::class, 'mecanico_id'); }
    public function ordemServico(): BelongsTo { return $this->belongsTo(OrdemServico::class, 'os_id'); }
}
