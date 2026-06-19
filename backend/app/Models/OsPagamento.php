<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class OsPagamento extends Model
{
    protected $table = 'os_pagamentos';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'os_id',
        'forma_pagamento',
        'valor',
    ];

    protected $casts = [
        'valor'      => 'decimal:2',
        'criado_em'  => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    public function os(): BelongsTo
    {
        return $this->belongsTo(OrdemServico::class, 'os_id');
    }
}
