<?php
declare(strict_types=1);

namespace App\Models;

use App\Tenancy\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use App\Tenancy\TenancyContext;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class NotaFiscal extends Model
{
    use HasTenantScope, LogsActivity;

    protected $table = 'notas_fiscais';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'numero', 'serie', 'modelo', 'cliente_id', 'os_id',
        'natureza_operacao', 'forma_pagamento', 'subtotal', 'desconto',
        'aliquota_iss', 'valor_iss', 'valor_total', 'status',
        'chave_acesso', 'protocolo', 'xml_retorno', 'pdf_url', 'qrcode_url', 'mensagem_erro', 'observacoes', 'informacoes_complementares', 'emitido_em',
        'oficina_id',
        'provedor', 'ambiente', 'referencia_externa', 'contingencia_desde',
    ];

    protected $casts = [
        'emitido_em' => 'datetime',
        'criado_em'  => 'datetime',
        'contingencia_desde' => 'datetime',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'numero', 'valor_total', 'chave_acesso'])
            ->logOnlyDirty()
            ->useLogName(TenancyContext::getSlug() ?? 'default');
    }

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn($m) => $m->id = $m->id ?: (string) Str::uuid());
    }

    /**
     * Texto de dados adicionais que foi de fato enviado no XML (NF-e `infCpl` /
     * NFS-e `xInfComp`) — é ele que os PDFs mostram, pra a nota impressa bater
     * com o documento fiscal. Null em nota sem XML ou sem esse campo.
     */
    public function getInformacoesComplementaresXmlAttribute(): ?string
    {
        if (!empty($this->xml_retorno)
            && preg_match('#<(infCpl|xInfComp)>(.*?)</\1>#s', (string) $this->xml_retorno, $m) === 1) {
            $texto = trim(html_entity_decode($m[2], ENT_QUOTES | ENT_XML1, 'UTF-8'));
            if ($texto !== '') {
                return $texto;
            }
        }

        // Spedy/Focus nem sempre devolvem o campo no XML: cai pro snapshot do
        // que foi enviado na emissão (coluna informacoes_complementares).
        $snapshot = trim((string) $this->getAttribute('informacoes_complementares'));

        return $snapshot === '' ? null : $snapshot;
    }

    public function cliente(): BelongsTo { return $this->belongsTo(Cliente::class, 'cliente_id'); }
    public function ordemServico(): BelongsTo { return $this->belongsTo(OrdemServico::class, 'os_id'); }
    public function itens(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(NotaFiscalItem::class, 'nota_fiscal_id');
    }
}
