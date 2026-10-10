<?php

namespace App\Http\Middleware;

use App\Domain\Tenancy\BusinessPermission;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireBusinessPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $membership = $request->attributes->get('business_membership');

        if (! $membership || ! BusinessPermission::allows((string) $membership->role, $permission)) {
            return response()->json(['message' => 'You do not have permission to perform this action.'], 403);
        }

        return $next($request);
    }
}
