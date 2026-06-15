<?php

namespace App\Services;

use App\Enums\TenantRole;
use App\Mail\WelcomeEmail;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class RegistrationService
{
    /**
     * Atomically create an organization with its first (owner) user.
     *
     * @return array{tenant: Tenant, user: User}
     */
    public function register(string $organizationName, string $name, string $email, string $password): array
    {
        ['tenant' => $tenant, 'user' => $user] = DB::transaction(function () use ($organizationName, $name, $email, $password) {
            $tenant = Tenant::create([
                'name' => $organizationName,
                'slug' => $this->uniqueSlug($organizationName),
            ]);

            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => $password,
            ]);

            $tenant->users()->attach($user, ['role' => TenantRole::Owner->value]);

            return ['tenant' => $tenant, 'user' => $user];
        });

        // Dispatch the welcome email onto the Redis queue *after* the transaction
        // commits, so a rolled-back registration never enqueues an email for a
        // user that no longer exists. The dedicated worker container delivers it.
        Mail::to($user->email)->queue(new WelcomeEmail($user, $tenant));

        return ['tenant' => $tenant, 'user' => $user];
    }

    protected function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'org';
        $slug = $base;
        $suffix = 1;

        while (Tenant::where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$suffix);
        }

        return $slug;
    }
}
