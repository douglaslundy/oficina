<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Notificacao extends Model
{
    protected $table      = 'notificacoes';
    protected $primaryKey = 'id';
    public    $incrementing = false;
    protected $keyType    = 'string';
    public    $timestamps = false;

    protected $fillable = [
        'titulo', 'subtitulo', 'texto', 'imagem',
        'alvo_tipo', 'plano_id', 'oficina_ids',
        'vezes_dia', 'intervalo_minutos', 'data_inicio', 'data_fim', 'ativo',
    ];

    protected $casts = [
        'oficina_ids'       => 'array',
        'vezes_dia'         => 'integer',
        'intervalo_minutos' => 'integer',
        'data_inicio'       => 'date',
        'data_fim'          => 'date',
        'ativo'             => 'boolean',
        'criado_em'         => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn ($m) => $m->id ??= (string) Str::uuid());
    }
}
