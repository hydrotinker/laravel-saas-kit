<?php

namespace App\Data;

use App\Enums\TenantRole;
use Spatie\LaravelData\Attributes\Validation\Email;
use Spatie\LaravelData\Data;

class AddMemberData extends Data
{
    public function __construct(
        #[Email]
        public string $email,
        public TenantRole $role = TenantRole::Member,
    ) {}
}
