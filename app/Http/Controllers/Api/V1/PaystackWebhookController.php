<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PaymentGatewayConfig;
use App\Models\PaymentOperationEvent;
use App\Models\ProviderDepositEvent;
use App\Models\ProviderWebhookEvent;
use App\Models\UserDepositAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class PaystackWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $raw = $request->getContent();
        $signature = (string) $request->header('x-paystack-signature', '');
        if ($raw === '' || $signature === '') {
            return response()->json(['message' => 'Missing signed webhook payload.'], 400);
        }

        $payload = json_decode($raw, true);
        if (! is_array($payload) || ! is_string($payload['event'] ?? null) || ! is_array($payload['data'] ?? null)) {
            return response()->json(['message' => 'Invalid provider webhook payload.'], 400);
        }

        $configs = PaymentGatewayConfig::query()->where('provider', 'paystack')->where('direction', 'deposit')->get();
        $matchedBusinessId = null;
        $matchedSecret = null;
        foreach ($configs as $config) {
            $credentials = json_decode((string) $config->credentials_ciphertext, true);
            $secret = is_array($credentials) ? ($credentials['secret_key'] ?? null) : null;
            if (! is_string($secret) || $secret === '') {
                continue;
            }
            $expected = hash_hmac('sha512', $raw, $secret);
            if (hash_equals($expected, $signature)) {
                $matchedBusinessId = (int) $config->business_id;
                $matchedSecret = $secret;
                break;
            }
        }
        unset($matchedSecret);
        if (! $matchedBusinessId) {
            return response()->json(['message' => 'Webhook signature verification failed.'], 401);
        }

        $eventType = $payload['event'];
        $data = $payload['data'];
        $objectId = isset($data['id']) ? (string) $data['id'] : null;
        $transactionRef = isset($data['reference']) ? (string) $data['reference'] : '';
        $eventKey = hash('sha256', implode('|', [
            'paystack', (string) $matchedBusinessId, $eventType, $objectId ?? '', $transactionRef,
            (string) ($data['status'] ?? ''), (string) ($data['customer']['customer_code'] ?? $data['customer_code'] ?? ''),
        ]));
        $payloadHash = hash('sha256', $raw);
        $safeMetadata = [
            'currency' => isset($data['currency']) ? (string) $data['currency'] : null,
            'status' => isset($data['status']) ? (string) $data['status'] : null,
            'reference_present' => $transactionRef !== '',
        ];

        return DB::transaction(function () use ($matchedBusinessId, $eventType, $data, $objectId, $transactionRef, $eventKey, $payloadHash, $safeMetadata): JsonResponse {
            $event = ProviderWebhookEvent::query()->firstOrCreate(
                ['provider' => 'paystack', 'event_key' => $eventKey],
                [
                    'business_id' => $matchedBusinessId, 'event_type' => $eventType,
                    'provider_object_id' => $objectId, 'status' => 'received',
                    'payload_hash' => $payloadHash, 'safe_metadata' => $safeMetadata, 'received_at' => now(),
                ]
            );
            if (! $event->wasRecentlyCreated) {
                if (! hash_equals((string) $event->payload_hash, $payloadHash)) {
                    // The provider event identity already exists, but the signed body differs.
                    // Preserve the original payload hash, quarantine the event, and audit the
                    // conflict instead of silently treating a changed amount/status as a replay.
                    $event->forceFill(['status' => 'conflict'])->save();
                    PaymentOperationEvent::query()->create([
                        'business_id' => $matchedBusinessId,
                        'operation_type' => 'provider_webhook_event',
                        'operation_id' => $event->id,
                        'actor_user_id' => null,
                        'event' => 'provider_webhook_conflicting_replay',
                        'reason' => 'A signed provider webhook reused an existing event identity with a different payload hash; no settlement or balance credit was posted.',
                        'evidence' => [
                            'provider' => 'paystack',
                            'event_type' => $eventType,
                            'provider_object_id' => $objectId,
                            'original_payload_hash' => (string) $event->payload_hash,
                            'incoming_payload_hash' => $payloadHash,
                        ],
                        'created_at' => now(),
                    ]);

                    return response()->json([
                        'received' => false,
                        'duplicate' => false,
                        'conflict' => true,
                        'message' => 'Conflicting signed provider event quarantined for review.',
                    ], 409);
                }

                return response()->json(['received' => true, 'duplicate' => true]);
            }

            $accountData = (array) ($data['dedicated_account'] ?? $data['account'] ?? []);
            if (isset($data['account_number']) && ! isset($accountData['account_number'])) {
                $accountData['account_number'] = $data['account_number'];
            }
            if (isset($data['account_name']) && ! isset($accountData['account_name'])) {
                $accountData['account_name'] = $data['account_name'];
            }
            $customer = (array) ($data['customer'] ?? []);
            $customerCode = (string) ($customer['customer_code'] ?? $data['customer_code'] ?? '');
            $accountId = isset($accountData['id']) ? (string) $accountData['id'] : (isset($data['dedicated_account_id']) ? (string) $data['dedicated_account_id'] : (isset($data['id']) ? (string) $data['id'] : ''));
            $accountNumber = (string) ($accountData['account_number'] ?? '');
            $accountName = (string) ($accountData['account_name'] ?? '');
            $bank = (array) ($accountData['bank'] ?? $data['bank'] ?? []);
            $baseAccountQuery = UserDepositAccount::query()
                ->where('business_id', $matchedBusinessId)->where('provider', 'paystack');
            $account = null;
            if ($accountId !== '') {
                $account = (clone $baseAccountQuery)->where('provider_account_id', $accountId)->lockForUpdate()->first();
            }
            if (! $account && $accountNumber !== '') {
                $account = (clone $baseAccountQuery)->where('account_number_hash', hash('sha256', $accountNumber))->lockForUpdate()->first();
            }
            if (! $account && $customerCode !== '') {
                $candidateQuery = (clone $baseAccountQuery)->where('provider_customer_id', $customerCode);
                if (in_array($eventType, ['dedicated_account.assign.success', 'dedicated_account.assign.failed', 'dedicated_account.assign.failure', 'dedicatedaccount.assign.success', 'dedicatedaccount.assign.failed', 'dedicatedaccount.assign.failure'], true)) {
                    $candidateQuery->where('status', 'provisioning');
                    if (! empty($bank['slug'])) $candidateQuery->where('bank_code', (string) $bank['slug']);
                    elseif (! empty($bank['name'])) $candidateQuery->where('bank_name', (string) $bank['name']);
                } elseif ($eventType === 'charge.success') {
                    $candidateQuery->where('status', 'active');
                }
                $candidates = $candidateQuery->lockForUpdate()->limit(2)->get();
                if ($candidates->count() === 1) {
                    $account = $candidates->first();
                }
            }

            if (in_array($eventType, ['dedicated_account.assign.success', 'dedicated_account.assign.failed', 'dedicated_account.assign.failure', 'dedicatedaccount.assign.success', 'dedicatedaccount.assign.failed', 'dedicatedaccount.assign.failure'], true)) {
                if ($account) {
                    $failed = str_ends_with($eventType, 'failed') || str_ends_with($eventType, 'failure');
                    $assigned = ! $failed && $accountNumber !== '' && $accountName !== '';
                    $fields = ['status' => $failed ? 'failed' : ($assigned ? 'active' : 'provisioning')];
                    if ($accountId !== '') $fields['provider_account_id'] = $accountId;
                    if ($customerCode !== '') $fields['provider_customer_id'] = $customerCode;
                    if ($accountNumber !== '') {
                        $fields['account_number_ciphertext'] = $accountNumber;
                        $fields['account_number_hash'] = hash('sha256', $accountNumber);
                    }
                    if ($accountName !== '') $fields['account_name_ciphertext'] = $accountName;
                    if (! empty($bank['slug'])) $fields['bank_code'] = (string) $bank['slug'];
                    if (! empty($bank['name'])) $fields['bank_name'] = (string) $bank['name'];
                    if (! empty($data['currency'])) $fields['currency_code'] = (string) $data['currency'];
                    if ($assigned) $fields['assigned_at'] = now();
                    $account->forceFill($fields)->save();
                    PaymentOperationEvent::query()->create([
                        'business_id' => $matchedBusinessId, 'operation_type' => 'user_deposit_account',
                        'operation_id' => $account->id, 'actor_user_id' => null,
                        'event' => $failed ? 'dva_assign_failed_hook' : 'dva_assign_confirmed_hook',
                        'reason' => 'Signed provider assignment webhook processed; no balance or ledger credit was posted.',
                        'evidence' => ['provider' => 'paystack', 'provider_event' => $eventType, 'provider_object_id' => $objectId],
                        'created_at' => now(),
                    ]);
                    $event->forceFill(['status' => 'processed', 'processed_at' => now()])->save();
                } else {
                    $event->forceFill(['status' => 'unmatched', 'processed_at' => now()])->save();
                }
            } elseif ($eventType === 'charge.success') {
                $transactionId = $objectId;
                $amount = filter_var($data['amount'] ?? null, FILTER_VALIDATE_INT);
                $currency = strtoupper((string) ($data['currency'] ?? ''));
                if ($transactionId && $amount !== false && $amount > 0 && preg_match('/^[A-Z]{3}$/', $currency)) {
                    $deposit = ProviderDepositEvent::query()->firstOrCreate(
                        ['business_id' => $matchedBusinessId, 'provider' => 'paystack', 'provider_transaction_id' => $transactionId],
                        [
                            'business_id' => $matchedBusinessId, 'user_id' => $account?->user_id,
                            'user_deposit_account_id' => $account?->id, 'provider_reference' => $transactionRef ?: null,
                            'amount_minor' => $amount, 'currency_code' => $currency,
                            'status' => $account ? 'needs_review' : 'unmatched_account',
                            'payload_hash' => $payloadHash,
                            'safe_metadata' => [
                                'provider_status' => (string) ($data['status'] ?? ''),
                                'channel' => (string) ($data['channel'] ?? ''),
                                'account_matched' => (bool) $account,
                            ],
                            'occurred_at' => isset($data['paid_at']) ? now()->parse($data['paid_at']) : null,
                        ]
                    );
                    $event->forceFill(['status' => $deposit->wasRecentlyCreated ? 'processed' : 'duplicate', 'processed_at' => now()])->save();
                    PaymentOperationEvent::query()->create([
                        'business_id' => $matchedBusinessId, 'operation_type' => 'provider_deposit_event',
                        'operation_id' => $deposit->id, 'actor_user_id' => null,
                        'event' => $deposit->wasRecentlyCreated ? 'provider_deposit_received_review' : 'provider_deposit_duplicate_hook',
                        'reason' => 'Signed provider charge event stored as reconciliation evidence only; no contribution, wallet or ledger credit was posted.',
                        'evidence' => ['provider' => 'paystack', 'transaction_id' => $transactionId, 'amount_minor' => $amount, 'currency_code' => $currency, 'account_matched' => (bool) $account],
                        'created_at' => now(),
                    ]);
                } else {
                    $event->forceFill(['status' => 'invalid_event', 'processed_at' => now()])->save();
                }
            } else {
                $event->forceFill(['status' => 'ignored_event', 'processed_at' => now()])->save();
            }
            return response()->json(['received' => true, 'duplicate' => false]);
        });
    }

    public function requeryDepositEvent(Request $request, int $eventId): JsonResponse
    {
        $business = $request->attributes->get('current_business');
        $deposit = ProviderDepositEvent::query()->where('business_id', $business->id)
            ->whereKey($eventId)->firstOrFail();

        if ($deposit->provider !== 'paystack' || ! $deposit->provider_reference) {
            return response()->json(['message' => 'Only Paystack deposits with a provider reference can be requeried.'], 422);
        }
        if (in_array($deposit->status, ['conflict', 'ignored'], true)) {
            return response()->json(['message' => 'Conflicting or ignored evidence cannot be requeried into a settlement decision.'], 409);
        }

        $config = PaymentGatewayConfig::query()->where('business_id', $business->id)
            ->where('provider', 'paystack')->where('direction', 'deposit')->first();
        $credentials = $config ? json_decode((string) $config->credentials_ciphertext, true) : null;
        $secret = is_array($credentials) ? ($credentials['secret_key'] ?? null) : null;
        if (! is_string($secret) || $secret === '') {
            return response()->json(['message' => 'Paystack server-side status requery is unavailable because credentials are not configured.'], 503);
        }

        try {
            $response = Http::withToken($secret)->acceptJson()->timeout(10)
                ->get('https://api.paystack.co/transaction/verify/'.rawurlencode($deposit->provider_reference));
        } catch (\Throwable $exception) {
            PaymentOperationEvent::query()->create([
                'business_id' => $business->id, 'operation_type' => 'provider_deposit_event',
                'operation_id' => $deposit->id, 'actor_user_id' => $request->user()->id,
                'event' => 'provider_deposit_status_requery_failed',
                'reason' => 'Authenticated provider status requery failed; the event remains uncredited and retryable.',
                'evidence' => ['provider' => 'paystack', 'provider_reference_hash' => hash('sha256', $deposit->provider_reference), 'failure_class' => class_basename($exception)],
                'created_at' => now(),
            ]);
            return response()->json(['message' => 'Provider status is unknown because the status query failed. No financial balance was changed.'], 503);
        }

        $body = $response->json();
        if (! $response->successful() || ! is_array($body) || ($body['status'] ?? false) !== true || ! is_array($body['data'] ?? null)) {
            PaymentOperationEvent::query()->create([
                'business_id' => $business->id, 'operation_type' => 'provider_deposit_event',
                'operation_id' => $deposit->id, 'actor_user_id' => $request->user()->id,
                'event' => 'provider_deposit_status_requery_unknown',
                'reason' => 'Provider status API did not return a verified transaction result; no settlement or credit was performed.',
                'evidence' => ['provider' => 'paystack', 'provider_reference_hash' => hash('sha256', $deposit->provider_reference), 'http_status' => $response->status()],
                'created_at' => now(),
            ]);
            return response()->json(['message' => 'Provider status could not be verified. No financial balance was changed.'], 502);
        }

        $providerData = $body['data'];
        $providerStatus = (string) ($providerData['status'] ?? 'unknown');
        $providerReference = (string) ($providerData['reference'] ?? '');
        $providerAmount = filter_var($providerData['amount'] ?? null, FILTER_VALIDATE_INT);
        $providerCurrency = strtoupper((string) ($providerData['currency'] ?? ''));
        $providerId = isset($providerData['id']) ? (string) $providerData['id'] : '';
        $matches = $providerReference === $deposit->provider_reference
            && $providerAmount !== false && (int) $providerAmount === (int) $deposit->amount_minor
            && $providerCurrency === $deposit->currency_code
            && $providerId === $deposit->provider_transaction_id;
        $conflict = ! $matches;
        $metadata = $deposit->safe_metadata ?? [];
        $metadata['status_requery'] = [
            'provider' => 'paystack', 'queried_at' => now()->toISOString(),
            'provider_status' => $providerStatus, 'matches_record' => $matches,
            'provider_transaction_id' => $providerId !== '' ? $providerId : null,
            'amount_minor' => $providerAmount === false ? null : (int) $providerAmount,
            'currency_code' => $providerCurrency ?: null,
            'provider_reference_hash' => hash('sha256', $deposit->provider_reference),
        ];

        DB::transaction(function () use ($deposit, $metadata, $conflict, $business, $request, $providerStatus, $matches): void {
            $locked = ProviderDepositEvent::query()->where('business_id', $business->id)
                ->whereKey($deposit->id)->lockForUpdate()->firstOrFail();
            $locked->forceFill([
                'safe_metadata' => $metadata,
                'status' => $conflict ? 'conflict' : $locked->status,
            ])->save();
            PaymentOperationEvent::query()->create([
                'business_id' => $business->id, 'operation_type' => 'provider_deposit_event',
                'operation_id' => $locked->id, 'actor_user_id' => $request->user()->id,
                'event' => $conflict ? 'provider_deposit_status_requery_conflict' : 'provider_deposit_status_requeried',
                'reason' => $conflict
                    ? 'Authenticated provider response did not match the recorded transaction ID, reference, amount, or currency; evidence quarantined.'
                    : 'Authenticated provider status requery matched the recorded transaction. This is evidence only and does not settle or credit funds.',
                'evidence' => [
                    'provider' => 'paystack', 'provider_status' => $providerStatus,
                    'matches_record' => $matches, 'financial_posting' => false,
                ],
                'created_at' => now(),
            ]);
        });

        return response()->json([
            'data' => ['id' => $deposit->id, 'provider_status' => $providerStatus, 'matches_record' => $matches,
                'status' => $conflict ? 'conflict' : $deposit->status, 'financial_posting' => false],
            'message' => $conflict
                ? 'Provider response conflicts with recorded evidence. The event is quarantined; no financial balance was changed.'
                : 'Authenticated provider status recorded as evidence only. Independent reconciliation and settlement approval are still required.',
        ], $conflict ? 409 : 200);
    }

    public function depositsForReview(Request $request): JsonResponse
    {
        $business = $request->attributes->get('current_business');
        $query = ProviderDepositEvent::query()->where('business_id', $business->id)->with('account');
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        } else {
            $query->whereNull('reviewed_at');
        }
        return response()->json(['data' => $query->orderByDesc('created_at')->paginate(25)]);
    }

    public function reviewDepositEvent(Request $request, int $eventId): JsonResponse
    {
        $business = $request->attributes->get('current_business');
        $data = $request->validate([
            'decision' => ['required', \Illuminate\Validation\Rule::in(['confirm_evidence', 'mark_conflict', 'ignore'])],
            'reason' => ['required', 'string', 'min:8', 'max:500'],
        ]);

        return DB::transaction(function () use ($request, $business, $eventId, $data): JsonResponse {
            $deposit = ProviderDepositEvent::query()->where('business_id', $business->id)
                ->whereKey($eventId)->lockForUpdate()->firstOrFail();
            if ($deposit->reviewed_at) {
                return response()->json(['message' => 'This provider deposit event has already been reviewed. A later change requires a separately audited correction process.'], 409);
            }
            if ($deposit->user_id === $request->user()->id) {
                return response()->json(['message' => 'The account holder cannot review their own provider deposit event.'], 403);
            }
            $status = match ($data['decision']) {
                'confirm_evidence' => 'evidence_reconciled',
                'mark_conflict' => 'conflict',
                default => 'ignored',
            };
            $deposit->forceFill([
                'status' => $status, 'reviewed_by_user_id' => $request->user()->id,
                'review_decision' => $data['decision'], 'review_reason' => trim($data['reason']),
                'reviewed_at' => now(),
            ])->save();
            PaymentOperationEvent::query()->create([
                'business_id' => $business->id, 'operation_type' => 'provider_deposit_event',
                'operation_id' => $deposit->id, 'actor_user_id' => $request->user()->id,
                'event' => 'deposit_reconcile_'.$data['decision'],
                'reason' => trim($data['reason']),
                'evidence' => [
                    'provider' => $deposit->provider, 'provider_transaction_id' => $deposit->provider_transaction_id,
                    'amount_minor' => $deposit->amount_minor, 'currency_code' => $deposit->currency_code,
                    'note' => 'Evidence review only; no wallet, contribution or ledger credit was posted.',
                ],
                'created_at' => now(),
            ]);
            return response()->json([
                'data' => $deposit->fresh(),
                'message' => 'Provider deposit evidence review recorded. No financial balance was changed.',
            ]);
        });
    }

}
