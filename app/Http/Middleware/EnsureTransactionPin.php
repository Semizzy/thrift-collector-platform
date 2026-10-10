<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
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

        if ($user->transaction_pin_locked_until && $user->transaction_pin_locked_until->isFuture()) {
            return response()->json(['message' => 'Transaction PIN verification is temporarily locked after repeated failures. Try again later.'], 429);
        }

        $data = $request->validate(['transaction_pin' => ['required', 'digits:4']]);
        if (! Hash::check($data['transaction_pin'], $user->transaction_pin_hash)) {
            $attempts = (int) $user->transaction_pin_failed_attempts + 1;
            $user->forceFill([
                'transaction_pin_failed_attempts' => $attempts >= 5 ? 0 : $attempts,
                'transaction_pin_locked_until' => $attempts >= 5 ? now()->addMinutes(15) : null,
            ])->save();

            return response()->json([
                'message' => $attempts >= 5
                    ? 'Too many incorrect PIN attempts. Transactions are locked for 15 minutes.'
                    : 'Transaction PIN is incorrect.',
            ], 422);
        }

        $user->forceFill(['transaction_pin_failed_attempts' => 0, 'transaction_pin_locked_until' => null])->save();
        $request->attributes->set('verified_transaction_pin', true);

        return $next($request);
    }
}
