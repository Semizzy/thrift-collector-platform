<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BusinessAuditEvent;
use App\Models\ContributionRecord;
use App\Models\ReconciliationBatch;
use App\Models\ReconciliationEntry;
use App\Models\ThriftGroup;
use App\Services\WalletLedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ReconciliationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $business = $request->attributes->get('current_business');
        return response()->json(['data' => ReconciliationBatch::query()->where('business_id', $business->id)
            ->withCount('entries')->orderByDesc('created_at')->paginate(25)]);
    }

    public function store(Request $request): JsonResponse
    {
        $business = $request->attributes->get('current_business');
        $data = $request->validate([
            'source' => ['required', 'string', Rule::in(['bank_statement', 'provider_statement', 'manual_collection_export'])],
            'source_reference' => ['required', 'string', 'max:160'],
            'currency_code' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'entries' => ['required', 'array', 'min:1', 'max:2000'],
            'entries.*.source_transaction_id' => ['nullable', 'string', 'max:160'],
            'entries.*.reference' => ['nullable', 'string', 'max:160'],
            'entries.*.amount_minor' => ['required', 'integer', 'min:1', 'max:1000000000000'],
            'entries.*.currency_code' => ['sometimes', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'entries.*.occurred_at' => ['nullable', 'date'],
        ]);

        $existing = ReconciliationBatch::query()->where('business_id', $business->id)
            ->where('source', $data['source'])->where('source_reference', $data['source_reference'])->first();
        if ($existing) {
            return response()->json(['data' => $existing->loadCount('entries'), 'replayed' => true,
                'message' => 'This statement reference was already imported; no rows were added.'], 200);
        }

        $batch = DB::transaction(function () use ($request, $business, $data): ReconciliationBatch {
            $batch = ReconciliationBatch::query()->create([
                'business_id' => $business->id, 'source' => $data['source'],
                'source_reference' => $data['source_reference'], 'currency_code' => $data['currency_code'],
                'status' => 'review', 'created_by_user_id' => $request->user()->id,
            ]);
            foreach ($data['entries'] as $row) {
                $reference = trim((string) ($row['reference'] ?? ''));
                $sourceId = trim((string) ($row['source_transaction_id'] ?? ''));
                $currency = $row['currency_code'] ?? $data['currency_code'];
                $occurredAt = $row['occurred_at'] ?? null;
                $fingerprint = hash('sha256', implode('|', [$sourceId, strtoupper(preg_replace('/\s+/', '', $reference)), $row['amount_minor'], $currency, (string) $occurredAt]));
                $duplicate = ReconciliationEntry::query()->where('reconciliation_batch_id', $batch->id)->where('row_fingerprint', $fingerprint)->exists();
                $entry = ReconciliationEntry::query()->create([
                    'reconciliation_batch_id' => $batch->id,
                    'source_transaction_id' => $sourceId !== '' ? $sourceId : null,
                    'external_reference' => $reference !== '' ? $reference : null,
                    'normalized_reference' => $reference !== '' ? strtoupper(preg_replace('/\s+/', '', $reference)) : null,
                    'amount_minor' => $row['amount_minor'], 'currency_code' => $currency,
                    'occurred_at' => $occurredAt, 'status' => $duplicate ? 'duplicate' : 'unmatched',
                    'row_fingerprint' => $fingerprint, 'source_data' => $row,
                ]);
            }
            $batch->forceFill(['entry_count' => $batch->entries()->count()])->save();
            BusinessAuditEvent::query()->create([
                'business_id' => $business->id, 'actor_user_id' => $request->user()->id,
                'action' => 'reconciliation.batch_imported', 'subject_type' => 'reconciliation_batch', 'subject_id' => $batch->id,
                'metadata' => ['source' => $batch->source, 'source_reference' => $batch->source_reference, 'entry_count' => $batch->entry_count, 'note' => 'Import records evidence only; no settlement or ledger status is changed.'],
                'ip_address' => $request->ip(), 'user_agent' => Str::limit((string) $request->userAgent(), 500, ''), 'created_at' => now(),
            ]);
            return $batch;
        });

        return response()->json(['data' => $batch->load('entries'), 'replayed' => false], 201);
    }

    public function match(Request $request, int $entryId, WalletLedgerService $ledger): JsonResponse
    {
        $business = $request->attributes->get('current_business');
        $data = $request->validate([
            'decision' => ['required', Rule::in(['match', 'unmatched', 'partial', 'conflict', 'ignore'])],
            'contribution_record_id' => ['nullable', 'integer'],
            'reason' => ['required', 'string', 'min:8', 'max:500'],
        ]);

        return DB::transaction(function () use ($request, $business, $entryId, $data, $ledger): JsonResponse {
            $entry = ReconciliationEntry::query()->whereKey($entryId)
                ->whereHas('batch', fn ($query) => $query->where('business_id', $business->id))
                ->lockForUpdate()->firstOrFail();
            if ($entry->status === 'matched') {
                return response()->json(['message' => 'A matched entry cannot be changed in place; submit a separately reviewed correction workflow.'], 409);
            }
            $contribution = null;
            if ($data['decision'] === 'match') {
                if (empty($data['contribution_record_id'])) {
                    return response()->json(['message' => 'A contribution_record_id is required for a match decision.'], 422);
                }
                $contribution = ContributionRecord::query()->whereKey($data['contribution_record_id'])
                    ->where('currency_code', $entry->currency_code)
                    ->where('amount_minor', $entry->amount_minor)
                    ->whereHas('group', fn ($query) => $query->where('business_id', $business->id))
                    ->where('status', 'confirmed')
                    ->lockForUpdate()->first();
                if (! $contribution) {
                    return response()->json(['message' => 'No confirmed contribution in this business has the exact amount and currency. Amount mismatches require human investigation; no automatic match was made.'], 422);
                }
                if ($entry->normalized_reference) {
                    if (! $contribution->reference) {
                        return response()->json(['message' => 'The selected contribution has no reference to verify against the statement entry. Review the exception instead of matching automatically.'], 409);
                    }
                    $normalized = strtoupper(preg_replace('/\\s+/', '', trim($contribution->reference)));
                    if (! hash_equals($entry->normalized_reference, $normalized)) {
                        return response()->json(['message' => 'Reference conflict: the external and contribution references differ.'], 409);
                    }
                }
                if ($entry->occurred_at && $contribution->created_at
                    && $entry->occurred_at->diffInDays($contribution->created_at) > 7) {
                    return response()->json(['message' => 'Date conflict: the contribution and statement dates differ by more than seven days. Review the exception instead of matching.'], 409);
                }
                $alreadyMatched = ReconciliationEntry::query()->where('matched_contribution_record_id', $contribution->id)
                    ->where('status', 'matched')->exists();
                if ($alreadyMatched) {
                    return response()->json(['message' => 'This contribution is already matched to another statement entry. Review as duplicate/conflict.'], 409);
                }
                $entry->forceFill(['status' => 'matched', 'matched_contribution_record_id' => $contribution->id])->save();
                $walletPosting = $ledger->creditContribution(
                    (int) $business->id, (int) $contribution->user_id, $contribution->currency_code,
                    (int) $contribution->amount_minor, (int) $contribution->id, (int) $request->user()->id
                );
            } else {
                $entry->forceFill(['status' => $data['decision'], 'matched_contribution_record_id' => null])->save();
            }

            $walletPosting ??= ['status' => 'not_posted', 'reason' => 'No contribution was matched.'];
            DB::table('reconciliation_decisions')->insert([
                'business_id' => $business->id, 'reconciliation_entry_id' => $entry->id,
                'actor_user_id' => $request->user()->id, 'decision' => $data['decision'],
                'reason' => trim($data['reason']), 'evidence' => json_encode(['contribution_record_id' => $contribution?->id, 'amount_minor' => $entry->amount_minor, 'currency_code' => $entry->currency_code, 'wallet_posting' => $walletPosting]),
                'created_at' => now(),
            ]);
            BusinessAuditEvent::query()->create([
                'business_id' => $business->id, 'actor_user_id' => $request->user()->id,
                'action' => 'reconciliation.entry_decided', 'subject_type' => 'reconciliation_entry', 'subject_id' => $entry->id,
                'metadata' => ['decision' => $data['decision'], 'reason' => trim($data['reason']), 'contribution_record_id' => $contribution?->id, 'wallet_posting' => $walletPosting, 'note' => 'Only an authorized exact-match decision posts the savings wallet credit.'],
                'ip_address' => $request->ip(), 'user_agent' => Str::limit((string) $request->userAgent(), 500, ''), 'created_at' => now(),
            ]);
            return response()->json(['data' => $entry->fresh(), 'wallet_posting' => $walletPosting, 'message' => $data['decision'] === 'match' ? 'Reconciliation matched and wallet credit was posted idempotently.' : 'Reconciliation decision audited; no wallet credit was posted.']);
        });
    }
}
