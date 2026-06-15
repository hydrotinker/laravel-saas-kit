<?php

namespace Tests\Feature;

use App\Enums\ProjectStatus;
use App\Enums\TaskStatus;
use App\Enums\TenantRole;
use App\Models\Project;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenantUser;
use Tests\TestCase;

class ProjectTaskCrudTest extends TestCase
{
    use CreatesTenantUser, RefreshDatabase;

    public function test_member_can_create_and_list_projects(): void
    {
        $session = $this->createTenantUser(TenantRole::Member);

        $this->withToken($session['token'])
            ->postJson('/api/projects', ['name' => 'Roadmap', 'description' => 'Q3 plans'])
            ->assertCreated()
            ->assertJsonFragment(['name' => 'Roadmap', 'status' => 'active']);

        $this->assertDatabaseHas('projects', [
            'name' => 'Roadmap',
            'tenant_id' => $session['tenant']->id,
        ]);
    }

    public function test_member_cannot_delete_project_but_owner_can(): void
    {
        $tenant = Tenant::factory()->create();
        $member = $this->createTenantUser(TenantRole::Member, $tenant);
        $owner = $this->createTenantUser(TenantRole::Owner, $tenant);

        $project = Project::factory()->for($tenant)->create();

        $this->withToken($member['token'])->deleteJson("/api/projects/{$project->id}")->assertForbidden();
        $this->withToken($owner['token'])->deleteJson("/api/projects/{$project->id}")->assertNoContent();

        $this->assertDatabaseMissing('projects', ['id' => $project->id]);
    }

    public function test_full_task_lifecycle_within_a_project(): void
    {
        $session = $this->createTenantUser();
        $project = Project::factory()->for($session['tenant'])->create();
        $token = $session['token'];

        $taskId = $this->withToken($token)
            ->postJson("/api/projects/{$project->id}/tasks", ['title' => 'Write tests'])
            ->assertCreated()
            ->assertJsonFragment(['title' => 'Write tests', 'status' => 'todo'])
            ->json('id');

        $this->withToken($token)
            ->putJson("/api/projects/{$project->id}/tasks/{$taskId}", [
                'title' => 'Write tests',
                'status' => TaskStatus::Done->value,
            ])
            ->assertOk()
            ->assertJsonFragment(['status' => 'done']);

        $this->withToken($token)->getJson("/api/projects/{$project->id}/tasks")
            ->assertOk()->assertJsonCount(1);

        $this->withToken($token)->deleteJson("/api/projects/{$project->id}/tasks/{$taskId}")
            ->assertNoContent();

        $this->assertDatabaseMissing('tasks', ['id' => $taskId]);
    }

    public function test_project_update_can_archive(): void
    {
        $session = $this->createTenantUser();
        $project = Project::factory()->for($session['tenant'])->create();

        $this->withToken($session['token'])
            ->putJson("/api/projects/{$project->id}", [
                'name' => $project->name,
                'status' => ProjectStatus::Archived->value,
            ])
            ->assertOk()
            ->assertJsonFragment(['status' => 'archived']);
    }
}
