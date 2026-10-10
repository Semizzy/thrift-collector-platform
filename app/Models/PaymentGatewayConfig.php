<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentGatewayConfig extends Model
{
    protected $fillable = ['business_id', 'provider', 'direction', 'status', 'public_config', 'credentials_ciphertext', 'updated_by_user_id'];
    protected function casts(): array { return ['public_config' => 'array', 'credentials_ciphertext' => 'encrypted']; }
}
