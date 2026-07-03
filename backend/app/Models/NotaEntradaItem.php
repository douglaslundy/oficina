<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class NotaEntradaItem extends Model
{
    protected $table = 'notas_entrada_itens';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'nota_entrada_id', 'produto_id', 'codigo_barras_xml', 'descricao_xml',
        'quantidade', 'valor_unitario', 'produto_criado',
    ];

    protected $casts = [
        'quantidade'     => 'float',
        'valor_unitario' => 'float',
        'produto_criado' => 'boolean',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn($m) => $m->id = $m->id ?: (string) Str::uuid());
    }

    public function notaEntrada(): BelongsTo
    {
        return $this->belongsTo(NotaEntrada::class, 'nota_entrada_id');
    }
}
