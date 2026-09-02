<?php
declare(strict_types=1);

namespace App\Models;

use App\Tenancy\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class NotaFiscalItem extends Model
{
    use HasTenantScope;

    protected $table = 'notas_fiscais_itens';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'nota_fiscal_id', 'produto_id', 'sku', 'oficina_id', 'descricao', 'unidade',
        'ncm', 'cfop', 'origem', 'tributacao_icms', 'cst_csosn',
        'quantidade', 'valor_unitario',
    ];

    protected $casts = [
        'origem'         => 'integer',
        'quantidade'     => 'float',
        'valor_unitario' => 'float',
        'valor_total'    => 'float',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn($m) => $m->id = $m->id ?: (string) Str::uuid());
    }

    public function produto(): BelongsTo { return $this->belongsTo(Produto::class, 'produto_id'); }
}
