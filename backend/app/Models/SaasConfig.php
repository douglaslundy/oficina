<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaasConfig extends Model
{
    protected $table = 'saas_config';
    public $timestamps = false;
    const UPDATED_AT = 'atualizado_em';

    protected $fillable = [
        'gateway_preferido',
        'asaas_api_key',
        'asaas_webhook_token',
        'mp_access_token',
        'mp_public_key',
        'mp_webhook_secret',
        'mp_ambiente',
    ];

    protected $hidden = [
        'asaas_api_key',
        'asaas_webhook_token',
        'mp_access_token',
        'mp_public_key',
        'mp_webhook_secret',
    ];

    /** Retorna sempre a linha singleton. */
    public static function get(): self
    {
        return static::firstOrCreate([], ['gateway_preferido' => 'ASAAS']);
    }

    /** Máscara: mostra apenas os últimos 6 caracteres. */
    public static function mascarar(?string $valor): ?string
    {
        if (empty($valor)) return null;
        $len = strlen($valor);
        return str_repeat('*', max(0, $len - 6)) . substr($valor, -6);
    }
}
