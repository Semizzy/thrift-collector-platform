<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentOperationEvent extends Model
{
    public $timestamps = false;
    protected $fillable = ['business_id', 'operation_type', 'operation_id', 'actor_user_id', 'event', 'reason', 'evidence', 'created_at'];
    protected function casts(): array { return ['evidence' => 'array', 'created_at' => 'datetime']; }
}
