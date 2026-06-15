<?php

namespace Tests\Feature;

use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenantUser;
use Tests\TestCase;

class MemberTest extends TestCase
{
    use CreatesTenantUser, RefreshDatabase;

    public function test_owner_can_add_an_existing_user_as_a_member(): void
    {
        $session = $this->createTenantUser(TenantRole::Owner);
        $invitee = User::factory()->create(['email' => 'bob@example.com']);

        $this->withToken($session['token'])
            ->postJson('/api/members', ['email' => 'bob@example.com', 'role' => 'member'])
            ->assertCreated()
            ->assertJsonFragment(['email' => 'bob@example.com', 'role' => 'member']);

        $this->assertDatabaseHas('tenant_user', [
            'tenant_id' => $session['tenant']->id,
            'user_id' => $invitee->id,
            'role' => 'member',
        ]);
    }

    public function test_member_cannot_add_members(): void
    {
        $session = $this->createTenantUser(TenantRole::Member);
        User::factory()->create(['email' => 'bob@example.com']);

        $this->withToken($session['token'])
            ->postJson('/api/members', ['email' => 'bob@example.com'])
            ->assertForbidden();
    }

    public function test_adding_unknown_email_fails_validation(): void
    {
        $session = $this->createTenantUser(TenantRole::Owner);

        $this->withToken($session['token'])
            ->postJson('/api/members', ['email' => 'ghost@example.com'])
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('email');
    }

    public function test_owner_can_list_members(): void
    {
        $session = $this->createTenantUser(TenantRole::Owner);

        $this->withToken($session['token'])->getJson('/api/members')
            ->assertOk()->assertJsonCount(1);
    }

    public function test_cannot_demote_the_last_owner(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->createTenantUser(TenantRole::Owner, $tenant);

        $this->withToken($owner['token'])
            ->patchJson("/api/members/{$owner['user']->id}", ['role' => 'member'])
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('member');
    }

    public function test_owner_can_remove_a_member(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->createTenantUser(TenantRole::Owner, $tenant);
        $member = $this->createTenantUser(TenantRole::Member, $tenant);

        $this->withToken($owner['token'])
            ->deleteJson("/api/members/{$member['user']->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('tenant_user', [
            'tenant_id' => $tenant->id,
            'user_id' => $member['user']->id,
        ]);
    }
}
