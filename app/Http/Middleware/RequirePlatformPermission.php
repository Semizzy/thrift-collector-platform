<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePlatformPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();
        // Platform administrators have full access across platform modules. The permission
        // list remains useful for future delegated operator roles, but must not turn the
        // primary admin account into a read-only or partially locked-out role.
        if (! $user || $user->platform_role !== 'admin') {
            return response()->json(['message' => 'Forbidden. Platform administrator access is required.'], 403);
        }

        return $next($request);
    }
}
