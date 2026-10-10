<?php

namespace App\Http\Middleware;

use App\Models\PersonalAccessToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $plainTextToken = $request->bearerToken();
        if (! is_string($plainTextToken) || strlen($plainTextToken) < 32 || strlen($plainTextToken) > 200) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $token = PersonalAccessToken::query()
            ->where('token_hash', hash('sha256', $plainTextToken))
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->first();

        if (! $token || ! $token->user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $token->forceFill(['last_used_at' => now()])->save();
        $request->setUserResolver(static fn () => $token->user);
        $request->attributes->set('api_token', $token);

        return $next($request);
    }
}
