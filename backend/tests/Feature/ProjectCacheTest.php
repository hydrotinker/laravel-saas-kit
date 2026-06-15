<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesTenantUser;
use Tests\TestCase;

class ProjectCacheTest extends TestCase
{
    use CreatesTenantUser, RefreshDatabase;

    public function test_second_project_list_is_served_from_cache(): void
    {
        $session = $this->createTenantUser();
        Project::factory()->count(2)->for($session['tenant'])->create();

        // Warm the cache.
        $this->withToken($session['token'])->getJson('/api/projects')
            ->assertOk()->assertJsonCount(2);

        // Second read must not touch the projects table.
        $queries = $this->countQueriesAgainst('projects', function () use ($session) {
            $this->withToken($session['token'])->getJson('/api/projects')
                ->assertOk()->assertJsonCount(2);
        });

        $this->assertSame(0, $queries, 'Cached project list still queried the database.');
    }

    public function test_creating_a_project_invalidates_the_list(): void
    {
        $session = $this->createTenantUser();
        Project::factory()->for($session['tenant'])->create();

        // Warm with one project.
        $this->withToken($session['token'])->getJson('/api/projects')
            ->assertOk()->assertJsonCount(1);

        // Observer flushes the projects tag on create.
        $this->withToken($session['token'])
            ->postJson('/api/projects', ['name' => 'Fresh'])
            ->assertCreated();

        $this->withToken($session['token'])->getJson('/api/projects')
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonFragment(['name' => 'Fresh']);
    }

    public function test_project_mutation_flushes_only_the_projects_tag(): void
    {
        $session = $this->createTenantUser();
        $tid = $session['tenant']->id;

        // Warm both lists.
        $this->withToken($session['token'])->getJson('/api/projects')->assertOk();
        $this->withToken($session['token'])->getJson('/api/members')->assertOk();

        $this->assertNotNull($this->cached($tid, 'projects'), 'Projects list was not cached.');
        $this->assertNotNull($this->cached($tid, 'members'), 'Members list was not cached.');

        // Mutating a project must flush the projects tag only.
        $this->withToken($session['token'])
            ->postJson('/api/projects', ['name' => 'Roadmap'])
            ->assertCreated();

        $this->assertNull($this->cached($tid, 'projects'), 'Projects cache was not invalidated.');
        $this->assertNotNull($this->cached($tid, 'members'), 'Project mutation over-flushed the member cache.');
    }

    /** Read a tenant resource's cached "index" entry, or null if flushed. */
    private function cached(int $tenantId, string $resource): mixed
    {
        return Cache::store()
            ->tags(["t:{$tenantId}", "t:{$tenantId}:{$resource}"])
            ->get("t:{$tenantId}:{$resource}:index");
    }

    public function test_tenants_do_not_share_a_cached_project_list(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $sessionA = $this->createTenantUser(tenant: $tenantA);
        $sessionB = $this->createTenantUser(tenant: $tenantB);

        Project::factory()->for($tenantA)->create(['name' => 'Alpha only']);
        Project::factory()->for($tenantB)->create(['name' => 'Beta only']);

        // Warm tenant A's cache first.
        $this->withToken($sessionA['token'])->getJson('/api/projects')
            ->assertOk()->assertJsonFragment(['name' => 'Alpha only']);

        // Tenant B must get its own data, not A's cached payload.
        $this->withToken($sessionB['token'])->getJson('/api/projects')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment(['name' => 'Beta only'])
            ->assertJsonMissing(['name' => 'Alpha only']);
    }

    /**
     * Count queries issued against a given table while running the callback.
     */
    private function countQueriesAgainst(string $table, callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $callback();

        $count = collect(DB::getQueryLog())
            ->filter(fn (array $entry) => str_contains($entry['query'], $table))
            ->count();

        DB::disableQueryLog();

        return $count;
    }
}
