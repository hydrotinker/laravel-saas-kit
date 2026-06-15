<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenantUser;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use CreatesTenantUser, RefreshDatabase;

    public function test_user_only_sees_their_own_tenants_projects(): void
    {
        $a = $this->createTenantUser();
        $b = $this->createTenantUser();

        Project::factory()->for($a['tenant'])->create(['name' => 'A project']);
        Project::factory()->for($b['tenant'])->create(['name' => 'B project']);

        $this->withToken($a['token'])->getJson('/api/projects')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment(['name' => 'A project'])
            ->assertJsonMissing(['name' => 'B project']);
    }

    public function test_user_cannot_read_another_tenants_project_by_id(): void
    {
        $a = $this->createTenantUser();
        $b = $this->createTenantUser();

        $foreign = Project::factory()->for($b['tenant'])->create();

        $this->withToken($a['token'])->getJson("/api/projects/{$foreign->id}")
            ->assertNotFound();
    }

    public function test_user_cannot_read_another_tenants_task(): void
    {
        $a = $this->createTenantUser();
        $b = $this->createTenantUser();

        $foreignProject = Project::factory()->for($b['tenant'])->create();
        $foreignTask = Task::factory()->for($foreignProject)->create();

        // Both the nested list and the single task are unreachable: the parent
        // project itself is filtered out by the tenant scope.
        $this->withToken($a['token'])
            ->getJson("/api/projects/{$foreignProject->id}/tasks")
            ->assertNotFound();

        $this->withToken($a['token'])
            ->getJson("/api/projects/{$foreignProject->id}/tasks/{$foreignTask->id}")
            ->assertNotFound();
    }

    public function test_user_cannot_update_or_delete_another_tenants_project(): void
    {
        $a = $this->createTenantUser();
        $b = $this->createTenantUser();

        $foreign = Project::factory()->for($b['tenant'])->create(['name' => 'B project']);

        $this->withToken($a['token'])
            ->putJson("/api/projects/{$foreign->id}", ['name' => 'hijacked'])
            ->assertNotFound();

        $this->withToken($a['token'])
            ->deleteJson("/api/projects/{$foreign->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('projects', [
            'id' => $foreign->id,
            'name' => 'B project',
        ]);
    }

    public function test_tenant_id_in_payload_cannot_override_resolved_tenant(): void
    {
        $a = $this->createTenantUser();
        $b = $this->createTenantUser();

        $this->withToken($a['token'])
            ->postJson('/api/projects', [
                'name' => 'A project',
                'tenant_id' => $b['tenant']->id,
            ])
            ->assertCreated();

        $this->assertDatabaseHas('projects', [
            'name' => 'A project',
            'tenant_id' => $a['tenant']->id,
        ]);
        $this->assertDatabaseMissing('projects', [
            'name' => 'A project',
            'tenant_id' => $b['tenant']->id,
        ]);
    }

    public function test_task_cannot_be_assigned_to_a_foreign_tenants_user(): void
    {
        $a = $this->createTenantUser();
        $b = $this->createTenantUser();

        $project = Project::factory()->for($a['tenant'])->create();
        $foreignUser = User::factory()->create();
        $b['tenant']->users()->attach($foreignUser, ['role' => 'member']);

        $this->withToken($a['token'])
            ->postJson("/api/projects/{$project->id}/tasks", [
                'title' => 'Leaky task',
                'assignee_id' => $foreignUser->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('assignee_id');
    }

    public function test_user_cannot_update_another_tenants_member(): void
    {
        $a = $this->createTenantUser();
        $b = $this->createTenantUser();

        $this->withToken($a['token'])
            ->patchJson("/api/members/{$b['user']->id}", ['role' => 'member'])
            ->assertNotFound();

        $this->assertDatabaseHas('tenant_user', [
            'tenant_id' => $b['tenant']->id,
            'user_id' => $b['user']->id,
            'role' => 'owner',
        ]);
    }

    public function test_user_cannot_remove_another_tenants_member(): void
    {
        $a = $this->createTenantUser();
        $b = $this->createTenantUser();

        $this->withToken($a['token'])
            ->deleteJson("/api/members/{$b['user']->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('tenant_user', [
            'tenant_id' => $b['tenant']->id,
            'user_id' => $b['user']->id,
        ]);
    }

    public function test_request_without_token_is_unauthenticated(): void
    {
        $this->getJson('/api/projects')->assertStatus(401);
    }

    public function test_token_for_tenant_the_user_left_is_forbidden(): void
    {
        $tenant = Tenant::factory()->create();
        $session = $this->createTenantUser(tenant: $tenant);

        // User is removed from the tenant after the token was minted.
        $tenant->users()->detach($session['user']->id);

        $this->withToken($session['token'])->getJson('/api/projects')->assertStatus(403);
    }
}
