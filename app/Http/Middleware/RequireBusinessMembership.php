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

        $business = Business::query()->find($businessId);
        if (! $business) {
            return response()->json(['message' => 'Business not found or access denied.'], 404);
        }

        $membership = BusinessMembership::query()
            ->where('business_id', $businessId)
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->where('status', 'active')
            ->first();

        $isPlatformAdmin = $request->user()->platform_role === 'admin';
        if (! $membership && ! $isPlatformAdmin) {
            return response()->json(['message' => 'Business not found or access denied.'], 404);
        }

        // Platform admins may operate across business-scoped modules without being
        // artificially enrolled as ordinary business members. A non-persisted owner-like
        // context supports existing controller workflows that inspect the actor's role;
        // it is never saved as a real business membership.
        if (! $membership && $isPlatformAdmin) {
            $membership = (new BusinessMembership())->forceFill([
                'business_id' => $business->id,
                'user_id' => $request->user()->getAuthIdentifier(),
                'role' => 'business_owner',
                'status' => 'active',
                'joined_at' => now(),
                'is_platform_admin' => true,
            ]);
        }

        $request->attributes->set('current_business', $business);
        $request->attributes->set('business_membership', $membership);
        $request->attributes->set('platform_admin_business_access', $isPlatformAdmin);

        if ($isPlatformAdmin && ! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            \App\Models\BusinessAuditEvent::query()->create([
                'business_id' => $business->id,
                'actor_user_id' => $request->user()->id,
                'action' => 'platform_admin.business_operation_attempted',
                'subject_type' => 'business',
                'subject_id' => $business->id,
                'metadata' => [
                    'method' => $request->method(),
                    'path' => $request->path(),
                    'route_name' => $request->route()?->getName(),
                    'note' => 'Privileged platform administrator operation; outcome is recorded by the target workflow where applicable.',
                ],
                'ip_address' => $request->ip(),
                'user_agent' => \Illuminate\Support\Str::limit((string) $request->userAgent(), 500, ''),
                'created_at' => now(),
            ]);
        }

        return $next($request);
    }
}
