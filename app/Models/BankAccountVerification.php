<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BankAccountVerification extends Model
{
    protected $fillable = [
        'business_id', 'user_id', 'verification_provider', 'bank_code', 'bank_name',
        'account_number_ciphertext', 'account_name_ciphertext', 'status', 'verified_at', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'account_number_ciphertext' => 'encrypted',
            'account_name_ciphertext' => 'encrypted',
            'verified_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    protected $hidden = ['account_number_ciphertext', 'account_name_ciphertext'];
}
