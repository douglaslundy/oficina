<?php
declare(strict_types=1);

namespace App\Models;

use App\Tenancy\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MovimentacaoEstoque extends Model
{
    protected $table = 'movimentacoes_estoque';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    use HasTenantScope;

    protected $fillable = ['produto_id', 'tipo', 'quantidade', 'motivo', 'os_id', 'nota_entrada_id', 'usuario_id', 'oficina_id'];
    protected $casts = ['criado_em' => 'datetime'];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn($m) => $m->id = $m->id ?: (string) Str::uuid());
    }
}
