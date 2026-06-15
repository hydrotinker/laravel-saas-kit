<?php

namespace App\Support;

use App\Models\Tenant;
use RuntimeException;

/**
 * Request-scoped holder for the tenant resolved from the JWT.
 *
 * Registered as a singleton; the ResolveTenant middleware populates it and the
 * BelongsToTenant global scope reads from it to isolate queries per tenant.
 */
class TenantContext
{
    protected ?Tenant $tenant = null;

    public function set(Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function get(): ?Tenant
    {
        return $this->tenant;
    }

    public function id(): ?int
    {
        return $this->tenant?->id;
    }

    public function has(): bool
    {
        return $this->tenant !== null;
    }

    public function getOrFail(): Tenant
    {
        return $this->tenant ?? throw new RuntimeException('No tenant resolved for the current request.');
    }
}
