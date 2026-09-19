<?php

namespace App\Domain\Tenant\Concerns;

use App\Domain\Tenant\TenantContext;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\App;
use RuntimeException;


trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder) {
            if (App::runningInConsole() && !App::runningUnitTests()) {

                return;
            }

            if (TenantContext::hasTenant()) {
                $builder->where($builder->getModel()->getTable() . '.tenant_id', TenantContext::id());
            }
        });


        static::saving(function (Model $model) {
            if (empty($model->tenant_id)) {
                if (! TenantContext::hasTenant()) {
                    throw new RuntimeException(
                        static::class . ' cannot be saved without a resolved tenant context.'
                    );
                }

                $model->tenant_id = TenantContext::id();

                return;
            }

            if (TenantContext::hasTenant() && $model->tenant_id !== TenantContext::id()) {
                throw new RuntimeException(
                    'TenantGuard violation: attempted to save a ' . static::class . ' row for tenant '
                    . $model->tenant_id . ' while resolved tenant context is ' . TenantContext::id()
                );
            }
        });
    }

    public function scopeWithoutTenantScope(Builder $query): Builder
    {
        return $query->withoutGlobalScope('tenant');
    }

    public function tenant():BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
