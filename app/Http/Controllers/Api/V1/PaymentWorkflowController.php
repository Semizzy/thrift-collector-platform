<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BusinessAuditEvent;
use App\Models\BankAccountVerification;
use App\Models\DepositIntent;
use App\Models\PaymentGatewayConfig;
use App\Models\PaymentOperationEvent;
use App\Models\PayoutRequest;
use App\Services\WalletLedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PaymentWorkflowController extends Controller
{
    private const PROVIDERS = ['paystack', 'opay', 'monnify', 'flutterwave', 'manual'];

    public function gateways(Request $request): JsonResponse
    {
        $business = $request->attributes->get('current_business');
        $configured = PaymentGatewayConfig::query()->where('business_id', $business->id)->get()
            ->keyBy(fn ($row) => $row->provider.':'.$row->direction);
        $catalogue = [];
        foreach (self::PROVIDERS as $provider) {
            foreach (['deposit', 'payout'] as $direction) {
                $row = $configured->get($provider.':'.$direction);
                $catalogue[] = [
                    'provider' => $provider, 'direction' => $direction,
                    'status' => $provider === 'manual' ? ($row?->status ?? 'manual_review_only') : ($row?->status ?? 'disabled'),
                    'ready_for_live_execution' => false,
                    'configured' => (bool) $row,
                ];
            }
        }
        return response()->json(['data' => $catalogue, 'message' => 'Provider adapters are not enabled for live execution in this release.']);
    }

    public function configureGateway(Request $request, string $provider, string $direction): JsonResponse
    {
        $business = $request->attributes->get('current_business');
        abort_unless(in_array($provider, self::PROVIDERS, true) && in_array($direction, ['deposit', 'payout'], true), 404);
        $data = $request->validate([
            'public_config' => ['sometimes', 'array'],
            'credentials' => ['sometimes', 'array'],
        ]);
        if ($provider !== 'manual' && empty($data['credentials'])) {
            return response()->json(['message' => 'Provide provider credentials to store an encrypted, disabled configuration.'], 422);
        }
        $config = PaymentGatewayConfig::query()->updateOrCreate(
            ['business_id' => $business->id, 'provider' => $provider, 'direction' => $direction],
            ['status' => 'disabled', 'public_config' => $data['public_config'] ?? [],
                'credentials_ciphertext' => isset($data['credentials']) ? json_encode($data['credentials'], JSON_THROW_ON_ERROR) : null,
                'updated_by_user_id' => $request->user()->id]
        );
        PaymentOperationEvent::query()->create([
            'business_id' => $business->id, 'operation_type' => 'gateway_config', 'operation_id' => $config->id,
            'actor_user_id' => $request->user()->id, 'event' => 'gateway_configured_disabled',
            'reason' => 'Configuration stored; provider remains disabled until independent release approval.',
            'evidence' => ['provider' => $provider, 'direction' => $direction, 'credentials_supplied' => ! empty($data['credentials'])],
            'created_at' => now(),
        ]);
        return response()->json(['data' => ['provider' => $provider, 'direction' => $direction, 'status' => 'disabled', 'configured' => true],
            'message' => 'Configuration saved encrypted. It remains disabled; this endpoint does not test credentials or enable live execution.']);
    }

    public function deposits(Request $request): JsonResponse
    {
        $business = $request->attributes->get('current_business');
        return response()->json(['data' => DepositIntent::query()->where('business_id', $business->id)
            ->where('user_id', $request->user()->id)->latest()->paginate(25)]);
    }

    public function createDeposit(Request $request): JsonResponse
    {
        $business = $request->attributes->get('current_business');
        $data = $request->validate([
            'provider' => ['required', Rule::in(self::PROVIDERS)],
            'amount_minor' => ['required', 'integer', 'min:1', 'max:1000000000000'],
            'currency_code' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'client_reference' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'provider_reference' => ['nullable', 'string', 'max:160'],
        ]);
        $manual = $data['provider'] === 'manual';
        if (! $manual) {
            return response()->json(['message' => 'This provider is not enabled for live deposits. Gateway credentials, sandbox verification, signed webhooks, server-side status requery, and reconciliation must pass release gates first. No payment was initiated.'], 503);
        }
        $deposit = DB::transaction(function () use ($request, $business, $data): DepositIntent {
            $deposit = DepositIntent::query()->firstOrCreate(
                ['business_id' => $business->id, 'client_reference' => $data['client_reference']],
                ['user_id' => $request->user()->id, 'provider' => 'manual', 'method' => 'manual',
                    'status' => 'pending_review', 'amount_minor' => $data['amount_minor'],
                    'currency_code' => $data['currency_code'], 'provider_reference' => $data['provider_reference'] ?? null]
            );
            if ($deposit->user_id !== $request->user()->id || $deposit->amount_minor !== (int) $data['amount_minor'] || $deposit->currency_code !== $data['currency_code']) {
                abort(409, 'Idempotency reference was already used with different deposit details.');
            }
            if ($deposit->wasRecentlyCreated) PaymentOperationEvent::query()->create([
                'business_id' => $business->id, 'operation_type' => 'deposit', 'operation_id' => $deposit->id,
                'actor_user_id' => $request->user()->id, 'event' => 'manual_deposit_submitted',
                'reason' => 'Submitted for independent review; this does not credit a balance.',
                'evidence' => ['provider_reference_supplied' => ! empty($data['provider_reference'])], 'created_at' => now(),
            ]);
            return $deposit;
        });
        return response()->json(['data' => $deposit, 'message' => 'Manual deposit submitted for review. No wallet or ledger credit has occurred.'], 201);
    }

    public function reviewDeposit(Request $request, int $depositId): JsonResponse
    {
        $business = $request->attributes->get('current_business');
        $data = $request->validate([
            'decision' => ['required', Rule::in(['accept_evidence', 'reject'])],
            'reason' => ['required', 'string', 'min:8', 'max:500'],
        ]);
        return DB::transaction(function () use ($request, $business, $depositId, $data): JsonResponse {
            $deposit = DepositIntent::query()->where('business_id', $business->id)->whereKey($depositId)->lockForUpdate()->firstOrFail();
            if ($deposit->user_id === $request->user()->id) {
                return response()->json(['message' => 'The depositor cannot review their own deposit.'], 403);
            }
            if ($deposit->status !== 'pending_review') {
                return response()->json(['message' => 'This deposit has already been reviewed. Submit a new deposit reference for a new review.'], 409);
            }
            $status = $data['decision'] === 'accept_evidence' ? 'evidence_accepted' : 'rejected';
            $deposit->forceFill(['status' => $status, 'reviewed_by_user_id' => $request->user()->id,
                'review_reason' => trim($data['reason']), 'reviewed_at' => now()])->save();
            PaymentOperationEvent::query()->create([
                'business_id' => $business->id, 'operation_type' => 'deposit', 'operation_id' => $deposit->id,
                'actor_user_id' => $request->user()->id, 'event' => 'manual_deposit_'.$status,
                'reason' => trim($data['reason']), 'evidence' => ['financial_credit_posted' => false], 'created_at' => now(),
            ]);
            return response()->json(['data' => $deposit, 'message' => 'Evidence decision recorded. This workflow does not credit funds or create a ledger entry.']);
        });
    }

    public function payouts(Request $request): JsonResponse
    {
        $business = $request->attributes->get('current_business');
        return response()->json(['data' => PayoutRequest::query()->where('business_id', $business->id)
            ->where('user_id', $request->user()->id)->latest()->paginate(25)]);
    }

    public function createPayout(Request $request, WalletLedgerService $ledger): JsonResponse
    {
        $business = $request->attributes->get('current_business');
        $data = $request->validate([
            'provider' => ['required', Rule::in(self::PROVIDERS)],
            'amount_minor' => ['required', 'integer', 'min:1', 'max:1000000000000'],
            'currency_code' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'client_reference' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'account_verification_id' => ['required', 'integer', 'min:1'],
        ]);
        if ($data['provider'] !== 'manual') {
            return response()->json(['message' => 'This provider is not enabled for live payouts. No payout was sent.'], 503);
        }
        $verification = BankAccountVerification::query()
            ->where('business_id', $business->id)
            ->where('user_id', $request->user()->id)
            ->where('status', 'verified')
            ->where('expires_at', '>', now())
            ->whereKey($data['account_verification_id'])
            ->first();
        if (! $verification) {
            return response()->json(['message' => 'Verify the destination bank account again before submitting this payout.'], 422);
        }
        $destination = json_encode([
            'bank_code' => $verification->bank_code,
            'bank_name' => $verification->bank_name,
            'account_number' => $verification->account_number_ciphertext,
            'account_name' => $verification->account_name_ciphertext,
            'verification_provider' => $verification->verification_provider,
            'verification_id' => $verification->id,
        ], JSON_THROW_ON_ERROR);
        $payout = DB::transaction(function () use ($request, $business, $data, $destination, $verification, $ledger): PayoutRequest {
            $payout = PayoutRequest::query()->firstOrCreate(
                ['business_id' => $business->id, 'client_reference' => $data['client_reference']],
                ['user_id' => $request->user()->id, 'provider' => 'manual', 'method' => 'manual',
                    'status' => 'requested', 'amount_minor' => $data['amount_minor'],
                    'currency_code' => $data['currency_code'], 'destination_ciphertext' => $destination]
            );
            if ($payout->user_id !== $request->user()->id || $payout->amount_minor !== (int) $data['amount_minor'] || $payout->currency_code !== $data['currency_code'] || $payout->destination_ciphertext !== $destination) {
                abort(409, 'Idempotency reference was already used with different payout details.');
            }
            $walletPosting = ['status' => 'already_posted', 'transaction_id' => null];
            if ($payout->wasRecentlyCreated) {
                $walletPosting = $ledger->debitWithdrawal(
                    (int) $business->id, (int) $request->user()->id, $payout->currency_code,
                    (int) $payout->amount_minor, (int) $payout->id, (int) $request->user()->id
                );
                if ($walletPosting['status'] === 'insufficient_balance') {
                    abort(422, 'Insufficient wallet balance for this withdrawal. No payout request was saved.');
                }
            }
            if ($payout->wasRecentlyCreated) PaymentOperationEvent::query()->create([
                'business_id' => $business->id, 'operation_type' => 'payout', 'operation_id' => $payout->id,
                'actor_user_id' => $request->user()->id, 'event' => 'payout_requested',
                'reason' => 'Wallet balance debited and withdrawal entered independent approval; no bank transfer was initiated.', 'evidence' => ['provider' => 'manual', 'bank_verification_id' => $verification->id, 'bank_code' => $verification->bank_code, 'wallet_ledger_transaction_id' => $walletPosting['transaction_id']], 'created_at' => now(),
            ]);
            return $payout;
        });
        return response()->json(['data' => $payout, 'wallet_debit' => $walletPosting, 'message' => $payout->wasRecentlyCreated ? 'Withdrawal requested and deducted from your wallet. It awaits independent approval; no bank transfer has been initiated.' : 'This withdrawal request already exists; the wallet was not debited a second time.'], $payout->wasRecentlyCreated ? 201 : 200);
    }

    public function decidePayout(Request $request, int $payoutId): JsonResponse
    {
        $business = $request->attributes->get('current_business');
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'reason' => ['required', 'string', 'min:8', 'max:500'],
        ]);
        return DB::transaction(function () use ($request, $business, $payoutId, $data): JsonResponse {
            $payout = PayoutRequest::query()->where('business_id', $business->id)->whereKey($payoutId)->lockForUpdate()->firstOrFail();
            if ($payout->user_id === $request->user()->id) {
                return response()->json(['message' => 'The requester cannot approve their own payout.'], 403);
            }
            if ($payout->status !== 'requested') {
                return response()->json(['message' => 'Only a pending payout request can be decided.'], 409);
            }
            $status = $data['decision'] === 'approve' ? 'approved_manual_execution_pending' : 'rejected';
            $payout->forceFill(['status' => $status, 'approved_by_user_id' => $request->user()->id,
                'decision_reason' => trim($data['reason']), 'approved_at' => now()])->save();
            PaymentOperationEvent::query()->create([
                'business_id' => $business->id, 'operation_type' => 'payout', 'operation_id' => $payout->id,
                'actor_user_id' => $request->user()->id, 'event' => 'payout_'.$status,
                'reason' => trim($data['reason']), 'evidence' => ['transfer_executed' => false], 'created_at' => now(),
            ]);
            return response()->json(['data' => $payout, 'message' => 'Decision recorded. Approval is not proof of payment and does not transfer funds.']);
        });
    }

    public function confirmManualPayout(Request $request, int $payoutId): JsonResponse
    {
        $business = $request->attributes->get('current_business');
        $data = $request->validate(['reason' => ['required', 'string', 'min:8', 'max:500'], 'transfer_reference' => ['required', 'string', 'max:160']]);
        return DB::transaction(function () use ($request, $business, $payoutId, $data): JsonResponse {
            $payout = PayoutRequest::query()->where('business_id', $business->id)->whereKey($payoutId)->lockForUpdate()->firstOrFail();
            if ($payout->status !== 'approved_manual_execution_pending') {
                return response()->json(['message' => 'Only an approved manual payout can be submitted for independent payment-evidence review.'], 409);
            }
            if (in_array($request->user()->id, [$payout->user_id, $payout->approved_by_user_id], true)) {
                return response()->json(['message' => 'The requester and approver cannot be the person confirming manual payment evidence.'], 403);
            }
            $payout->forceFill(['status' => 'manual_payment_evidence_submitted', 'paid_confirmed_by_user_id' => $request->user()->id,
                'decision_reason' => trim($data['reason']).' Transfer reference: '.$data['transfer_reference'], 'paid_confirmed_at' => now()])->save();
            PaymentOperationEvent::query()->create([
                'business_id' => $business->id, 'operation_type' => 'payout', 'operation_id' => $payout->id,
                'actor_user_id' => $request->user()->id, 'event' => 'manual_payment_evidence_submitted',
                'reason' => trim($data['reason']), 'evidence' => ['transfer_reference' => $data['transfer_reference'], 'provider_verified' => false], 'created_at' => now(),
            ]);
            return response()->json(['data' => $payout, 'message' => 'Manual payment evidence recorded for reconciliation. This is not provider-verified proof of payment and does not debit a ledger.']);
        });
    }

    public function reviewManualPayoutEvidence(Request $request, int $payoutId): JsonResponse
    {
        $business = $request->attributes->get('current_business');
        $data = $request->validate([
            'decision' => ['required', Rule::in(['accept_evidence', 'reject_evidence'])],
            'reason' => ['required', 'string', 'min:8', 'max:500'],
        ]);
        return DB::transaction(function () use ($request, $business, $payoutId, $data): JsonResponse {
            $payout = PayoutRequest::query()->where('business_id', $business->id)->whereKey($payoutId)->lockForUpdate()->firstOrFail();
            if ($payout->status !== 'manual_payment_evidence_submitted') {
                return response()->json(['message' => 'No submitted manual payout evidence is awaiting review.'], 409);
            }
            if (in_array($request->user()->id, [$payout->user_id, $payout->approved_by_user_id, $payout->paid_confirmed_by_user_id], true)) {
                return response()->json(['message' => 'The requester, approver, and evidence submitter must not review the evidence.'], 403);
            }
            $status = $data['decision'] === 'accept_evidence' ? 'manual_evidence_accepted_unsettled' : 'manual_evidence_rejected';
            $payout->forceFill(['status' => $status, 'decision_reason' => trim($data['reason'])])->save();
            PaymentOperationEvent::query()->create([
                'business_id' => $business->id, 'operation_type' => 'payout', 'operation_id' => $payout->id,
                'actor_user_id' => $request->user()->id, 'event' => $status,
                'reason' => trim($data['reason']), 'evidence' => ['provider_verified' => false, 'ledger_debit_posted' => false], 'created_at' => now(),
            ]);
            return response()->json(['data' => $payout, 'message' => 'Evidence review recorded. Even accepted manual evidence is not provider verification, settlement proof, or a ledger posting.']);
        });
    }
}
