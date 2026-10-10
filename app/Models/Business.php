<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Business extends Model
{
    protected $fillable = ['name', 'slug', 'country_code', 'status', 'created_by_user_id', 'settings'];

    protected function casts(): array
    {
        return ['settings' => 'array'];
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'country_code', 'code');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(BusinessMembership::class);
    }

    public function membershipInvitations(): HasMany
    {
        return $this->hasMany(BusinessMembershipInvitation::class);
    }

    public function auditEvents(): HasMany
    {
        return $this->hasMany(BusinessAuditEvent::class);
    }
}
