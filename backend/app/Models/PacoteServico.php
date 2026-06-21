<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PacoteServico extends Model
{
    protected $table      = 'pacotes_servico';
    protected $primaryKey = 'id';
    public    $incrementing = false;
    protected $keyType    = 'string';
    public    $timestamps = false;

    protected $fillable = [
        'nome', 'servico', 'quantidade', 'valor', 'recorrente', 'periodo_dias', 'ativo',
    ];

    protected $casts = [
        'quantidade'   => 'integer',
        'valor'        => 'decimal:2',
        'recorrente'   => 'boolean',
        'periodo_dias' => 'integer',
        'ativo'        => 'boolean',
        'criado_em'    => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn ($m) => $m->id ??= (string) Str::uuid());
    }
}
