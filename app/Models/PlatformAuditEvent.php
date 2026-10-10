<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformAuditEvent extends Model
{
    public $timestamps = false;
    protected $fillable = ['actor_user_id', 'subject_user_id', 'action', 'metadata', 'ip_address', 'user_agent', 'created_at'];
    protected function casts(): array { return ['metadata' => 'array', 'created_at' => 'datetime']; }
    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'actor_user_id'); }
    public function subject(): BelongsTo { return $this->belongsTo(User::class, 'subject_user_id'); }
}
