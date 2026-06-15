<?php

namespace Tests\Feature;

use App\Models\RefreshToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RefreshRotationTest extends TestCase
{
    use RefreshDatabase;

    private function register(): array
    {
        return $this->postJson('/api/register', [
            'organization_name' => 'Acme Inc',
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'password' => 'password123',
        ])->json();
    }

    public function test_refresh_returns_a_new_pair_and_revokes_the_old_token(): void
    {
        $old = $this->register()['refresh_token'];

        $new = $this->postJson('/api/refresh', ['refresh_token' => $old])
            ->assertOk()
            ->assertJsonStructure(['access_token', 'refresh_token'])
            ->json('refresh_token');

        $this->assertNotSame($old, $new);

        // The freshly issued token works.
        $this->postJson('/api/refresh', ['refresh_token' => $new])->assertOk();
    }

    public function test_reusing_a_rotated_token_is_rejected_and_revokes_the_family(): void
    {
        $old = $this->register()['refresh_token'];

        $new = $this->postJson('/api/refresh', ['refresh_token' => $old])->json('refresh_token');

        // Replaying the already-rotated token is treated as theft.
        $this->postJson('/api/refresh', ['refresh_token' => $old])->assertStatus(401);

        // The whole family (including the legitimate new token) is now revoked.
        $this->assertSame(0, RefreshToken::whereNull('revoked_at')->count());
        $this->postJson('/api/refresh', ['refresh_token' => $new])->assertStatus(401);
    }

    public function test_unknown_refresh_token_is_rejected(): void
    {
        $this->postJson('/api/refresh', ['refresh_token' => 'not-a-real-token'])->assertStatus(401);
    }
}
