<?php
declare(strict_types=1);

namespace App\Models;

use App\Tenancy\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class NotaFiscal extends Model
{
    use HasTenantScope;

    protected $table = 'notas_fiscais';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'numero', 'serie', 'modelo', 'cliente_id', 'os_id',
        'natureza_operacao', 'forma_pagamento', 'subtotal', 'desconto',
        'aliquota_iss', 'valor_iss', 'valor_total', 'status',
        'chave_acesso', 'protocolo', 'xml_retorno', 'pdf_url', 'observacoes', 'emitido_em',
        'oficina_id',
    ];

    protected $casts = [
        'emitido_em' => 'datetime',
        'criado_em'  => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn($m) => $m->id = $m->id ?: (string) Str::uuid());
    }

    public function cliente(): BelongsTo { return $this->belongsTo(Cliente::class, 'cliente_id'); }
    public function ordemServico(): BelongsTo { return $this->belongsTo(OrdemServico::class, 'os_id'); }
}
