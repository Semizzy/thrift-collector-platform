<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BusinessAuditEvent;
use App\Models\ContributionRecord;
use App\Models\ContributionRecordAdjustment;
use App\Models\ThriftGroup;
use App\Services\WalletLedgerService;
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
        if ($record->status !== 'confirmed') {
            return response()->json(['message' => 'Only confirmed contributions can be adjusted.'], 409);
        }
        if ($data['adjustment_type'] === 'correction' && (int) $data['proposed_amount_minor'] === (int) $record->amount_minor) {
            return response()->json(['message' => 'The proposed correction must change the contribution amount.'], 422);
        }

        $adjustment = DB::transaction(function () use ($request, $business, $record, $data) {
            $lockedRecord = ContributionRecord::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();
            if ($lockedRecord->status === 'reversed') {
                return null;
            }
            $conflict = ContributionRecordAdjustment::query()->where('contribution_record_id', $lockedRecord->id)
                ->where(function ($query): void {
                    $query->where('status', 'pending')
                        ->orWhere(fn ($approved) => $approved->where('status', 'approved')->where('adjustment_type', 'reversal'));
                })->exists();
            if ($conflict) {
                return null;
            }
            $adjustment = ContributionRecordAdjustment::query()->create([
                'contribution_record_id' => $lockedRecord->id, 'thrift_group_id' => $lockedRecord->thrift_group_id,
                'adjustment_type' => $data['adjustment_type'], 'proposed_amount_minor' => $data['proposed_amount_minor'] ?? null,
                'reason' => trim($data['reason']), 'status' => 'pending', 'requested_by_user_id' => $request->user()->id,
            ]);
            BusinessAuditEvent::query()->create([
                'business_id' => $business->id, 'actor_user_id' => $request->user()->id,
                'action' => 'contribution_adjustment.requested', 'subject_type' => 'contribution_record_adjustment', 'subject_id' => $adjustment->id,
                'metadata' => ['record_id' => $lockedRecord->id, 'type' => $adjustment->adjustment_type, 'reason' => $adjustment->reason, 'proposed_amount_minor' => $adjustment->proposed_amount_minor, 'note' => 'Request only; original contribution record remains unchanged.'],
                'ip_address' => $request->ip(), 'user_agent' => Str::limit((string) $request->userAgent(), 500, ''), 'created_at' => now(),
            ]);
            return $adjustment;
        });

        if (! $adjustment) {
            return response()->json(['message' => 'This contribution is already reversed, or an unresolved adjustment or approved reversal already exists.'], 409);
        }
        return response()->json(['data' => $adjustment, 'message' => 'Adjustment request recorded for independent review. Original contribution data is unchanged.'], 201);
    }

    public function decide(Request $request, int $groupId, int $adjustmentId, WalletLedgerService $ledger): JsonResponse
    {
        $business = $request->attributes->get('current_business');
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'decision_note' => ['required', 'string', 'min:8', 'max:500'],
        ]);

        $result = DB::transaction(function () use ($request, $business, $groupId, $adjustmentId, $data, $ledger) {
            $adjustment = ContributionRecordAdjustment::query()->whereKey($adjustmentId)
                ->where('thrift_group_id', $groupId)
                ->whereHas('group', fn ($query) => $query->where('business_id', $business->id))
                ->lockForUpdate()->first();
            if (! $adjustment) return response()->json(['message' => 'Adjustment request not found.'], 404);
            $isActiveGroupMember = $adjustment->group->memberships()
                ->where('user_id', $request->user()->id)
                ->where('status', 'active')
                ->exists();
            if (! $isActiveGroupMember) {
                return response()->json(['message' => 'Active membership in this thrift group is required to decide an adjustment.'], 403);
            }
            if ($adjustment->requested_by_user_id === $request->user()->id) {
                return response()->json(['message' => 'The requester cannot approve or reject their own adjustment.'], 403);
            }
            if ($adjustment->status !== 'pending') {
                return response()->json(['message' => 'Only pending adjustment requests can be decided.'], 409);
            }

            $record = ContributionRecord::query()->whereKey($adjustment->contribution_record_id)
                ->lockForUpdate()->firstOrFail();

            $amountBefore = (int) $record->amount_minor;
            $posting = null;
            if ($data['decision'] === 'approved') {
                if ($record->status !== 'confirmed') {
                    return response()->json(['message' => 'Only a confirmed contribution can be adjusted.'], 409);
                }

                if ($adjustment->adjustment_type === 'reversal') {
                    $posting = $ledger->reverseContribution(
                        (int) $business->id,
                        (int) $record->user_id,
                        (string) $record->currency_code,
                        (int) $record->amount_minor,
                        (int) $record->id,
                        (int) $request->user()->id,
                        trim($data['decision_note'])
                    );
                    if ($posting['status'] === 'insufficient_balance') {
                        return response()->json([
                            'message' => 'This reversal cannot be approved because the member wallet no longer contains the full contribution amount. Reconcile the balance before retrying.',
                            'code' => 'contribution_reversal_insufficient_balance',
                        ], 409);
                    }
                    $record->forceFill(['status' => 'reversed'])->save();
                } elseif ($adjustment->adjustment_type === 'correction') {
                    $newAmount = (int) $adjustment->proposed_amount_minor;
                    $oldAmount = (int) $record->amount_minor;
                    if ($newAmount === $oldAmount) {
                        return response()->json(['message' => 'The proposed correction does not change the contribution amount.'], 422);
                    }
                    $posting = $ledger->correctContribution(
                        (int) $business->id,
                        (int) $record->user_id,
                        (string) $record->currency_code,
                        $oldAmount,
                        $newAmount,
                        (int) $record->id,
                        (int) $adjustment->id,
                        (int) $request->user()->id,
                        trim($data['decision_note'])
                    );
                    if ($posting['status'] === 'insufficient_balance') {
                        return response()->json([
                            'message' => 'This correction cannot be approved because the member wallet lacks the amount required for the correction. Reconcile the balance before retrying.',
                            'code' => 'contribution_correction_insufficient_balance',
                        ], 409);
                    }
                    $record->forceFill(['amount_minor' => $newAmount])->save();
                }
            }

            $adjustment->forceFill([
                'status' => $data['decision'], 'decided_by_user_id' => $request->user()->id,
                'decided_at' => now(), 'decision_note' => trim($data['decision_note']),
            ])->save();

            BusinessAuditEvent::query()->create([
                'business_id' => $business->id, 'actor_user_id' => $request->user()->id,
                'action' => 'contribution_adjustment.'.$data['decision'], 'subject_type' => 'contribution_record_adjustment', 'subject_id' => $adjustment->id,
                'metadata' => [
                    'record_id' => $adjustment->contribution_record_id,
                    'requester_user_id' => $adjustment->requested_by_user_id,
                    'type' => $adjustment->adjustment_type,
                    'reason' => $adjustment->reason,
                    'decision_note' => $adjustment->decision_note,
                    'ledger_transaction_id' => $data['decision'] === 'approved' ? ($posting['transaction_id'] ?? null) : null,
                    'record_status_after_decision' => $record->fresh()->status,
                    'amount_minor_before' => $amountBefore,
                    'amount_minor_after' => (int) $record->fresh()->amount_minor,
                    'note' => $data['decision'] === 'approved' && $adjustment->adjustment_type === 'reversal'
                        ? 'Approved reversal posted to the double-entry ledger; the contribution is marked reversed.'
                        : ($data['decision'] === 'approved' && $adjustment->adjustment_type === 'correction'
                            ? 'Approved amount correction posted as a balanced double-entry journal.'
                            : 'Decision recorded without financial posting.'),
                ],
                'ip_address' => $request->ip(), 'user_agent' => Str::limit((string) $request->userAgent(), 500, ''), 'created_at' => now(),
            ]);

            return response()->json([
                'data' => $adjustment->fresh(),
                'message' => $data['decision'] === 'approved' && $adjustment->adjustment_type === 'reversal'
                    ? 'Reversal approved and posted to the double-entry ledger.'
                    : ($data['decision'] === 'approved' && $adjustment->adjustment_type === 'correction'
                        ? 'Correction approved and posted to the double-entry ledger.'
                        : 'Decision recorded.'),
            ]);
        });

        return $result;
    }
}
