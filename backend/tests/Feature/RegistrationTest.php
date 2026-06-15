<?php

namespace Tests\Feature;

use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RegistrationService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_creates_tenant_and_owner_user_and_returns_tokens(): void
    {
        $response = $this->postJson('/api/register', [
            'organization_name' => 'Acme Inc',
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'password123',
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['access_token', 'refresh_token', 'token_type', 'expires_in']);

        $this->assertDatabaseHas('tenants', ['name' => 'Acme Inc', 'slug' => 'acme-inc']);
        $this->assertDatabaseHas('users', ['email' => 'ada@example.com']);

        $tenant = Tenant::firstWhere('slug', 'acme-inc');
        $user = User::firstWhere('email', 'ada@example.com');

        $this->assertSame(TenantRole::Owner, $user->roleIn($tenant->id));
    }

    public function test_register_rejects_duplicate_email(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->postJson('/api/register', [
            'organization_name' => 'Acme Inc',
            'name' => 'Ada',
            'email' => 'taken@example.com',
            'password' => 'password123',
        ])->assertUnprocessable()->assertJsonValidationErrorFor('email');

        $this->assertDatabaseMissing('tenants', ['name' => 'Acme Inc']);
    }

    public function test_registration_rolls_back_tenant_when_user_creation_fails(): void
    {
        // Pre-existing user; bypassing validation, the DB unique constraint
        // fires mid-transaction and must roll back the just-created tenant.
        User::factory()->create(['email' => 'dupe@example.com']);

        $tenantsBefore = Tenant::count();

        try {
            app(RegistrationService::class)->register('Orphan Org', 'Bob', 'dupe@example.com', 'password123');
            $this->fail('Expected a QueryException for the duplicate email.');
        } catch (QueryException) {
            // expected
        }

        $this->assertSame($tenantsBefore, Tenant::count());
        $this->assertDatabaseMissing('tenants', ['name' => 'Orphan Org']);
    }
}
