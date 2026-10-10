<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\WalletLedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    public function show(Request $request, WalletLedgerService $ledger): JsonResponse
    {
        $business = $request->attributes->get('current_business');
        $data = $request->validate(['currency_code' => ['sometimes', 'string', 'size:3', 'regex:/^[A-Z]{3}$/']]);
        $currency = $data['currency_code'] ?? 'NGN';

        return response()->json(['data' => [
            'currency_code' => $currency,
            'balance_minor' => $ledger->balance((int) $business->id, (int) $request->user()->id, $currency),
            'withdrawals_enabled_for_live_execution' => false,
            'message' => 'Wallet balance reflects confirmed and reconciled savings less requested withdrawals. Live bank transfer execution remains disabled.',
        ]]);
    }

    public function entries(Request $request, WalletLedgerService $ledger): JsonResponse
    {
        $business = $request->attributes->get('current_business');
        $data = $request->validate([
            'currency_code' => ['sometimes', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $currency = $data['currency_code'] ?? 'NGN';

        return response()->json(['data' => $ledger->entries(
            (int) $business->id, (int) $request->user()->id, $currency, (int) ($data['limit'] ?? 50)
        )]);
    }
}
