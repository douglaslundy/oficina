<?php
declare(strict_types=1);

namespace App\Models;

use App\Tenancy\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ProdutoFiscalDivergencia extends Model
{
    use HasTenantScope;

    protected $table = 'produto_fiscal_divergencias';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'id', 'oficina_id', 'produto_id', 'nota_entrada_id',
        'campo', 'valor_atual', 'valor_xml', 'resolvido_em', 'resolucao',
    ];

    protected $casts = [
        'criado_em'    => 'datetime',
        'resolvido_em' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn ($m) => $m->id = $m->id ?: (string) Str::uuid());
    }

    public function produto(): BelongsTo
    {
        return $this->belongsTo(Produto::class, 'produto_id');
    }

    public function notaEntrada(): BelongsTo
    {
        return $this->belongsTo(NotaEntrada::class, 'nota_entrada_id');
    }
}
