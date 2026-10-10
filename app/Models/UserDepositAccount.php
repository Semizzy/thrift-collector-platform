<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserDepositAccount extends Model
{
    protected $fillable = [
        'business_id', 'user_id', 'provider', 'provider_account_id', 'provider_customer_id',
        'bank_code', 'bank_name', 'account_number_ciphertext', 'account_number_hash',
        'account_name_ciphertext', 'currency_code', 'status', 'client_reference', 'assigned_at',
    ];

    protected function casts(): array
    {
        return [
            'account_number_ciphertext' => 'encrypted',
            'account_name_ciphertext' => 'encrypted',
            'assigned_at' => 'datetime',
            'last_recovery_at' => 'datetime',
            'recovery_attempts' => 'integer',
        ];
    }

    protected $hidden = ['account_number_ciphertext', 'account_number_hash', 'account_name_ciphertext'];
}
