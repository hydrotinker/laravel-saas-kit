<?php

namespace App\Data;

use App\Enums\TenantRole;
use App\Models\User;
use Spatie\LaravelData\Data;

class MemberData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public TenantRole $role,
    ) {}

    /**
     * Build from a User that has the `tenant_user` pivot loaded.
     */
    public static function fromModel(User $user): self
    {
        return new self(
            id: $user->id,
            name: $user->name,
            email: $user->email,
            role: TenantRole::from($user->pivot->role),
        );
    }
}
