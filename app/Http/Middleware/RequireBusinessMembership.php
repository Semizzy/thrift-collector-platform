<?php

namespace App\Http\Middleware;

use App\Models\Business;
use App\Models\BusinessMembership;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireBusinessMembership
{
    public function handle(Request $request, Closure $next): Response
    {
        $businessId = filter_var($request->header('X-Business-Id'), FILTER_VALIDATE_INT);
        if (! $request->user() || ! $businessId || $businessId < 1) {
            return response()->json(['message' => 'A valid X-Business-Id and authentication are required.'], 400);
        }

        $membership = BusinessMembership::query()
            ->where('business_id', $businessId)
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->where('status', 'active')
            ->first();

        if (! $membership) {
            return response()->json(['message' => 'Business not found or access denied.'], 404);
        }

        $business = Business::query()->find($businessId);
        if (! $business) {
            return response()->json(['message' => 'Business not found or access denied.'], 404);
        }

        $request->attributes->set('current_business', $business);
        $request->attributes->set('business_membership', $membership);

        return $next($request);
    }
}
