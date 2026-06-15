<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;
use App\Policies\Concerns\ResolvesTenantRole;

class ProjectPolicy
{
    use ResolvesTenantRole;

    public function viewAny(User $user): bool
    {
        return $this->isMember($user);
    }

    public function view(User $user, Project $project): bool
    {
        return $this->isMember($user);
    }

    public function create(User $user): bool
    {
        return $this->isMember($user);
    }

    public function update(User $user, Project $project): bool
    {
        return $this->isMember($user);
    }

    public function delete(User $user, Project $project): bool
    {
        return $this->canManage($user);
    }
}
