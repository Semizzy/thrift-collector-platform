<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\ContributionCycle;
use App\Models\ContributionRecordAdjustment;
use App\Models\PlatformAuditEvent;
use App\Models\ThriftGroup;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminDashboardController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $recentAudit = PlatformAuditEvent::query()
            ->with(['actor:id,name', 'subject:id,name'])
            ->orderByDesc('created_at')->limit(10)->get()
            ->map(static fn (PlatformAuditEvent $event): array => [
                'id' => $event->id,
                'action' => $event->action,
                'actor_name' => $event->actor?->name,
                'subject_name' => $event->subject?->name,
                'created_at' => $event->created_at,
            ]);

        return response()->json([
            'data' => [
                'administrator' => $request->user()->only(['id', 'name', 'email']),
                'status' => 'authorized_read_only',
                'metrics' => [
                    'registered_members' => User::query()->count(),
                    'active_businesses' => Business::query()->where('status', 'active')->count(),
                    'active_thrift_groups' => ThriftGroup::query()->where('status', 'active')->count(),
                    'scheduled_cycles' => ContributionCycle::query()->where('status', 'scheduled')->count(),
                    'pending_adjustment_reviews' => ContributionRecordAdjustment::query()->where('status', 'pending')->count(),
                ],
                'recent_audit_events' => $recentAudit,
                'financial_execution_enabled' => false,
                'message' => 'Read-only operational counts. No financial execution, ledger posting, payout, or provider settlement actions are available from this endpoint.',
            ],
        ]);
    }
}
