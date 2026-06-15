<?php

namespace App\Models;

use App\Models\Attributes\NotTenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $tenant_id
 * @property string $token_hash
 * @property string $family_id
 * @property Carbon $expires_at
 * @property Carbon|null $revoked_at
 */
#[Fillable(['user_id', 'tenant_id', 'token_hash', 'family_id', 'expires_at', 'revoked_at'])]
#[NotTenantScoped('Refresh tokens are looked up before a tenant context exists (the lookup mints the access token that carries the tenant); TokenService stamps tenant_id explicitly.')]
class RefreshToken extends Model
{
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isUsable(): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired();
    }
}
