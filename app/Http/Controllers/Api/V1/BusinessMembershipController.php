<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BusinessMembershipController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $business = $request->attributes->get('current_business');
        $members = $business->memberships()
            ->with('user:id,name,email,email_verified_at')
            ->get()
            ->map(static fn ($membership): array => [
                'id' => $membership->id,
                'user' => $membership->user,
                'role' => $membership->role,
                'status' => $membership->status,
                'joined_at' => $membership->joined_at,
            ]);

        return response()->json(['data' => $members]);
    }
}
