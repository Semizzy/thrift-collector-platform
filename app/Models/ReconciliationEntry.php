<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReconciliationEntry extends Model
{
    protected $fillable = ['reconciliation_batch_id', 'source_transaction_id', 'external_reference', 'normalized_reference', 'amount_minor', 'currency_code', 'occurred_at', 'status', 'matched_contribution_record_id', 'row_fingerprint', 'source_data'];

    protected function casts(): array { return ['amount_minor' => 'integer', 'occurred_at' => 'datetime', 'source_data' => 'array']; }
    public function batch(): BelongsTo { return $this->belongsTo(ReconciliationBatch::class, 'reconciliation_batch_id'); }
    public function matchedContribution(): BelongsTo { return $this->belongsTo(ContributionRecord::class, 'matched_contribution_record_id'); }
}
