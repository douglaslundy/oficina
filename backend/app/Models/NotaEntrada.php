<?php
declare(strict_types=1);

namespace App\Models;

use App\Tenancy\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class NotaEntrada extends Model
{
    use HasTenantScope;

    protected $table = 'notas_entrada';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'numero_nf', 'serie', 'chave_acesso', 'fornecedor_nome', 'fornecedor_cnpj',
        'valor_total', 'data_emissao', 'xml_original', 'usuario_id', 'oficina_id',
    ];

    protected $casts = [
        'criado_em'    => 'datetime',
        'data_emissao' => 'date',
        'valor_total'  => 'float',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn($m) => $m->id = $m->id ?: (string) Str::uuid());
    }

    public function itens(): HasMany
    {
        return $this->hasMany(NotaEntradaItem::class, 'nota_entrada_id');
    }
}
