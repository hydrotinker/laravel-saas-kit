<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\TenantRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * @property-read TenantUser $pivot
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /** @return BelongsToMany<Tenant, $this, TenantUser> */
    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class)
            ->using(TenantUser::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    /** @return HasMany<RefreshToken, $this> */
    public function refreshTokens(): HasMany
    {
        return $this->hasMany(RefreshToken::class);
    }

    /**
     * Determine whether the user belongs to the given tenant.
     */
    public function belongsToTenant(int $tenantId): bool
    {
        return $this->tenants()->whereKey($tenantId)->exists();
    }

    /**
     * Resolve the user's role within the given tenant, if any.
     */
    public function roleIn(int $tenantId): ?TenantRole
    {
        $tenant = $this->tenants()->whereKey($tenantId)->first();

        return $tenant !== null
            ? TenantRole::from($tenant->pivot->role)
            : null;
    }
}
