<?php

namespace App\Policies;

use App\Models\Tenant;
use App\Models\User;
use App\Policies\Concerns\ResolvesTenantRole;

class MemberPolicy
{
    use ResolvesTenantRole;

    public function viewAny(User $user, Tenant $tenant): bool
    {
        return $this->isMember($user);
    }

    public function manage(User $user, Tenant $tenant): bool
    {
        return $this->canManage($user);
    }
}
