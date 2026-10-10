<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContributionSchedule extends Model
{
    protected $fillable = ['thrift_group_id', 'name', 'amount_minor', 'currency_code', 'frequency', 'starts_on', 'ends_on', 'due_day_of_month', 'status', 'created_by_user_id'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'starts_on' => 'date', 'ends_on' => 'date', 'due_day_of_month' => 'integer'];
    }

    public function group(): BelongsTo { return $this->belongsTo(ThriftGroup::class, 'thrift_group_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by_user_id'); }
    public function cycles(): HasMany { return $this->hasMany(ContributionCycle::class, 'contribution_schedule_id'); }
}
