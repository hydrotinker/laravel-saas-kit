<?php

namespace App\Http\Middleware;

use App\Auth\JwtGuard;
use App\Models\Tenant;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pins the active tenant for the request from the verified JWT `tid` claim.
 *
 * Must run after `auth:api`. Aborts with 403 if the token carries no tenant or
 * the authenticated user is not a member of it.
 */
class ResolveTenant
{
    public function __construct(protected TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var JwtGuard $guard */
        $guard = Auth::guard('api');

        $user = $guard->user();
        $tenantId = $guard->tokenTenantId();

        if ($user === null || $tenantId === null) {
            abort(403, 'No tenant context in token.');
        }

        if (! $user->belongsToTenant($tenantId)) {
            abort(403, 'You are not a member of this organization.');
        }

        $this->context->set(Tenant::findOrFail($tenantId));

        return $next($request);
    }
}
