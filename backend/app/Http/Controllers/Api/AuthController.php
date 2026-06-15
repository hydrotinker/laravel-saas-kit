<?php

namespace App\Http\Controllers\Api;

use App\Data\LoginData;
use App\Data\RegisterData;
use App\Data\UserData;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\RegistrationService;
use App\Services\TokenService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function __construct(
        protected TokenService $tokens,
        protected RegistrationService $registration,
    ) {}

    public function register(RegisterData $data): JsonResponse
    {
        ['tenant' => $tenant, 'user' => $user] = $this->registration->register(
            $data->organization_name,
            $data->name,
            $data->email,
            $data->password,
        );

        return response()->json(
            $this->tokens->issueTokenPair($user, $tenant),
            201,
        );
    }

    public function login(LoginData $data): JsonResponse
    {
        $user = User::where('email', $data->email)->first();

        if ($user === null || ! Hash::check($data->password, $user->password)) {
            return response()->json(['message' => 'Invalid credentials.'], 401);
        }

        $tenant = $user->tenants()->first();

        if ($tenant === null) {
            return response()->json(['message' => 'User has no organization.'], 403);
        }

        return response()->json($this->tokens->issueTokenPair($user, $tenant));
    }

    public function refresh(Request $request): JsonResponse
    {
        $validated = $request->validate(['refresh_token' => ['required', 'string']]);

        return response()->json(
            $this->tokens->rotateRefreshToken($validated['refresh_token']),
        );
    }

    public function logout(Request $request): JsonResponse
    {
        $validated = $request->validate(['refresh_token' => ['required', 'string']]);

        $this->tokens->revoke($validated['refresh_token']);

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request, TenantContext $context): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();
        $tenant = $context->getOrFail();

        return response()->json([
            'user' => UserData::fromModel($user),
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'role' => $user->roleIn($tenant->id),
            ],
        ]);
    }
}
