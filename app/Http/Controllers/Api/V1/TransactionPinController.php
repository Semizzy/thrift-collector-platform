<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class TransactionPinController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        return response()->json(['data' => [
            'configured' => filled($user->transaction_pin_hash),
            'set_at' => $user->transaction_pin_set_at?->toISOString(),
            'locked_until' => $user->transaction_pin_locked_until?->isFuture() ? $user->transaction_pin_locked_until->toISOString() : null,
            'transaction_pin_length' => 4,
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Verify your email before setting or changing transaction security settings.'], 403);
        }
        $data = $request->validate([
            'pin' => ['required', 'digits:4'],
            'current_password' => ['required', 'string', 'max:255'],
            'current_pin' => ['nullable', 'digits:4'],
        ]);

        if (! Hash::check($data['current_password'], $user->password)) {
            return response()->json(['message' => 'Password confirmation failed.'], 422);
        }

        if (filled($user->transaction_pin_hash)) {
            if (! isset($data['current_pin']) || ! Hash::check($data['current_pin'], $user->transaction_pin_hash)) {
                return response()->json(['message' => 'Enter your current transaction PIN before changing it.'], 422);
            }
            if (Hash::check($data['pin'], $user->transaction_pin_hash)) {
                return response()->json(['message' => 'Choose a new PIN; it cannot reuse your current PIN.'], 422);
            }
        }

        if (in_array($data['pin'], ['0000', '1111', '2222', '3333', '4444', '5555', '6666', '7777', '8888', '9999', '1234', '4321'], true)) {
            return response()->json(['message' => 'Choose a less predictable four-digit PIN.'], 422);
        }

        $user->forceFill([
            'transaction_pin_hash' => Hash::make($data['pin']),
            'transaction_pin_set_at' => now(),
            'transaction_pin_failed_attempts' => 0,
            'transaction_pin_locked_until' => null,
        ])->save();

        return response()->json(['data' => ['configured' => true, 'set_at' => $user->transaction_pin_set_at?->toISOString()],
            'message' => 'Transaction PIN saved securely. It is never returned by the API.']);
    }
}
