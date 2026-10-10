<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContributionCycle extends Model
{
    protected $fillable = ['contribution_schedule_id', 'thrift_group_id', 'due_on', 'expected_amount_minor', 'currency_code', 'status', 'created_by_user_id'];

    protected function casts(): array
    {
        return ['due_on' => 'date', 'expected_amount_minor' => 'integer'];
    }

    public function schedule(): BelongsTo { return $this->belongsTo(ContributionSchedule::class, 'contribution_schedule_id'); }
    public function group(): BelongsTo { return $this->belongsTo(ThriftGroup::class, 'thrift_group_id'); }
}
