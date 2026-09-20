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

            // Models that resolve identity BEFORE tenant context exists (e.g. User,
            // looked up by EloquentUserProvider during login/session auth) opt out
            // of the query-time filter entirely. The tenant_id column, the tenant()
            // relation, and the save-time guard below still apply undiminished --
            // only the WHERE/fail-closed filter on reads is skipped for these models.
            if (property_exists($builder->getModel(), 'skipTenantQueryScope')
                && $builder->getModel()::$skipTenantQueryScope === true) {
                return;
            }

            if (TenantContext::hasTenant()) {
                $builder->where($builder->getModel()->getTable() . '.tenant_id', TenantContext::id());
                return;
            }

            $builder->whereRaw('1 = 0'); // no tenant: return nothing instead of everything
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

