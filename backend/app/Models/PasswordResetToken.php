<?php
declare(strict_types=1);

namespace App\Models;

use App\Tenancy\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PasswordResetToken extends Model
{
    protected $table = 'password_reset_tokens_custom';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    use HasTenantScope;

    protected $fillable = ['usuario_id', 'token_hash', 'expires_at', 'usado', 'oficina_id'];

    protected $casts = [
        'expires_at' => 'datetime',
        'criado_em'  => 'datetime',
        'usado'      => 'boolean',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn($m) => $m->id = $m->id ?: (string) Str::uuid());
    }

    public function usuario(): BelongsTo { return $this->belongsTo(Usuario::class, 'usuario_id'); }
}
