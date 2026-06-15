<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;
use App\Policies\Concerns\ResolvesTenantRole;

class TaskPolicy
{
    use ResolvesTenantRole;

    public function viewAny(User $user): bool
    {
        return $this->isMember($user);
    }

    public function view(User $user, Task $task): bool
    {
        return $this->isMember($user);
    }

    public function create(User $user): bool
    {
        return $this->isMember($user);
    }

    public function update(User $user, Task $task): bool
    {
        return $this->isMember($user);
    }

    public function delete(User $user, Task $task): bool
    {
        return $this->isMember($user);
    }
}
