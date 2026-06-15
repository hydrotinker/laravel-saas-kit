<?php

namespace Tests\Concerns;

use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TokenService;

trait CreatesTenantUser
{
    /**
     * Create a tenant with a member user and return the tenant, user, and a
     * valid bearer access token for them.
     *
     * @return array{tenant: Tenant, user: User, token: string}
     */
    protected function createTenantUser(TenantRole $role = TenantRole::Owner, ?Tenant $tenant = null): array
    {
        $tenant ??= Tenant::factory()->create();
        $user = User::factory()->create();

        $tenant->users()->attach($user, ['role' => $role->value]);

        $token = app(TokenService::class)->issueAccessToken($user, $tenant);

        return ['tenant' => $tenant, 'user' => $user, 'token' => $token];
    }
}
