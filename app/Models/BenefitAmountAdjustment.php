<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'member_id', 'old_amount', 'new_amount', 'reason',
    'request_reference', 'requested_at', 'recorded_by',
])]
class BenefitAmountAdjustment extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'old_amount' => 'decimal:2',
            'new_amount' => 'decimal:2',
            'requested_at' => 'date',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
