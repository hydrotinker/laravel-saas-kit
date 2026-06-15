<?php

namespace App\Models\Scopes;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Constrains queries to the tenant resolved for the current request.
 *
 * When no tenant is resolved (e.g. console, seeders, tests setting up
 * fixtures) the scope is a no-op so global operations still work.
 *
 * @implements Scope<Model>
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenantId = app(TenantContext::class)->id();

        if ($tenantId !== null) {
            $builder->where($model->qualifyColumn('tenant_id'), $tenantId);
        }
    }
}
