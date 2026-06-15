<?php

namespace Tests\Feature;

use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_with_valid_credentials_returns_token_pair(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create([
            'email' => 'ada@example.com',
            'password' => Hash::make('password123'),
        ]);
        $tenant->users()->attach($user, ['role' => TenantRole::Owner->value]);

        $this->postJson('/api/login', [
            'email' => 'ada@example.com',
            'password' => 'password123',
        ])->assertOk()->assertJsonStructure(['access_token', 'refresh_token', 'token_type', 'expires_in']);
    }

    public function test_login_with_bad_credentials_returns_401(): void
    {
        User::factory()->create([
            'email' => 'ada@example.com',
            'password' => Hash::make('password123'),
        ]);

        $this->postJson('/api/login', [
            'email' => 'ada@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(401);
    }
}
