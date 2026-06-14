<?php
declare(strict_types=1);

namespace App\Tenancy;

use Illuminate\Database\Eloquent\Builder;

trait HasTenantScope
{
    public static function bootHasTenantScope(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder) {
            if (TenancyContext::has()) {
                $builder->where(static::tenantColumn(), TenancyContext::get());
            }
        });

        static::creating(function ($model) {
            if (TenancyContext::has() && empty($model->{static::tenantColumn()})) {
                $model->{static::tenantColumn()} = TenancyContext::get();
            }
        });
    }

    protected static function tenantColumn(): string
    {
        return 'oficina_id';
    }
}
