<?php
declare(strict_types=1);

namespace App\Models;

use App\Tenancy\HasTenantScope;
use Illuminate\Database\Eloquent\Model;

class AlertaLog extends Model
{
    use HasTenantScope;

    public const UPDATED_AT = null;
    public const CREATED_AT = 'enviado_em';

    protected $table    = 'alerta_logs';
    protected $keyType  = 'string';
    public    $incrementing = false;

    protected $fillable = [
        'oficina_id',
        'tipo',
        'destinatario',
        'mensagem',
        'sucesso',
        'erro',
    ];

    protected $casts = [
        'sucesso'    => 'boolean',
        'enviado_em' => 'datetime',
    ];
}
