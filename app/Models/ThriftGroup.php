<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ThriftGroup extends Model
{
    protected $fillable = ['business_id', 'name', 'description', 'currency_code', 'status', 'created_by_user_id'];

    public function business(): BelongsTo { return $this->belongsTo(Business::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by_user_id'); }
    public function memberships(): HasMany { return $this->hasMany(ThriftGroupMembership::class); }
    public function contributions(): HasMany { return $this->hasMany(ContributionRecord::class); }
}
