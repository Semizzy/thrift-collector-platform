<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BusinessAuditEvent;
use App\Models\ContributionRecord;
use App\Models\ContributionRecordAdjustment;
use App\Models\ThriftGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ContributionAdjustmentController extends Controller
{
    public function index(Request $request, int $groupId): JsonResponse
    {
        $business = $request->attributes->get('current_business');
        $group = ThriftGroup::query()->where('business_id', $business->id)->whereKey($groupId)
            ->whereHas('memberships', fn ($query) => $query->where('user_id', $request->user()->id)->where('status', 'active'))
            ->firstOrFail();

        return response()->json(['data' => ContributionRecordAdjustment::query()->where('thrift_group_id', $group->id)
            ->with(['contribution:id,amount_minor,currency_code,status', 'requester:id,name', 'decider:id,name'])
            ->orderByDesc('created_at')->paginate(50)]);
    }

    public function request(Request $request, int $groupId, int $recordId): JsonResponse
    {
        $business = $request->attributes->get('current_business');
        $record = ContributionRecord::query()->whereKey($recordId)->where('thrift_group_id', $groupId)
            ->whereHas('group', fn ($query) => $query->where('business_id', $business->id)
                ->whereHas('memberships', fn ($members) => $members->where('user_id', $request->user()->id)->where('status', 'active')))
            ->firstOrFail();

        $data = $request->validate([
            'adjustment_type' => ['required', Rule::in(['correction', 'reversal'])],
            'proposed_amount_minor' => ['nullable', 'integer', 'min:1', 'max:1000000000000'],
            'reason' => ['required', 'string', 'min:8', 'max:500'],
        ]);
        if ($data['adjustment_type'] === 'correction' && ! isset($data['proposed_amount_minor'])) {
            return response()->json(['message' => 'A correction must include a proposed amount in minor units.'], 422);
        }
        if ($data['adjustment_type'] === 'reversal' && isset($data['proposed_amount_minor'])) {
            return response()->json(['message' => 'A reversal request cannot set a replacement amount.'], 422);
        }
        if ($record->status === 'reversed') return response()->json(['message' => 'This contribution record is already reversed.'], 409);

        $adjustment = DB::transaction(function () use ($request, $business, $record, $data): ContributionRecordAdjustment {
            $adjustment = ContributionRecordAdjustment::query()->create([
                'contribution_record_id' => $record->id, 'thrift_group_id' => $record->thrift_group_id,
                'adjustment_type' => $data['adjustment_type'], 'proposed_amount_minor' => $data['proposed_amount_minor'] ?? null,
                'reason' => trim($data['reason']), 'status' => 'pending', 'requested_by_user_id' => $request->user()->id,
            ]);
            BusinessAuditEvent::query()->create([
                'business_id' => $business->id, 'actor_user_id' => $request->user()->id,
                'action' => 'contribution_adjustment.requested', 'subject_type' => 'contribution_record_adjustment', 'subject_id' => $adjustment->id,
                'metadata' => ['record_id' => $record->id, 'type' => $adjustment->adjustment_type, 'reason' => $adjustment->reason, 'proposed_amount_minor' => $adjustment->proposed_amount_minor, 'note' => 'Request only; original contribution record remains unchanged.'],
                'ip_address' => $request->ip(), 'user_agent' => Str::limit((string) $request->userAgent(), 500, ''), 'created_at' => now(),
            ]);
            return $adjustment;
        });

        return response()->json(['data' => $adjustment, 'message' => 'Adjustment request recorded for independent review. Original contribution data is unchanged.'], 201);
    }

    public function decide(Request $request, int $groupId, int $adjustmentId): JsonResponse
    {
        $business = $request->attributes->get('current_business');
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'decision_note' => ['required', 'string', 'min:8', 'max:500'],
        ]);

        $result = DB::transaction(function () use ($request, $business, $groupId, $adjustmentId, $data) {
            $adjustment = ContributionRecordAdjustment::query()->whereKey($adjustmentId)
                ->where('thrift_group_id', $groupId)
                ->whereHas('group', fn ($query) => $query->where('business_id', $business->id))
                ->lockForUpdate()->first();
            if (! $adjustment) return response()->json(['message' => 'Adjustment request not found.'], 404);
            if ($adjustment->requested_by_user_id === $request->user()->id) {
                return response()->json(['message' => 'The requester cannot approve or reject their own adjustment.'], 403);
            }
            if ($adjustment->status !== 'pending') {
                return response()->json(['message' => 'Only pending adjustment requests can be decided.'], 409);
            }

            $adjustment->forceFill([
                'status' => $data['decision'], 'decided_by_user_id' => $request->user()->id,
                'decided_at' => now(), 'decision_note' => trim($data['decision_note']),
            ])->save();

            BusinessAuditEvent::query()->create([
                'business_id' => $business->id, 'actor_user_id' => $request->user()->id,
                'action' => 'contribution_adjustment.'.$data['decision'], 'subject_type' => 'contribution_record_adjustment', 'subject_id' => $adjustment->id,
                'metadata' => ['record_id' => $adjustment->contribution_record_id, 'requester_user_id' => $adjustment->requested_by_user_id, 'type' => $adjustment->adjustment_type, 'reason' => $adjustment->reason, 'decision_note' => $adjustment->decision_note, 'note' => 'Approval records a decision only; it does not mutate the original record or post a ledger entry.'],
                'ip_address' => $request->ip(), 'user_agent' => Str::limit((string) $request->userAgent(), 500, ''), 'created_at' => now(),
            ]);

            return response()->json(['data' => $adjustment, 'message' => 'Decision recorded. The original record remains unchanged; no ledger or payment action was performed.']);
        });

        return $result;
    }
}
