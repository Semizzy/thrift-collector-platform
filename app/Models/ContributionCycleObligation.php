<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContributionCycleObligation extends Model
{
    protected $fillable = ['contribution_cycle_id', 'user_id', 'expected_amount_minor', 'currency_code', 'status', 'contribution_record_id'];

    protected function casts(): array
    {
        return ['expected_amount_minor' => 'integer'];
    }

    public function cycle(): BelongsTo { return $this->belongsTo(ContributionCycle::class, 'contribution_cycle_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function contribution(): BelongsTo { return $this->belongsTo(ContributionRecord::class, 'contribution_record_id'); }
}
