<?php

namespace App\Observers;

use App\Models\Task;
use App\Support\Cache\TenantCache;

class TaskObserver
{
    public function __construct(private readonly TenantCache $cache) {}

    public function created(Task $task): void
    {
        $this->invalidate($task);
    }

    public function updated(Task $task): void
    {
        $this->invalidate($task);
    }

    public function deleted(Task $task): void
    {
        $this->invalidate($task);
    }

    /**
     * Flush the project's cached task list. tenant_id and project_id are read
     * from the model so this fires correctly for any write path.
     */
    private function invalidate(Task $task): void
    {
        $this->cache->flush($task->tenant_id, "project:{$task->project_id}:tasks");
    }
}
