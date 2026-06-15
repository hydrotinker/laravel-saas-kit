<?php

namespace Database\Seeders;

use App\Enums\TenantRole;
use App\Models\Project;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed a demo organization with an owner and some projects/tasks.
     */
    public function run(): void
    {
        $tenant = Tenant::factory()->create([
            'name' => 'Acme Inc',
            'slug' => 'acme',
        ]);

        $owner = User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $tenant->users()->attach($owner, ['role' => TenantRole::Owner->value]);

        Project::factory(3)
            ->for($tenant)
            ->create()
            ->each(fn (Project $project) => Task::factory(4)->create([
                'project_id' => $project->id,
                'tenant_id' => $tenant->id,
                'assignee_id' => $owner->id,
            ]));
    }
}
