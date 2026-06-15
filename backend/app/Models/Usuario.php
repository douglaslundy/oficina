<?php
declare(strict_types=1);

namespace App\Models;

use App\Tenancy\HasTenantScope;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use App\Tenancy\TenancyContext;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Usuario extends Authenticatable
{
    use HasApiTokens, Notifiable, HasTenantScope, LogsActivity;

    protected $table = 'usuarios';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'nome', 'email', 'cpf', 'telefone',
        'role', 'status', 'senha_hash', 'oficina_id',
    ];

    protected $hidden = ['senha_hash'];

    protected $casts = [
        'ultimo_acesso' => 'datetime',
        'criado_em'     => 'datetime',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['nome', 'email', 'role', 'status'])
            ->logOnlyDirty()
            ->useLogName(TenancyContext::getSlug() ?? 'default');
    }

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    public function getAuthPassword(): string
    {
        return $this->senha_hash;
    }
}
