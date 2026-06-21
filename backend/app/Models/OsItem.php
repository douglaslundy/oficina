<?php
declare(strict_types=1);

namespace App\Models;

use App\Tenancy\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class OsItem extends Model
{
    use HasTenantScope;

    protected $table = 'os_itens';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'os_id', 'tipo', 'produto_id', 'descricao',
        'quantidade', 'valor_unitario', 'oficina_id', 'aprovado',
    ];

    protected $casts = [
        'quantidade'     => 'float',
        'valor_unitario' => 'float',
        'valor_total'    => 'float',
        'aprovado'       => 'boolean',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn($m) => $m->id = $m->id ?: (string) Str::uuid());
    }

    public function produto(): BelongsTo { return $this->belongsTo(Produto::class, 'produto_id'); }
}
