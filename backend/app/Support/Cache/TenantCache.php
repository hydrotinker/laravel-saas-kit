<?php

namespace App\Support\Cache;

use App\Support\TenantContext;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Tenant-aware read-through cache.
 *
 * Every entry carries two tags — a broad tenant tag and a narrow resource tag —
 * so a mutation can flush a single resource (e.g. projects) without wiping the
 * rest of the tenant's cache. Keys and tags always embed the tenant id, which is
 * the guard against cross-tenant leakage in this single-database design.
 *
 * Reads resolve the tenant from the request-scoped TenantContext; invalidation
 * takes the tenant id explicitly (from the mutated model) so it stays correct
 * from queues, console, and tinker where no request tenant is set.
 */
class TenantCache
{
    /** Stale-while-revalidate window: fresh 5 min, serve-stale up to 15 min. */
    private const TTL = [300, 900];

    public function __construct(private readonly TenantContext $context) {}

    /**
     * Read-through with stampede protection.
     *
     * @param  string  $resource  e.g. "projects", "members", "project:7:tasks"
     * @param  string  $suffix  e.g. "index"
     */
    public function remember(string $resource, string $suffix, Closure $callback): mixed
    {
        $tid = $this->context->getOrFail()->id;

        return Cache::store()
            ->tags([$this->tenantTag($tid), $this->resourceTag($tid, $resource)])
            ->flexible("t:{$tid}:{$resource}:{$suffix}", self::TTL, $callback);
    }

    /**
     * Invalidate one resource for a tenant. The tenant id is passed explicitly
     * (from the mutated model) rather than read from TenantContext.
     */
    public function flush(int $tenantId, string $resource): void
    {
        Cache::store()
            ->tags([$this->resourceTag($tenantId, $resource)])
            ->flush();
    }

    /** Flush a tenant's entire cache footprint (e.g. offboarding). */
    public function flushTenant(int $tenantId): void
    {
        Cache::store()
            ->tags([$this->tenantTag($tenantId)])
            ->flush();
    }

    private function tenantTag(int $tid): string
    {
        return "t:{$tid}";
    }

    private function resourceTag(int $tid, string $resource): string
    {
        return "t:{$tid}:{$resource}";
    }
}
