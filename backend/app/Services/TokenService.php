<?php

namespace App\Services;

use App\Data\TokenPairData;
use App\Exceptions\InvalidRefreshTokenException;
use App\Models\RefreshToken;
use App\Models\Tenant;
use App\Models\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;
use Throwable;

/**
 * Issues and rotates the auth token pair.
 *
 * Access tokens are stateless HS256 JWTs carrying the user (`sub`) and the
 * active tenant (`tid`). Refresh tokens are opaque random strings, stored only
 * as a SHA-256 hash, and rotated on every use. Reuse of an already-rotated
 * refresh token is treated as theft and revokes the entire token family.
 */
class TokenService
{
    public function issueTokenPair(User $user, Tenant $tenant, ?string $familyId = null): TokenPairData
    {
        $familyId ??= (string) Str::uuid();

        return new TokenPairData(
            access_token: $this->issueAccessToken($user, $tenant),
            refresh_token: $this->issueRefreshToken($user, $tenant, $familyId),
            token_type: 'Bearer',
            expires_in: config('jwt.access_ttl'),
        );
    }

    public function issueAccessToken(User $user, Tenant $tenant): string
    {
        $now = Carbon::now();

        $payload = [
            'iss' => config('app.url'),
            'sub' => (string) $user->id,
            'tid' => $tenant->id,
            'iat' => $now->timestamp,
            'exp' => $now->copy()->addSeconds(config('jwt.access_ttl'))->timestamp,
            'jti' => (string) Str::uuid(),
        ];

        return JWT::encode($payload, $this->secret(), config('jwt.algo'));
    }

    /**
     * Decode and verify an access token, returning its claims.
     *
     * @throws Throwable when the token is malformed, expired, or has a bad signature.
     */
    public function parseAccessToken(string $token): stdClass
    {
        return JWT::decode($token, new Key($this->secret(), config('jwt.algo')));
    }

    public function issueRefreshToken(User $user, Tenant $tenant, string $familyId): string
    {
        $plain = Str::random(64);

        RefreshToken::create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'token_hash' => $this->hash($plain),
            'family_id' => $familyId,
            'expires_at' => Carbon::now()->addSeconds(config('jwt.refresh_ttl')),
        ]);

        return $plain;
    }

    /**
     * Validate a refresh token, revoke it, and issue a fresh pair in the same family.
     *
     * @throws InvalidRefreshTokenException
     */
    public function rotateRefreshToken(string $plain): TokenPairData
    {
        $token = RefreshToken::where('token_hash', $this->hash($plain))->first();

        if ($token === null) {
            throw new InvalidRefreshTokenException;
        }

        // A token that was already rotated is being replayed: assume theft and
        // revoke every live token in the family. This runs outside the rotation
        // transaction so the revocation is committed even as we abort.
        if ($token->isRevoked()) {
            $this->revokeFamily($token->family_id);

            throw new InvalidRefreshTokenException('Refresh token reuse detected; session revoked.');
        }

        if ($token->isExpired()) {
            throw new InvalidRefreshTokenException;
        }

        return DB::transaction(function () use ($token) {
            $locked = RefreshToken::whereKey($token->id)->lockForUpdate()->firstOrFail();

            // A concurrent request already consumed this token.
            if ($locked->isRevoked()) {
                throw new InvalidRefreshTokenException;
            }

            $locked->forceFill(['revoked_at' => Carbon::now()])->save();

            $user = $locked->user;
            $tenant = $locked->tenant;

            if ($user === null || $tenant === null) {
                throw new InvalidRefreshTokenException;
            }

            return $this->issueTokenPair($user, $tenant, $locked->family_id);
        });
    }

    /**
     * Revoke a refresh token by its plaintext value (used on logout).
     */
    public function revoke(string $plain): void
    {
        RefreshToken::where('token_hash', $this->hash($plain))
            ->whereNull('revoked_at')
            ->update(['revoked_at' => Carbon::now()]);
    }

    public function revokeFamily(string $familyId): void
    {
        RefreshToken::where('family_id', $familyId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => Carbon::now()]);
    }

    protected function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    protected function secret(): string
    {
        return config('jwt.secret');
    }
}
