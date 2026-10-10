<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProviderWebhookEvent extends Model
{
    protected $fillable = [
        'provider', 'event_key', 'business_id', 'event_type', 'provider_object_id',
        'status', 'payload_hash', 'safe_metadata', 'received_at', 'processed_at',
    ];

    protected function casts(): array
    {
        return ['safe_metadata' => 'array', 'received_at' => 'datetime', 'processed_at' => 'datetime'];
    }
}
