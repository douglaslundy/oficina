<?php
declare(strict_types=1);

namespace App\Models;

use App\Tenancy\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class WhatsAppConfig extends Model
{
    use HasTenantScope;

    protected $table      = 'whatsapp_configs';
    protected $primaryKey = 'id';
    public    $incrementing = false;
    protected $keyType    = 'string';
    public    $timestamps = false;

    protected $fillable = [
        'oficina_id',
        'evolution_url',
        'evolution_api_key',
        'instance_name',
        'instance_token',
        'ativo',
    ];

    protected $hidden = ['evolution_api_key', 'instance_token'];

    protected $casts = [
        'ativo'       => 'boolean',
        'criado_em'   => 'datetime',
        'atualizado_em' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn($m) => $m->id ??= (string) Str::uuid());
    }

    public function mascarado(): array
    {
        return [
            'id'               => $this->id,
            'oficina_id'       => $this->oficina_id,
            'evolution_url'    => $this->evolution_url,
            'evolution_api_key' => $this->mascarar($this->getRawOriginal('evolution_api_key')),
            'instance_name'    => $this->instance_name,
            'instance_token'   => $this->mascarar($this->getRawOriginal('instance_token')),
            'ativo'            => $this->ativo,
        ];
    }

    private function mascarar(?string $v): ?string
    {
        if (!$v) return null;
        return str_repeat('*', max(0, strlen($v) - 6)) . substr($v, -6);
    }
}
