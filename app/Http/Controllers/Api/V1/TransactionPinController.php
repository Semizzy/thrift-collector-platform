<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

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

    public function sendChallenge(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Verify your email before changing transaction security settings.'], 403);
        }

        $recent = DB::table('transaction_pin_challenges')->where('user_id', $user->id)
            ->where('created_at', '>=', now()->subMinutes(15))->count();
        if ($recent >= 3) {
            return response()->json(['message' => 'Too many verification codes requested. Wait 15 minutes before trying again.'], 429);
        }

        $code = (string) random_int(100000, 999999);
        $id = DB::table('transaction_pin_challenges')->insertGetId([
            'user_id' => $user->id, 'code_hash' => Hash::make($code), 'attempts' => 0,
            'expires_at' => now()->addMinutes(10), 'created_at' => now(), 'updated_at' => now(),
        ]);
        try {
            Mail::raw("Your transaction PIN verification code is {$code}. It expires in 10 minutes. Do not share it.", function ($message) use ($user): void {
                $message->to($user->email)->subject('Transaction PIN verification code');
            });
        } catch (\Throwable $exception) {
            DB::table('transaction_pin_challenges')->where('id', $id)->update(['consumed_at' => now(), 'updated_at' => now()]);
            report($exception);
            return response()->json(['message' => 'Verification email could not be sent. Check email delivery settings.'], 503);
        }

        return response()->json(['message' => 'A verification code was sent to your verified email. It expires in 10 minutes.']);
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
            'otp_code' => ['required', 'digits:6'],
        ]);

        if (! Hash::check($data['current_password'], $user->password)) {
            return response()->json(['message' => 'Password confirmation failed.'], 422);
        }

        $challenge = DB::table('transaction_pin_challenges')->where('user_id', $user->id)
            ->whereNull('consumed_at')->where('expires_at', '>', now())
            ->where('attempts', '<', 5)->orderByDesc('id')->first();
        if (! $challenge) {
            return response()->json(['message' => 'Request a fresh email verification code before changing your PIN.'], 422);
        }
        if (! Hash::check($data['otp_code'], $challenge->code_hash)) {
            $attempts = (int) $challenge->attempts + 1;
            DB::table('transaction_pin_challenges')->where('id', $challenge->id)->update([
                'attempts' => $attempts, 'consumed_at' => $attempts >= 5 ? now() : null, 'updated_at' => now(),
            ]);
            return response()->json(['message' => 'The email verification code is incorrect or expired.'], 422);
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

        $consumed = DB::table('transaction_pin_challenges')->where('id', $challenge->id)
            ->whereNull('consumed_at')->where('expires_at', '>', now())->where('attempts', '<', 5)
            ->update(['consumed_at' => now(), 'updated_at' => now()]);
        if ($consumed !== 1) {
            return response()->json(['message' => 'This verification code has already been used or expired. Request a new code.'], 422);
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
