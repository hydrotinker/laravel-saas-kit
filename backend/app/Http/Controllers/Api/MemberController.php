<?php

namespace App\Http\Controllers\Api;

use App\Data\AddMemberData;
use App\Data\MemberData;
use App\Data\UpdateMemberRoleData;
use App\Enums\TenantRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Cache\TenantCache;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\DataCollection;

class MemberController extends Controller
{
    public function __construct(
        protected TenantContext $context,
        protected TenantCache $cache,
    ) {}

    public function index(): JsonResponse
    {
        $tenant = $this->context->getOrFail();
        $this->authorize('viewAny', $tenant);

        $payload = $this->cache->remember('members', 'index', fn () => MemberData::collect(
            $tenant->users()->get(),
            DataCollection::class,
        )->toArray());

        return response()->json($payload);
    }

    public function store(AddMemberData $data): JsonResponse
    {
        $tenant = $this->context->getOrFail();
        $this->authorize('manage', $tenant);

        $user = User::where('email', $data->email)->first();

        if ($user === null) {
            throw ValidationException::withMessages([
                'email' => ['No user exists with that email address.'],
            ]);
        }

        if ($tenant->users()->whereKey($user->id)->exists()) {
            throw ValidationException::withMessages([
                'email' => ['This user is already a member of the organization.'],
            ]);
        }

        $tenant->users()->attach($user, ['role' => $data->role->value]);

        // Membership lives on the tenant_user pivot, which fires no model events,
        // so an observer can't see this write — invalidate the members list here.
        $this->cache->flush($tenant->id, 'members');

        $member = $tenant->users()->whereKey($user->id)->firstOrFail();

        return response()->json(MemberData::fromModel($member), 201);
    }

    public function updateRole(UpdateMemberRoleData $data, int $member): MemberData
    {
        $tenant = $this->context->getOrFail();
        $this->authorize('manage', $tenant);

        $this->findMember($member);
        $this->guardLastOwner($member, $data->role);

        $tenant->users()->updateExistingPivot($member, ['role' => $data->role->value]);

        // Pivot write — invalidate explicitly (no model event fires).
        $this->cache->flush($tenant->id, 'members');

        return MemberData::fromModel($tenant->users()->whereKey($member)->firstOrFail());
    }

    public function destroy(int $member): JsonResponse
    {
        $tenant = $this->context->getOrFail();
        $this->authorize('manage', $tenant);

        $this->findMember($member);
        $this->guardLastOwner($member, null);

        $tenant->users()->detach($member);

        // Pivot write — invalidate explicitly (no model event fires).
        $this->cache->flush($tenant->id, 'members');

        return response()->json(status: 204);
    }

    /**
     * Resolve a member within the current tenant, or 404. Keeps cross-tenant
     * member ids indistinguishable from non-existent ones (no 403 leak).
     */
    protected function findMember(int $member): User
    {
        return $this->context->getOrFail()
            ->users()
            ->whereKey($member)
            ->firstOrFail();
    }

    /**
     * Prevent demoting or removing the organization's last owner.
     */
    protected function guardLastOwner(int $member, ?TenantRole $newRole): void
    {
        $tenant = $this->context->getOrFail();

        $isOwner = $tenant->users()
            ->whereKey($member)
            ->wherePivot('role', TenantRole::Owner->value)
            ->exists();

        if (! $isOwner || $newRole === TenantRole::Owner) {
            return;
        }

        $ownerCount = $tenant->users()
            ->wherePivot('role', TenantRole::Owner->value)
            ->count();

        if ($ownerCount <= 1) {
            throw ValidationException::withMessages([
                'member' => ['The organization must have at least one owner.'],
            ]);
        }
    }
}
