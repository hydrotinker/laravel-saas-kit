<?php

namespace App\Auth;

use App\Services\TokenService;
use Illuminate\Auth\GuardHelpers;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Http\Request;
use Throwable;

/**
 * Stateless guard backed by HS256 access tokens.
 *
 * Reads the bearer token, verifies it via the TokenService, and resolves the
 * user from the `sub` claim. The verified `tid` (tenant) claim is exposed so the
 * ResolveTenant middleware can pin the active tenant for the request.
 */
class JwtGuard implements Guard
{
    use GuardHelpers;

    protected ?int $tenantId = null;

    protected bool $resolved = false;

    public function __construct(
        UserProvider $provider,
        protected Request $request,
        protected TokenService $tokens,
    ) {
        $this->provider = $provider;
    }

    /**
     * Bind the current request and reset the resolution cache.
     *
     * The framework re-invokes this whenever the `request` instance is rebound
     * (see AuthServiceProvider), so a single cached guard never leaks a user
     * across requests.
     */
    public function setRequest(Request $request): static
    {
        $this->request = $request;
        $this->resolved = false;
        $this->user = null;
        $this->tenantId = null;

        return $this;
    }

    public function user()
    {
        if ($this->resolved) {
            return $this->user;
        }

        $this->resolved = true;

        $token = $this->request->bearerToken();

        if ($token === null) {
            return null;
        }

        try {
            $claims = $this->tokens->parseAccessToken($token);
        } catch (Throwable) {
            return null;
        }

        $user = $this->provider->retrieveById($claims->sub ?? null);

        if ($user !== null) {
            $this->user = $user;
            $this->tenantId = isset($claims->tid) ? (int) $claims->tid : null;
        }

        return $this->user;
    }

    /**
     * The tenant id carried in the verified access token, if a user is authenticated.
     */
    public function tokenTenantId(): ?int
    {
        $this->user();

        return $this->tenantId;
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function validate(array $credentials = []): bool
    {
        return $this->user() !== null;
    }
}
