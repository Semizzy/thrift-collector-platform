<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BusinessAuditEvent;
use App\Models\ContributionRecord;
use App\Models\ThriftGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ContributionRecordController extends Controller
{
    public function index(Request $request, int $groupId): JsonResponse
    {
        $business = $request->attributes->get('current_business');
        $group = ThriftGroup::query()->where('business_id', $business->id)
            ->whereKey($groupId)
            ->whereHas('memberships', fn ($query) => $query->where('user_id', $request->user()->id)->where('status', 'active'))
            ->firstOrFail();

        $records = $group->contributions()->with('contributor:id,name')
            ->orderByDesc('created_at')->paginate(25, [
                'id', 'thrift_group_id', 'user_id', 'recorded_by_user_id', 'amount_minor', 'currency_code',
                'status', 'collection_method', 'reference', 'confirmed_by_user_id', 'confirmed_at', 'created_at',
            ]);

        return response()->json(['data' => $records]);
    }

    public function store(Request $request, int $groupId): JsonResponse
    {
        $business = $request->attributes->get('current_business');
        $group = ThriftGroup::query()->where('business_id', $business->id)->whereKey($groupId)
            ->where('status', 'active')
            ->whereHas('memberships', fn ($query) => $query->where('user_id', $request->user()->id)->where('status', 'active'))
            ->firstOrFail();

        $data = $request->validate([
            'user_id' => ['required', 'integer', Rule::exists('thrift_group_memberships', 'user_id')->where(fn ($query) => $query->where('thrift_group_id', $group->id)->where('status', 'active'))],
            'amount_minor' => ['required', 'integer', 'min:1', 'max:1000000000000'],
            'collection_method' => ['sometimes', 'string', Rule::in(['cash', 'bank_transfer', 'mobile_money', 'unspecified'])],
            'reference' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $record = DB::transaction(function () use ($request, $business, $group, $data): ContributionRecord {
            $record = ContributionRecord::query()->create([
                'thrift_group_id' => $group->id, 'user_id' => $data['user_id'],
                'recorded_by_user_id' => $request->user()->id, 'amount_minor' => $data['amount_minor'],
                'currency_code' => $group->currency_code, 'status' => 'recorded',
                'collection_method' => $data['collection_method'] ?? 'unspecified',
                'reference' => $data['reference'] ?? null, 'note' => $data['note'] ?? null,
            ]);
            BusinessAuditEvent::query()->create([
                'business_id' => $business->id, 'actor_user_id' => $request->user()->id,
                'action' => 'contribution.recorded', 'subject_type' => 'contribution_record', 'subject_id' => $record->id,
                'metadata' => ['group_id' => $group->id, 'contributor_user_id' => $record->user_id, 'amount_minor' => $record->amount_minor, 'currency_code' => $record->currency_code, 'status' => $record->status],
                'ip_address' => $request->ip(), 'user_agent' => Str::limit((string) $request->userAgent(), 500, ''), 'created_at' => now(),
            ]);
            return $record;
        });

        return response()->json(['data' => $record, 'message' => 'Contribution recorded; this is not proof of deposit or settlement.'], 201);
    }

    public function confirm(Request $request, int $groupId, int $recordId): JsonResponse
    {
        $business = $request->attributes->get('current_business');
        $result = DB::transaction(function () use ($request, $business, $groupId, $recordId) {
            $record = ContributionRecord::query()->where('thrift_group_id', $groupId)
                ->whereHas('group', fn ($query) => $query->where('business_id', $business->id))
                ->whereKey($recordId)->lockForUpdate()->first();
            if (! $record) return response()->json(['message' => 'Contribution record not found.'], 404);
            if ($record->recorded_by_user_id === $request->user()->id) {
                return response()->json(['message' => 'The recorder cannot confirm their own contribution record.'], 403);
            }
            if ($record->status !== 'recorded') {
                return response()->json(['message' => 'Only recorded contributions can be confirmed. Corrections require a separate reversal workflow.'], 409);
            }
            $record->forceFill(['status' => 'confirmed', 'confirmed_by_user_id' => $request->user()->id, 'confirmed_at' => now()])->save();
            BusinessAuditEvent::query()->create([
                'business_id' => $business->id, 'actor_user_id' => $request->user()->id,
                'action' => 'contribution.confirmed', 'subject_type' => 'contribution_record', 'subject_id' => $record->id,
                'metadata' => ['group_id' => $groupId, 'amount_minor' => $record->amount_minor, 'currency_code' => $record->currency_code, 'note' => 'Record confirmation does not prove bank/provider settlement.'],
                'ip_address' => $request->ip(), 'user_agent' => Str::limit((string) $request->userAgent(), 500, ''), 'created_at' => now(),
            ]);
            return response()->json(['data' => $record, 'message' => 'Contribution record confirmed. This does not confirm bank/provider settlement.']);
        });
        return $result;
    }
}
