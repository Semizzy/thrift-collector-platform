<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:254', 'unique:users,email'],
            'password' => ['required', 'string', 'min:12', 'max:200', 'confirmed'],
        ]);

        $user = User::query()->create([
            'name' => trim($data['name']),
            'email' => Str::lower(trim($data['email'])),
            'password' => $data['password'],
        ]);

        return $this->issueToken($user, 'registration', 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:254'],
            'password' => ['required', 'string', 'max:200'],
        ]);

        $user = User::query()->where('email', Str::lower(trim($data['email'])))->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => ['The supplied credentials are invalid.']]);
        }

        return $this->issueToken($user, 'login', 200);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user()->only(['id', 'name', 'email', 'created_at'])]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->attributes->get('api_token')?->delete();

        return response()->json(['message' => 'Signed out.']);
    }

    private function issueToken(User $user, string $name, int $status): JsonResponse
    {
        $plainTextToken = Str::random(64);
        PersonalAccessToken::query()->create([
            'user_id' => $user->id,
            'name' => $name,
            'token_hash' => hash('sha256', $plainTextToken),
            'expires_at' => now()->addDays(30),
        ]);

        return response()->json([
            'data' => $user->only(['id', 'name', 'email', 'created_at']),
            'token_type' => 'Bearer',
            'access_token' => $plainTextToken,
            'expires_at' => now()->addDays(30)->toIso8601String(),
        ], $status);
    }
}
