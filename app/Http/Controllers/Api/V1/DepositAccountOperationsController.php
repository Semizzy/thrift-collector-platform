<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\UserDepositAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DepositAccountOperationsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $business = $request->attributes->get('current_business');
        $cutoff = now()->subHour();
        $accounts = UserDepositAccount::query()
            ->where('business_id', $business->id)
            ->where('provider', 'paystack')
            ->where('status', 'provisioning')
            ->where(function ($query) use ($cutoff): void {
                $query->where('recovery_attempts', '>=', 3)
                    ->orWhere('created_at', '<=', $cutoff);
            })
            ->orderByDesc('recovery_attempts')->orderBy('created_at')
            ->limit(100)->get();

        $escalations = DB::table('deposit_account_escalations')
            ->where('business_id', $business->id)
            ->whereIn('user_deposit_account_id', $accounts->pluck('id'))
            ->get()->keyBy('user_deposit_account_id');

        $data = $accounts->map(function (UserDepositAccount $account) use ($escalations): array {
            $ageMinutes = max(0, $account->created_at?->diffInMinutes(now()) ?? 0);
            $escalation = $escalations->get($account->id);
            $diagnostic = match (true) {
                ! $account->provider_customer_id => 'customer_reference_missing',
                $account->recovery_attempts >= 12 => 'retry_limit_reached',
                $account->last_recovery_result === 'credentials_unavailable' => 'provider_credentials_unavailable',
                $account->last_recovery_result === 'provider_error' => 'provider_requery_failed',
                $account->last_recovery_result === 'still_pending' => 'provider_has_not_confirmed_assignment',
                default => 'assignment_not_confirmed',
            };

            return [
                'id' => $account->id,
                'bank_name' => $account->bank_name,
                'bank_code' => $account->bank_code,
                'currency_code' => $account->currency_code,
                'status' => $account->status,
                'created_at' => $account->created_at?->toISOString(),
                'age_minutes' => $ageMinutes,
                'recovery_attempts' => (int) $account->recovery_attempts,
                'last_recovery_at' => $account->last_recovery_at?->toISOString(),
                'last_recovery_result' => $account->last_recovery_result,
                'has_provider_customer_reference' => filled($account->provider_customer_id),
                'has_provider_account_reference' => filled($account->provider_account_id),
                'diagnostic_code' => $diagnostic,
                'recommended_action' => $account->recovery_attempts >= 12
                    ? 'Stop automatic retries and contact provider support; do not request a replacement account.'
                    : 'Check provider dashboard and webhook delivery. Requery the existing assignment only; do not create a duplicate.',
                'escalation' => $escalation ? [
                    'id' => $escalation->id,
                    'status' => $escalation->status,
                    'created_at' => $escalation->created_at,
                ] : null,
            ];
        })->values();

        return response()->json([
            'data' => $data,
            'meta' => ['count' => $data->count(), 'financial_posting_enabled' => false],
        ]);
    }

    public function escalate(Request $request, int $accountId): JsonResponse
    {
        $business = $request->attributes->get('current_business');
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        return DB::transaction(function () use ($request, $business, $accountId, $data): JsonResponse {
            $account = UserDepositAccount::query()->where('business_id', $business->id)
                ->whereKey($accountId)->lockForUpdate()->firstOrFail();

            if ($account->status !== 'provisioning'
                || ($account->recovery_attempts < 3 && $account->created_at?->gt(now()->subHour()))) {
                return response()->json(['message' => 'Only repeatedly retried or at-least-one-hour pending accounts can be escalated.'], 422);
            }

            $existing = DB::table('deposit_account_escalations')->where('user_deposit_account_id', $account->id)->first();
            if ($existing) {
                return response()->json(['data' => ['id' => $existing->id, 'status' => $existing->status], 'message' => 'An escalation already exists for this account.'], 200);
            }

            $diagnosticCode = $account->recovery_attempts >= 12 ? 'retry_limit_reached' : ($account->last_recovery_result ?? 'assignment_not_confirmed');
            $id = DB::table('deposit_account_escalations')->insertGetId([
                'business_id' => $business->id,
                'user_deposit_account_id' => $account->id,
                'requested_by_user_id' => $request->user()->id,
                'reason' => trim($data['reason']),
                'status' => 'open',
                'diagnostic_code' => $diagnosticCode,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('payment_operation_events')->insert([
                'business_id' => $business->id,
                'operation_type' => 'user_deposit_account',
                'operation_id' => $account->id,
                'actor_user_id' => $request->user()->id,
                'event' => 'deposit_account_escalated',
                'reason' => trim($data['reason']),
                'evidence' => json_encode(['escalation_id' => $id, 'diagnostic_code' => $diagnosticCode, 'financial_posting' => false], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);

            return response()->json([
                'data' => ['id' => $id, 'status' => 'open', 'account_id' => $account->id],
                'message' => 'Escalation recorded for authorized business support follow-up. No new provider assignment or financial posting was initiated.',
            ], 201);
        });
    }
}
