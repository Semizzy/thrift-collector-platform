<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BusinessMembership;
use App\Models\ContributionRecord;
use App\Models\ContributionCycleObligation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        $memberships = BusinessMembership::query()
            ->where('user_id', $user->id)->where('status', 'active')
            ->with('business:id,name,country_code,status')->get();

        $groupMemberships = \App\Models\ThriftGroupMembership::query()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->with(['group:id,business_id,name,description,currency_code,status,created_at'])
            ->whereHas('group', fn ($query) => $query->where('status', 'active'))
            ->get();
        $groupIds = $groupMemberships->pluck('thrift_group_id');
        $recentContributions = ContributionRecord::query()->whereIn('thrift_group_id', $groupIds)
            ->where('user_id', $user->id)->with('group:id,name,currency_code')
            ->orderByDesc('created_at')->limit(10)->get([
                'id', 'thrift_group_id', 'user_id', 'amount_minor', 'currency_code',
                'status', 'collection_method', 'reference', 'created_at', 'confirmed_at',
            ]);
        $confirmedTotals = ContributionRecord::query()->whereIn('thrift_group_id', $groupIds)
            ->where('user_id', $user->id)->where('status', 'confirmed')
            ->select('currency_code', DB::raw('SUM(amount_minor) as total_minor'), DB::raw('COUNT(*) as records_count'))
            ->groupBy('currency_code')->get()->map(static fn ($row): array => [
                'currency_code' => $row->currency_code,
                'total_minor' => (int) $row->total_minor,
                'records_count' => (int) $row->records_count,
            ])->values();

        $upcomingObligationsQuery = ContributionCycleObligation::query()
            ->where('user_id', $user->id)->where('status', 'due')
            ->whereHas('cycle', fn ($query) => $query->where('status', 'scheduled')
                ->whereDate('due_on', '>=', now()->toDateString())
                ->whereHas('group', fn ($groupQuery) => $groupQuery->where('status', 'active')
                    ->whereHas('memberships', fn ($membershipQuery) => $membershipQuery
                        ->where('user_id', $user->id)->where('status', 'active'))));
        $upcomingObligationsCount = (clone $upcomingObligationsQuery)->count();
        $upcomingObligations = $upcomingObligationsQuery->with([
            'cycle:id,thrift_group_id,due_on,expected_amount_minor,currency_code,status',
            'cycle.group:id,name,currency_code',
        ])->orderBy('id')->limit(10)->get([
            'id', 'contribution_cycle_id', 'user_id', 'expected_amount_minor', 'currency_code', 'status',
        ])->map(static fn ($obligation): array => [
            'id' => $obligation->id,
            'status' => $obligation->status,
            'expected_amount_minor' => $obligation->expected_amount_minor,
            'currency_code' => $obligation->currency_code,
            'due_on' => $obligation->cycle?->due_on?->toDateString(),
            'group' => $obligation->cycle?->group ? [
                'id' => $obligation->cycle->group->id,
                'name' => $obligation->cycle->group->name,
            ] : null,
        ])->values();

        return response()->json(['data' => [
            'user' => $user->only(['id', 'name', 'email', 'email_verified_at', 'created_at']),
            'active_workspaces_count' => $memberships->count(),
            'workspaces' => $memberships->map(static fn (BusinessMembership $membership): array => [
                'id' => $membership->business?->id, 'name' => $membership->business?->name,
                'country_code' => $membership->business?->country_code, 'status' => $membership->business?->status,
                'role' => $membership->role,
            ])->values(),
            'active_groups_count' => $groupMemberships->count(),
            'groups' => $groupMemberships->map(static fn ($membership): array => [
                'id' => $membership->group?->id, 'business_id' => $membership->group?->business_id,
                'name' => $membership->group?->name, 'description' => $membership->group?->description,
                'currency_code' => $membership->group?->currency_code, 'status' => $membership->group?->status,
                'role' => $membership->role, 'joined_at' => $membership->joined_at,
            ])->values(),
            'confirmed_contribution_totals' => $confirmedTotals,
            'recent_contributions' => $recentContributions,
            'upcoming_obligations_count' => $upcomingObligationsCount,
            'upcoming_obligations' => $upcomingObligations,
            'financial_data_status' => 'recorded_contributions_only',
            'message' => 'Confirmed contribution records are not proof of bank deposit, payment-provider settlement, or payout.',
        ]]);
    }
}
