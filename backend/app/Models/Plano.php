<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Plano extends Model
{
    protected $table = 'planos';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'nome',
        'preco_mensal',
        'limite_usuarios',
        'limite_os_mes',
        'limite_produtos',
        'limite_clientes',
        'limite_notas_mes',
        'preco_nota_excedente',
        'alerta_whatsapp',
        'alerta_email',
        'orcamento',
        'ativo',
    ];

    protected $casts = [
        'preco_mensal'         => 'decimal:2',
        'limite_usuarios'      => 'integer',
        'limite_os_mes'        => 'integer',
        'limite_produtos'      => 'integer',
        'limite_clientes'      => 'integer',
        'limite_notas_mes'     => 'integer',
        'preco_nota_excedente' => 'decimal:2',
        'alerta_whatsapp'      => 'boolean',
        'alerta_email'         => 'boolean',
        'orcamento'            => 'boolean',
        'ativo'                => 'boolean',
        'criado_em'            => 'datetime',
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

    public function oficinas(): HasMany
    {
        return $this->hasMany(Oficina::class);
    }
}
