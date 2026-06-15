<?php

namespace App\Observers;

use App\Models\Project;
use App\Support\Cache\TenantCache;

class ProjectObserver
{
    public function __construct(private readonly TenantCache $cache) {}

    public function created(Project $project): void
    {
        $this->invalidate($project);
    }

    public function updated(Project $project): void
    {
        $this->invalidate($project);
    }

    public function deleted(Project $project): void
    {
        $this->invalidate($project);
    }

    /**
     * Flush the tenant's cached project list. tenant_id is read from the model
     * so this fires correctly for any write path, request-scoped or not.
     */
    private function invalidate(Project $project): void
    {
        $this->cache->flush($project->tenant_id, 'projects');
    }
}
