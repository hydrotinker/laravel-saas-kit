<?php

namespace App\Data;

use App\Models\User;
use Spatie\LaravelData\Attributes\Validation\Email;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Unique;
use Spatie\LaravelData\Data;

class RegisterData extends Data
{
    public function __construct(
        #[Max(255)]
        public string $organization_name,
        #[Max(255)]
        public string $name,
        #[Email, Max(255), Unique(User::class, 'email')]
        public string $email,
        #[Min(8)]
        public string $password,
    ) {}
}
