<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContributionRecordAdjustment extends Model
{
    protected $fillable = ['contribution_record_id', 'thrift_group_id', 'adjustment_type', 'proposed_amount_minor', 'reason', 'status', 'requested_by_user_id', 'decided_by_user_id', 'decided_at', 'decision_note'];

    protected function casts(): array
    {
        return ['proposed_amount_minor' => 'integer', 'decided_at' => 'datetime'];
    }

    public function contribution(): BelongsTo { return $this->belongsTo(ContributionRecord::class, 'contribution_record_id'); }
    public function group(): BelongsTo { return $this->belongsTo(ThriftGroup::class, 'thrift_group_id'); }
    public function requester(): BelongsTo { return $this->belongsTo(User::class, 'requested_by_user_id'); }
    public function decider(): BelongsTo { return $this->belongsTo(User::class, 'decided_by_user_id'); }
}
