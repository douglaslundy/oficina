<?php
declare(strict_types=1);

namespace App\Models;

use App\Tenancy\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CategoriaPadraoFiscal extends Model
{
    use HasTenantScope;

    /**
     * Categorias padrão do sistema. DEVE bater com a lista do select de
     * categoria em frontend/components/forms/ProdutoForm.tsx — se divergir,
     * uma categoria fica sem padrão fiscal possível.
     */
    public const CATEGORIAS = [
        'Filtros', 'Óleo/Fluidos', 'Freios', 'Suspensão', 'Elétrica', 'Motor', 'Outros',
    ];

    protected $table = 'categoria_padrao_fiscal';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'id', 'oficina_id', 'categoria', 'ncm', 'origem', 'tributacao_icms',
    ];

    protected $casts = [
        'criado_em' => 'datetime',
        'origem'    => 'integer',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn ($m) => $m->id = $m->id ?: (string) Str::uuid());
    }
}
