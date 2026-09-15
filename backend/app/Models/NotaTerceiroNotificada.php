<?php
declare(strict_types=1);

namespace App\Models;

use App\Tenancy\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class NotaTerceiroNotificada extends Model
{
    use HasTenantScope;

    protected $table = 'notas_terceiro_notificadas';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'oficina_id', 'chave_acesso', 'fornecedor_nome', 'valor_total', 'data_emissao',
    ];

    protected $casts = [
        'valor_total'  => 'float',
        'data_emissao' => 'date',
        'criado_em'    => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn ($m) => $m->id = $m->id ?: (string) Str::uuid());
    }
}
