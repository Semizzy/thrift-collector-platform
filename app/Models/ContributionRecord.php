<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContributionRecord extends Model
{
    protected $fillable = [
        'thrift_group_id', 'user_id', 'recorded_by_user_id', 'amount_minor', 'currency_code',
        'status', 'collection_method', 'reference', 'note', 'confirmed_by_user_id', 'confirmed_at',
    ];
    protected function casts(): array { return ['amount_minor' => 'integer', 'confirmed_at' => 'datetime']; }
    public function group(): BelongsTo { return $this->belongsTo(ThriftGroup::class, 'thrift_group_id'); }
    public function contributor(): BelongsTo { return $this->belongsTo(User::class, 'user_id'); }
    public function recorder(): BelongsTo { return $this->belongsTo(User::class, 'recorded_by_user_id'); }
    public function confirmer(): BelongsTo { return $this->belongsTo(User::class, 'confirmed_by_user_id'); }
}
