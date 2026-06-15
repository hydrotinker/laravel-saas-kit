<?php

namespace App\Policies\Concerns;

use App\Enums\TenantRole;
use App\Models\User;
use App\Support\TenantContext;

trait ResolvesTenantRole
{
    protected function role(User $user): ?TenantRole
    {
        $tenantId = app(TenantContext::class)->id();

        return $tenantId !== null ? $user->roleIn($tenantId) : null;
    }

    protected function canManage(User $user): bool
    {
        return $this->role($user)?->canManageTenant() ?? false;
    }

    protected function isMember(User $user): bool
    {
        return $this->role($user) !== null;
    }
}
