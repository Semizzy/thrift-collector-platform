<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BusinessMembership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        $memberships = BusinessMembership::query()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->with('business:id,name,country_code,status')
            ->get();

        return response()->json([
            'data' => [
                'user' => $user->only(['id', 'name', 'email', 'email_verified_at', 'created_at']),
                'active_workspaces_count' => $memberships->count(),
                'workspaces' => $memberships->map(static fn (BusinessMembership $membership): array => [
                    'id' => $membership->business?->id,
                    'name' => $membership->business?->name,
                    'country_code' => $membership->business?->country_code,
                    'status' => $membership->business?->status,
                    'role' => $membership->role,
                ])->values(),
                'financial_data_status' => 'not_available',
                'message' => 'Contribution totals, schedules and transactions will appear when their verified domain records are implemented.',
            ],
        ]);
    }
}
