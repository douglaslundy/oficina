<?php
declare(strict_types=1);

namespace App\Models;

use App\Tenancy\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Servico extends Model
{
    use HasTenantScope;

    protected $table = 'servicos';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = ['nome', 'valor_padrao', 'ativo', 'oficina_id'];

    protected $casts = [
        'ativo'        => 'boolean',
        'valor_padrao' => 'float',
        'criado_em'    => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn($m) => $m->id = $m->id ?: (string) Str::uuid());
    }
}
