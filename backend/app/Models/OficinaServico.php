<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class OficinaServico extends Model
{
    protected $table      = 'oficina_servicos';
    protected $primaryKey = 'id';
    public    $incrementing = false;
    protected $keyType    = 'string';
    public    $timestamps = false;

    protected $fillable = [
        'oficina_id', 'servico', 'pacote_id', 'quantidade',
        'valor_adicional', 'recorrente', 'data_inicio', 'data_fim', 'ativo',
    ];

    protected $casts = [
        'quantidade'      => 'integer',
        'valor_adicional' => 'decimal:2',
        'recorrente'      => 'boolean',
        'data_inicio'     => 'date',
        'data_fim'        => 'date',
        'ativo'           => 'boolean',
        'criado_em'       => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn ($m) => $m->id ??= (string) Str::uuid());
    }
}
