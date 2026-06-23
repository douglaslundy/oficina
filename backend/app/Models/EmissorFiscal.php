<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class EmissorFiscal extends Model
{
    protected $table = 'emissores_fiscais';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'id', 'oficina_id', 'provedor', 'ambiente',
        'emissor_externo_id', 'token_encrypted', 'status',
        'registrado_em', 'ultimo_erro',
    ];

    protected $casts = [
        'registrado_em' => 'datetime',
        'criado_em'     => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn ($m) => $m->id = $m->id ?: (string) Str::uuid());
    }
}
