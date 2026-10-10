<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DepositIntent extends Model
{
    protected $fillable = ['business_id', 'user_id', 'provider', 'method', 'status', 'amount_minor', 'currency_code', 'client_reference', 'provider_reference', 'proof_path', 'reviewed_by_user_id', 'review_reason', 'reviewed_at'];
    protected function casts(): array { return ['amount_minor' => 'integer', 'reviewed_at' => 'datetime']; }
}
