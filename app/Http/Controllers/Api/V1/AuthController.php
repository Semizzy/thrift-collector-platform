<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
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
        $user->sendEmailVerificationNotification();

        return $this->issueToken($user, 'registration', 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:254'],
            'password' => ['required', 'string', 'max:200'],
        ]);

        $result = DB::transaction(function () use ($data): array {
            $user = User::query()->where('email', Str::lower(trim($data['email'])))->lockForUpdate()->first();
            if (! $user) {
                return ['status' => 'invalid'];
            }

            if ($user->login_locked_until && $user->login_locked_until->isFuture()) {
                return ['status' => 'locked', 'until' => $user->login_locked_until->toIso8601String()];
            }
            if ($user->login_locked_until && ! $user->login_locked_until->isFuture()) {
                $user->forceFill(['failed_login_attempts' => 0, 'login_locked_until' => null])->save();
            }

            if (! Hash::check($data['password'], $user->password)) {
                $attempts = min(3, (int) $user->failed_login_attempts + 1);
                $attributes = ['failed_login_attempts' => $attempts];
                if ($attempts >= 3) {
                    $configuredHours = (int) (DB::table('platform_settings')->where('key', 'login_lockout_hours')->value('value') ?? 1);
                    $hours = max(1, min(720, $configuredHours));
                    $lockedUntil = now()->addHours($hours);
                    $attributes['login_locked_until'] = $lockedUntil;
                }
                $user->forceFill($attributes)->save();

                return $attempts >= 3
                    ? ['status' => 'locked', 'until' => $lockedUntil->toIso8601String()]
                    : ['status' => 'invalid'];
            }

            $user->forceFill(['failed_login_attempts' => 0, 'login_locked_until' => null])->save();
            return ['status' => 'ok', 'user' => $user];
        }, 3);

        if ($result['status'] === 'locked') {
            return response()->json([
                'message' => 'Account locked after three failed login attempts. Reset your password or wait until the lockout expires.',
                'locked_until' => $result['until'],
            ], 423);
        }
        if ($result['status'] !== 'ok') {
            throw ValidationException::withMessages(['email' => ['The supplied credentials are invalid.']]);
        }

        return $this->issueToken($result['user'], 'login', 200);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user()->only(['id', 'name', 'email', 'email_verified_at', 'created_at'])]);
    }

    public function verifyEmail(Request $request, string $id, string $hash): JsonResponse
    {
        $user = User::query()->findOrFail($id);
        if (! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            return response()->json(['message' => 'Invalid verification link.'], 403);
        }

        if (! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return response()->json(['message' => 'Email address verified.']);
    }

    public function resendVerification(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }

        return response()->json(['message' => 'If verification is still required, a new link has been sent.']);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:254']]);
        Password::sendResetLink(['email' => Str::lower(trim($data['email']))]);

        return response()->json(['message' => 'If an account matches that email, password reset instructions will be sent.']);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:254'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:12', 'max:200', 'confirmed'],
            'password_confirmation' => ['required', 'string', 'min:12', 'max:200'],
        ]);

        $status = Password::reset(
            ['email' => Str::lower(trim($data['email'])), 'token' => $data['token'], 'password' => $data['password'], 'password_confirmation' => $data['password_confirmation']],
            function (User $user, string $password): void {
                $user->forceFill(['password' => $password, 'failed_login_attempts' => 0, 'login_locked_until' => null])->save();
                $user->tokens()->delete();
                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json(['message' => 'The reset token is invalid or expired. Request a new password reset link.'], 422);
        }

        return response()->json(['message' => 'Password reset successfully. Please sign in again.']);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->attributes->get('api_token')?->delete();

        return response()->json(['message' => 'Signed out.']);
    }

    private function issueToken(User $user, string $name, int $status): JsonResponse
    {
        $plainTextToken = Str::random(64);
        $expiresAt = now()->addDays(30);
        PersonalAccessToken::query()->create([
            'user_id' => $user->id,
            'name' => $name,
            'token_hash' => hash('sha256', $plainTextToken),
            'expires_at' => $expiresAt,
        ]);

        return response()->json([
            'data' => $user->only(['id', 'name', 'email', 'email_verified_at', 'created_at']),
            'token_type' => 'Bearer',
            'access_token' => $plainTextToken,
            'expires_at' => $expiresAt->toIso8601String(),
        ], $status);
    }
}
