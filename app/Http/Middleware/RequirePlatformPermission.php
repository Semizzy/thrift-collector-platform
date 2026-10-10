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
        $permissions = $user?->platform_permissions ?? [];
        if (! $user || $user->platform_role !== 'admin' || ! is_array($permissions) || ! in_array($permission, $permissions, true)) {
            return response()->json(['message' => 'Forbidden. The required platform permission is missing.'], 403);
        }

        return $next($request);
    }
}
