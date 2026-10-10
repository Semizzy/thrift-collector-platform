<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayoutRequest extends Model
{
    protected $fillable = ['business_id', 'user_id', 'provider', 'method', 'status', 'amount_minor', 'currency_code', 'destination_ciphertext', 'client_reference', 'provider_reference', 'approved_by_user_id', 'paid_confirmed_by_user_id', 'decision_reason', 'approved_at', 'paid_confirmed_at'];
    protected function casts(): array { return ['amount_minor' => 'integer', 'destination_ciphertext' => 'encrypted', 'approved_at' => 'datetime', 'paid_confirmed_at' => 'datetime']; }
    protected $hidden = ['destination_ciphertext'];
}
