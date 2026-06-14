<?php
declare(strict_types=1);

namespace App\Models;

use App\Tenancy\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Configuracao extends Model
{
    use HasTenantScope;

    protected $table = 'configuracoes';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'razao_social', 'nome_fantasia', 'cnpj', 'inscricao_estadual',
        'inscricao_municipal', 'regime_tributario', 'cep', 'endereco',
        'cidade', 'uf', 'telefone', 'email', 'ambiente_fiscal', 'serie_nf',
        'proximo_numero_nf', 'aliquota_iss', 'cnae', 'codigo_ibge',
        'estoque_limite_padrao', 'alertas_email', 'email_alertas', 'certificado_pfx_encrypted',
        'oficina_id',
    ];

    protected $casts = ['alertas_email' => 'boolean'];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn($m) => $m->id = $m->id ?: (string) Str::uuid());
    }
}
