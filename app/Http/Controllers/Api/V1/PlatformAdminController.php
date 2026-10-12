<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PlatformAuditEvent;
use App\Models\Business;
use App\Models\ContributionRecord;
use App\Models\ContributionRecordAdjustment;
use App\Models\DepositIntent;
use App\Models\PayoutRequest;
use App\Models\ReconciliationBatch;
use App\Models\ReconciliationEntry;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PlatformAdminController extends Controller
{
    private const GRANTABLE_PERMISSIONS = ['platform.admins.manage', 'platform.audit.view', 'platform.users.view', 'platform.security.manage', 'platform.businesses.view', 'platform.collections.view', 'platform.reconciliation.view', 'platform.approvals.view'];

    public function provision(Request $request, int $userId): JsonResponse
    {
        $data = $request->validate([
            'platform_role' => ['required', Rule::in(['member', 'admin'])],
            'permissions' => ['sometimes', 'array', 'max:10'],
            'permissions.*' => ['string', Rule::in(self::GRANTABLE_PERMISSIONS)],
            'reason' => ['required', 'string', 'min:8', 'max:500'],
        ]);
        if ($request->user()->id === $userId) {
            return response()->json(['message' => 'Administrators cannot change their own platform role or permissions.'], 403);
        }

        $subject = User::query()->find($userId);
        if (! $subject) return response()->json(['message' => 'User not found.'], 404);
        $permissions = array_values(array_unique($data['permissions'] ?? []));
        if ($data['platform_role'] !== 'admin' && $permissions !== []) {
            return response()->json(['message' => 'Platform permissions can only be assigned to platform administrators.'], 422);
        }

        DB::transaction(function () use ($request, $subject, $data, $permissions): void {
            $lockedSubject = User::query()->whereKey($subject->id)->lockForUpdate()->firstOrFail();
            $before = ['platform_role' => $lockedSubject->platform_role, 'permissions' => $lockedSubject->platform_permissions ?? []];
            $lockedSubject->forceFill([
                'platform_role' => $data['platform_role'],
                'platform_permissions' => $data['platform_role'] === 'admin' ? $permissions : null,
            ])->save();
            PlatformAuditEvent::query()->create([
                'actor_user_id' => $request->user()->id, 'subject_user_id' => $lockedSubject->id,
                'action' => 'platform_admin.provisioned', 'metadata' => [
                    'before' => $before,
                    'after' => ['platform_role' => $lockedSubject->platform_role, 'permissions' => $lockedSubject->platform_permissions ?? []],
                    'reason' => $data['reason'],
                ],
                'ip_address' => $request->ip(), 'user_agent' => Str::limit((string) $request->userAgent(), 500, ''), 'created_at' => now(),
            ]);
        });

        return response()->json(['message' => 'Platform role and permissions updated and audited.']);
    }

    public function securitySettings(): JsonResponse
    {
        $hours = (int) (DB::table('platform_settings')->where('key', 'login_lockout_hours')->value('value') ?? 1);
        return response()->json(['data' => [
            'failed_login_attempt_limit' => 3,
            'login_lockout_hours' => max(1, min(720, $hours)),
            'transaction_pin_failed_attempt_limit' => 3,
            'transaction_pin_lockout_minutes' => 15,
        ]]);
    }

    public function updateSecuritySettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'login_lockout_hours' => ['required', 'integer', 'min:1', 'max:720'],
            'reason' => ['required', 'string', 'min:8', 'max:500'],
        ]);

        DB::transaction(function () use ($request, $data): void {
            DB::table('platform_settings')->updateOrInsert(
                ['key' => 'login_lockout_hours'],
                ['value' => (string) $data['login_lockout_hours'], 'updated_by_user_id' => $request->user()->id, 'updated_at' => now()]
            );
            PlatformAuditEvent::query()->create([
                'actor_user_id' => $request->user()->id, 'subject_user_id' => $request->user()->id,
                'action' => 'platform.security.login_lockout_updated',
                'metadata' => ['login_lockout_hours' => (int) $data['login_lockout_hours'], 'reason' => trim($data['reason'])],
                'ip_address' => $request->ip(), 'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
                'created_at' => now(),
            ]);
        });

        return response()->json(['data' => [
            'failed_login_attempt_limit' => 3,
            'login_lockout_hours' => (int) $data['login_lockout_hours'],
            'transaction_pin_failed_attempt_limit' => 3,
            'transaction_pin_lockout_minutes' => 15,
        ], 'message' => 'Login lockout policy updated and audited.']);
    }

    public function users(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = User::query()
            ->select(['id', 'name', 'email', 'email_verified_at', 'platform_role', 'created_at'])
            ->orderByDesc('id');

        $term = trim((string) ($data['q'] ?? ''));
        if ($term !== '') {
            $query->where(function ($builder) use ($term): void {
                $builder->where('name', 'like', '%'.$term.'%')
                    ->orWhere('email', 'like', '%'.$term.'%');
            });
        }

        return response()->json([
            'data' => $query->paginate((int) ($data['per_page'] ?? 25)),
        ]);
    }


    /**
     * Read-only platform-wide business directory. Mutations remain in the
     * business-scoped workflows so this endpoint cannot bypass membership controls.
     */
    public function businesses(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['sometimes', 'string', 'max:100'],
            'status' => ['sometimes', 'string', 'max:24'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Business::query()
            ->select(['id', 'name', 'slug', 'country_code', 'status', 'created_by_user_id', 'created_at'])
            ->withCount('memberships')
            ->addSelect(['thrift_groups_count' => DB::table('thrift_groups')
                ->selectRaw('count(*)')->whereColumn('thrift_groups.business_id', 'businesses.id')])
            ->orderByDesc('id');

        if (! empty($data['q'])) {
            $term = trim($data['q']);
            $query->where(fn ($builder) => $builder->where('name', 'like', '%'.$term.'%')
                ->orWhere('slug', 'like', '%'.$term.'%'));
        }
        if (! empty($data['status'])) $query->where('status', $data['status']);

        return response()->json(['data' => $query->paginate((int) ($data['per_page'] ?? 25))]);
    }

    /**
     * Platform-wide, read-only collection monitoring. Private payment destinations,
     * proof paths, raw provider payloads and member notes are deliberately excluded.
     */
    public function collections(Request $request): JsonResponse
    {
        $data = $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $limit = (int) ($data['per_page'] ?? 25);

        $contributions = ContributionRecord::query()
            ->join('thrift_groups', 'thrift_groups.id', '=', 'contribution_records.thrift_group_id')
            ->join('businesses', 'businesses.id', '=', 'thrift_groups.business_id')
            ->join('users', 'users.id', '=', 'contribution_records.user_id')
            ->select(['contribution_records.id', 'contribution_records.thrift_group_id', 'thrift_groups.name as group_name',
                'businesses.id as business_id', 'businesses.name as business_name', 'users.name as member_name',
                'contribution_records.amount_minor', 'contribution_records.currency_code', 'contribution_records.status',
                'contribution_records.collection_method', 'contribution_records.reference', 'contribution_records.created_at'])
            ->orderByDesc('contribution_records.id')->limit($limit)->get();

        $deposits = DepositIntent::query()
            ->join('businesses', 'businesses.id', '=', 'deposit_intents.business_id')
            ->join('users', 'users.id', '=', 'deposit_intents.user_id')
            ->select(['deposit_intents.id', 'deposit_intents.business_id', 'businesses.name as business_name',
                'users.name as member_name', 'deposit_intents.provider', 'deposit_intents.method',
                'deposit_intents.status', 'deposit_intents.amount_minor', 'deposit_intents.currency_code',
                'deposit_intents.client_reference', 'deposit_intents.created_at'])
            ->orderByDesc('deposit_intents.id')->limit($limit)->get();

        $payouts = PayoutRequest::query()
            ->join('businesses', 'businesses.id', '=', 'payout_requests.business_id')
            ->join('users', 'users.id', '=', 'payout_requests.user_id')
            ->select(['payout_requests.id', 'payout_requests.business_id', 'businesses.name as business_name',
                'users.name as member_name', 'payout_requests.provider', 'payout_requests.method',
                'payout_requests.status', 'payout_requests.amount_minor', 'payout_requests.currency_code',
                'payout_requests.client_reference', 'payout_requests.approved_by_user_id',
                'payout_requests.paid_confirmed_by_user_id', 'payout_requests.created_at'])
            ->orderByDesc('payout_requests.id')->limit($limit)->get();

        return response()->json(['data' => [
            'contributions' => $contributions,
            'deposits' => $deposits,
            'payouts' => $payouts,
            'limit_per_category' => $limit,
            'read_only' => true,
        ]]);
    }

    /**
     * Reconciliation visibility only; matching/ledger posting stays in the
     * business-scoped, audited reconciliation workflow.
     */
    public function reconciliation(Request $request): JsonResponse
    {
        $data = $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $limit = (int) ($data['per_page'] ?? 25);

        $batches = ReconciliationBatch::query()
            ->join('businesses', 'businesses.id', '=', 'reconciliation_batches.business_id')
            ->select(['reconciliation_batches.id', 'reconciliation_batches.business_id', 'businesses.name as business_name',
                'reconciliation_batches.source', 'reconciliation_batches.source_reference',
                'reconciliation_batches.currency_code', 'reconciliation_batches.status',
                'reconciliation_batches.entry_count', 'reconciliation_batches.created_at'])
            ->orderByDesc('reconciliation_batches.id')->limit($limit)->get();

        $exceptions = ReconciliationEntry::query()
            ->join('reconciliation_batches', 'reconciliation_batches.id', '=', 'reconciliation_entries.reconciliation_batch_id')
            ->join('businesses', 'businesses.id', '=', 'reconciliation_batches.business_id')
            ->whereIn('reconciliation_entries.status', ['unmatched', 'partial', 'conflict', 'duplicate', 'ignore'])
            ->select(['reconciliation_entries.id', 'reconciliation_entries.reconciliation_batch_id',
                'businesses.id as business_id', 'businesses.name as business_name',
                'reconciliation_batches.source', 'reconciliation_batches.source_reference',
                'reconciliation_entries.external_reference', 'reconciliation_entries.amount_minor',
                'reconciliation_entries.currency_code', 'reconciliation_entries.status',
                'reconciliation_entries.occurred_at', 'reconciliation_entries.created_at'])
            ->orderByDesc('reconciliation_entries.id')->limit($limit)->get();

        return response()->json(['data' => [
            'batches' => $batches, 'unresolved_exceptions' => $exceptions,
            'limit_per_category' => $limit, 'read_only' => true,
        ]]);
    }

    /**
     * Combined read-only review queue. Decisions must still be performed by
     * the existing business-scoped endpoints with their separation-of-duties rules.
     */
    public function approvals(Request $request): JsonResponse
    {
        $data = $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $limit = (int) ($data['per_page'] ?? 25);

        $deposits = DepositIntent::query()
            ->join('businesses', 'businesses.id', '=', 'deposit_intents.business_id')
            ->join('users', 'users.id', '=', 'deposit_intents.user_id')
            ->where('deposit_intents.status', 'pending_review')
            ->select(['deposit_intents.id', 'deposit_intents.business_id', 'businesses.name as business_name',
                'users.name as requester_name', 'deposit_intents.provider', 'deposit_intents.amount_minor',
                'deposit_intents.currency_code', 'deposit_intents.client_reference', 'deposit_intents.status',
                'deposit_intents.created_at'])
            ->orderBy('deposit_intents.created_at')->limit($limit)->get();

        $payouts = PayoutRequest::query()
            ->join('businesses', 'businesses.id', '=', 'payout_requests.business_id')
            ->join('users', 'users.id', '=', 'payout_requests.user_id')
            ->whereIn('payout_requests.status', ['requested', 'approved_manual_execution_pending', 'manual_payment_evidence_submitted', 'manual_evidence_accepted_unsettled'])
            ->select(['payout_requests.id', 'payout_requests.business_id', 'businesses.name as business_name',
                'users.name as requester_name', 'payout_requests.provider', 'payout_requests.amount_minor',
                'payout_requests.currency_code', 'payout_requests.client_reference', 'payout_requests.status',
                'payout_requests.approved_by_user_id', 'payout_requests.paid_confirmed_by_user_id',
                'payout_requests.created_at'])
            ->orderBy('payout_requests.created_at')->limit($limit)->get();

        $adjustments = ContributionRecordAdjustment::query()
            ->join('thrift_groups', 'thrift_groups.id', '=', 'contribution_record_adjustments.thrift_group_id')
            ->join('businesses', 'businesses.id', '=', 'thrift_groups.business_id')
            ->join('contribution_records', 'contribution_records.id', '=', 'contribution_record_adjustments.contribution_record_id')
            ->join('users', 'users.id', '=', 'contribution_record_adjustments.requested_by_user_id')
            ->where('contribution_record_adjustments.status', 'pending')
            ->select(['contribution_record_adjustments.id', 'contribution_record_adjustments.thrift_group_id',
                'thrift_groups.name as group_name', 'businesses.id as business_id', 'businesses.name as business_name',
                'contribution_record_adjustments.contribution_record_id', 'contribution_records.amount_minor as current_amount_minor',
                'contribution_records.currency_code', 'contribution_record_adjustments.adjustment_type',
                'contribution_record_adjustments.proposed_amount_minor', 'contribution_record_adjustments.reason',
                'users.name as requester_name', 'contribution_record_adjustments.created_at'])
            ->orderBy('contribution_record_adjustments.created_at')->limit($limit)->get();

        return response()->json(['data' => [
            'pending_deposits' => $deposits,
            'pending_payout_decisions' => $payouts,
            'pending_contribution_adjustments' => $adjustments,
            'limit_per_category' => $limit,
            'read_only' => true,
            'message' => 'This platform-wide queue is for oversight only. Decisions remain in audited business-scoped workflows and never trigger automatic financial execution.',
        ]]);
    }

    public function auditEvents(): JsonResponse
    {
        return response()->json(['data' => PlatformAuditEvent::query()
            ->with(['actor:id,name,email', 'subject:id,name,email'])
            ->orderByDesc('created_at')->paginate(50)]);
    }
}
