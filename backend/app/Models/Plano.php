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
        'ativo',
    ];

    protected $casts = [
        'preco_mensal'    => 'decimal:2',
        'limite_usuarios' => 'integer',
        'limite_os_mes'   => 'integer',
        'ativo'           => 'boolean',
        'criado_em'       => 'datetime',
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
