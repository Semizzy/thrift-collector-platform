<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

class EnsureTransactionPin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || blank($user->transaction_pin_hash)) {
            return response()->json(['message' => 'Set a four-digit transaction PIN before submitting this transaction.', 'code' => 'transaction_pin_required'], 428);
        }

        $data = $request->validate([
            'transaction_pin' => ['required', 'digits:4'],
            'purpose' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        $verification = DB::transaction(function () use ($user, $data): array {
            $lockedUser = \App\Models\User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($lockedUser->transaction_pin_locked_until && $lockedUser->transaction_pin_locked_until->isFuture()) {
                return ['status' => 'locked', 'until' => $lockedUser->transaction_pin_locked_until->toIso8601String()];
            }
            if ($lockedUser->transaction_pin_locked_until && ! $lockedUser->transaction_pin_locked_until->isFuture()) {
                $lockedUser->forceFill(['transaction_pin_failed_attempts' => 0, 'transaction_pin_locked_until' => null])->save();
                $lockedUser->refresh();
            }

            if (! Hash::check($data['transaction_pin'], $lockedUser->transaction_pin_hash)) {
                $attempts = min(3, (int) $lockedUser->transaction_pin_failed_attempts + 1);
                $attributes = ['transaction_pin_failed_attempts' => $attempts];
                if ($attempts >= 3) {
                    $lockedUntil = now()->addMinutes(15);
                    $attributes['transaction_pin_locked_until'] = $lockedUntil;
                }
                $lockedUser->forceFill($attributes)->save();

                return $attempts >= 3
                    ? ['status' => 'locked', 'until' => $lockedUntil->toIso8601String()]
                    : ['status' => 'invalid'];
            }

            $lockedUser->forceFill(['transaction_pin_failed_attempts' => 0, 'transaction_pin_locked_until' => null])->save();
            return ['status' => 'ok'];
        }, 3);

        if ($verification['status'] === 'locked') {
            return response()->json([
                'message' => 'Transaction PIN is locked after three incorrect attempts. Try again after the lockout expires.',
                'locked_until' => $verification['until'],
            ], 429);
        }
        if ($verification['status'] !== 'ok') {
            return response()->json(['message' => 'Transaction PIN is incorrect. One more failed attempt will lock transactions for 15 minutes.'], 422);
        }

        $request->attributes->set('verified_transaction_pin', true);
        $response = $next($request);

        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            $body = json_decode($response->getContent(), true);
            $recordId = $body['data']['id'] ?? null;
            $business = $request->attributes->get('current_business');
            $path = $request->path();
            $table = str_ends_with($path, '/payments/deposits') ? 'deposit_intents'
                : (str_ends_with($path, '/payments/payouts') ? 'payout_requests' : null);
            if ($table && is_numeric($recordId) && $business) {
                $existing = DB::table($table)->where('business_id', $business->id)
                    ->where('id', (int) $recordId)->where('user_id', $user->id)->first();
                if ($existing && filled($existing->purpose) && $existing->purpose !== trim($data['purpose'])) {
                    return response()->json(['message' => 'This idempotency reference was already used with a different transaction purpose.'], 409);
                }
                if (! $existing || blank($existing->purpose)) {
                    DB::table($table)->where('business_id', $business->id)->where('id', (int) $recordId)
                        ->where('user_id', $user->id)->whereNull('purpose')
                        ->update(['purpose' => trim($data['purpose']), 'updated_at' => now()]);
                    DB::table('payment_operation_events')->insert([
                        'business_id' => $business->id,
                        'operation_type' => $table === 'deposit_intents' ? 'deposit' : 'payout',
                        'operation_id' => (int) $recordId,
                        'actor_user_id' => $user->id,
                        'event' => 'transaction_purpose_recorded',
                        'reason' => trim($data['purpose']),
                        'evidence' => json_encode(['transaction_pin_verified' => true, 'financial_posting' => false], JSON_THROW_ON_ERROR),
                        'created_at' => now(),
                    ]);
                }
            }
        }

        return $response;
    }
}
