<?php

namespace App\Models\Concerns;

use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Scopes a model to the current tenant.
 *
 * Adds a global scope that filters every query by the resolved tenant and
 * auto-fills `tenant_id` on create, so callers never write tenant `where`
 * clauses by hand.
 */
#[ScopedBy(TenantScope::class)]
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::creating(function ($model): void {
            if ($model->tenant_id === null) {
                $tenantId = app(TenantContext::class)->id();

                if ($tenantId !== null) {
                    $model->tenant_id = $tenantId;
                }
            }
        });
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
