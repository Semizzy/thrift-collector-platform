<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReconciliationBatch extends Model
{
    protected $fillable = ['business_id', 'source', 'source_reference', 'currency_code', 'status', 'created_by_user_id', 'entry_count'];

    protected function casts(): array { return ['entry_count' => 'integer']; }
    public function business(): BelongsTo { return $this->belongsTo(Business::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by_user_id'); }
    public function entries(): HasMany { return $this->hasMany(ReconciliationEntry::class); }
}
