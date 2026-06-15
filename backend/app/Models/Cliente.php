<?php
declare(strict_types=1);

namespace App\Models;

use App\Tenancy\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use App\Tenancy\TenancyContext;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Cliente extends Model
{
    use HasTenantScope, LogsActivity;

    protected $table = 'clientes';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'nome', 'cpf_cnpj', 'telefone', 'email',
        'cep', 'endereco', 'bairro', 'cidade', 'uf',
        'veiculo_modelo', 'veiculo_ano', 'veiculo_placa', 'status', 'oficina_id',
    ];

    protected $casts = ['criado_em' => 'datetime'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->useLogName(TenancyContext::getSlug() ?? 'default');
    }

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn($m) => $m->id = $m->id ?: (string) Str::uuid());
    }

    public function ordensServico(): HasMany
    {
        return $this->hasMany(OrdemServico::class, 'cliente_id');
    }

    public function veiculos(): HasMany
    {
        return $this->hasMany(Veiculo::class, 'cliente_id');
    }
}
