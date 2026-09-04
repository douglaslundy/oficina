<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class NotificacaoVisualizacao extends Model
{
    protected $table = 'notificacao_visualizacoes';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'tipo', 'notificacao_id', 'cobranca_id', 'titulo', 'mensagem',
        'oficina_id', 'usuario_id', 'ip', 'user_agent',
    ];

    protected $casts = [
        'visualizado_em' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function ($m) {
            $m->id ??= (string) Str::uuid();
            // useCurrent() na migration cobre o default no banco, mas o
            // Postgres não devolve o valor gerado pro objeto Eloquent em
            // memória (só o id via RETURNING) — sem isso, ler o atributo
            // logo após create() dá null até um refresh().
            $m->visualizado_em ??= now();
        });
    }

    public function oficina(): BelongsTo
    {
        return $this->belongsTo(Oficina::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }

    public function cobranca(): BelongsTo
    {
        return $this->belongsTo(Cobranca::class);
    }
}
