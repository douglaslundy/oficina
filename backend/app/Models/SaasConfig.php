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
        'smtp_host',
        'smtp_port',
        'smtp_username',
        'smtp_password',
        'smtp_encryption',
        'smtp_from_address',
        'smtp_from_name',
        'smtp_ativo',
        'evolution_url',
        'evolution_api_key',
        'provedor_fiscal_padrao',
        'emissao_fiscal_modo_padrao',
        'spedy_master_key_sandbox',
        'spedy_master_key_producao',
        'focus_master_token_homologacao',
        'focus_master_token_producao',
        'cobranca_dias_antecedencia_padrao',
        'cobranca_dias_suspensao_padrao',
        'desconto_anual_pct',
        'alerta_cobranca_vezes_dia',
        'alerta_cobranca_dias_exibicao',
        'voto_confianca_dias',
    ];

    protected $casts = [
        'smtp_port'                         => 'integer',
        'smtp_ativo'                        => 'boolean',
        'cobranca_dias_antecedencia_padrao' => 'integer',
        'cobranca_dias_suspensao_padrao'    => 'integer',
        'desconto_anual_pct'                => 'decimal:2',
        'alerta_cobranca_vezes_dia'         => 'integer',
        'alerta_cobranca_dias_exibicao'     => 'integer',
        'voto_confianca_dias'               => 'integer',
    ];

    protected $hidden = [
        'asaas_api_key',
        'asaas_webhook_token',
        'mp_access_token',
        'mp_public_key',
        'mp_webhook_secret',
        'smtp_password',
        'evolution_api_key',
        'spedy_master_key_sandbox',
        'spedy_master_key_producao',
        'focus_master_token_homologacao',
        'focus_master_token_producao',
    ];

    /** Há SMTP configurado e ativo para envio de e-mails? */
    public function smtpConfigurado(): bool
    {
        return $this->smtp_ativo
            && !empty($this->smtp_host)
            && !empty($this->getRawOriginal('smtp_from_address'));
    }

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
