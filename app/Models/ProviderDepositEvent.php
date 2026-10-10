<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProviderDepositEvent extends Model
{
    protected $fillable = [
        'business_id', 'user_id', 'user_deposit_account_id', 'provider', 'provider_transaction_id',
        'provider_reference', 'amount_minor', 'currency_code', 'status', 'payload_hash', 'safe_metadata',
        'occurred_at', 'reviewed_by_user_id', 'review_decision', 'review_reason', 'reviewed_at',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(UserDepositAccount::class, 'user_deposit_account_id');
    }

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer', 'safe_metadata' => 'array', 'occurred_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }
}
