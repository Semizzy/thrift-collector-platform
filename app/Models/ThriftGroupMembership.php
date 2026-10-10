<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ThriftGroupMembership extends Model
{
    protected $fillable = ['thrift_group_id', 'user_id', 'role', 'status', 'added_by_user_id', 'joined_at'];
    protected function casts(): array { return ['joined_at' => 'datetime']; }
    public function group(): BelongsTo { return $this->belongsTo(ThriftGroup::class, 'thrift_group_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
