<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Models\Configuracao;
use App\Models\EmissorFiscal;
use App\Models\Oficina;
use App\Models\SaasConfig;
use App\Services\Fiscal\Contracts\FiscalProvider;
use App\Services\Fiscal\Providers\FocusNfeProvider;
use App\Services\Fiscal\Providers\SpedyProvider;
use App\Tenancy\TenancyContext;
use Illuminate\Support\Facades\Crypt;

class FiscalProviderManager
{
    private const PROVEDORES = ['SPEDY', 'FOCUS'];

    public static function resolverProvedor(?string $override, string $padrao): string
    {
        if ($override !== null && in_array($override, self::PROVEDORES, true)) {
            return $override;
        }
        return in_array($padrao, self::PROVEDORES, true) ? $padrao : 'SPEDY';
    }

    public function provedorDaOficina(string $oficinaId): string
    {
        $oficina = Oficina::find($oficinaId);
        $cfg     = SaasConfig::get();
        return self::resolverProvedor($oficina?->provedor_fiscal, $cfg->provedor_fiscal_padrao ?? 'SPEDY');
    }

    public function ambienteDaOficina(): string
    {
        return Configuracao::first()?->ambiente_fiscal ?? 'HOMOLOGACAO';
    }

    /** Resolve o provider para o tenant atual, com token do emissor (se registrado). */
    public function forTenant(): FiscalProvider
    {
        $oficinaId = TenancyContext::get();
        if (!$oficinaId) {
            throw new \RuntimeException('Tenant não definido para emissão fiscal.');
        }

        $provedor = $this->provedorDaOficina($oficinaId);
        $ambiente = $this->ambienteDaOficina();
        $cfg      = SaasConfig::get();

        $emissor      = EmissorFiscal::where('oficina_id', $oficinaId)
            ->where('provedor', $provedor)
            ->where('ambiente', $ambiente)
            ->first();
        $emissorToken = $emissor?->token_encrypted ? ($this->decifrar($emissor->token_encrypted) ?: null) : null;
        $emissorExtId = $emissor?->emissor_externo_id;

        return $this->build($provedor, $ambiente, $cfg, $emissorToken, $emissorExtId);
    }

    public function build(string $provedor, string $ambiente, SaasConfig $cfg, ?string $emissorToken, ?string $emissorExtId): FiscalProvider
    {
        if ($provedor === 'FOCUS') {
            $baseUrl = $ambiente === 'PRODUCAO'
                ? (string) config('services.focusnfe.producao_url')
                : (string) config('services.focusnfe.homologacao_url');
            $master = $ambiente === 'PRODUCAO'
                ? $this->decifrar($cfg->getRawOriginal('focus_master_token_producao'))
                : $this->decifrar($cfg->getRawOriginal('focus_master_token_homologacao'));
            return new FocusNfeProvider($baseUrl, $master, $emissorToken);
        }

        // SPEDY
        $baseUrl = $ambiente === 'PRODUCAO'
            ? (string) config('services.spedy.producao_url')
            : (string) config('services.spedy.sandbox_url');
        $master = $ambiente === 'PRODUCAO'
            ? $this->decifrar($cfg->getRawOriginal('spedy_master_key_producao'))
            : $this->decifrar($cfg->getRawOriginal('spedy_master_key_sandbox'));
        return new SpedyProvider($baseUrl, $master, $emissorToken, $emissorExtId);
    }

    private function decifrar(?string $valor): string
    {
        if (empty($valor)) return '';
        try {
            return Crypt::decryptString($valor);
        } catch (\Throwable) {
            return $valor; // valor já em claro (compatibilidade)
        }
    }
}
