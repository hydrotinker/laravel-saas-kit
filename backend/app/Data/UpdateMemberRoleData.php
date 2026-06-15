<?php

namespace App\Data;

use App\Enums\TenantRole;
use Spatie\LaravelData\Data;

class UpdateMemberRoleData extends Data
{
    public function __construct(
        public TenantRole $role,
    ) {}
}
