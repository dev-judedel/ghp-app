<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// The tracking columns below (kind, increase_amount, excess_*, available_increase_amount,
// reverted_at/by) are deliberately NOT mass-assignable: they are only ever set by
// AmountAdjustmentService, never from a request.
#[Fillable([
    'member_id', 'old_amount', 'new_amount', 'reason',
    'request_reference', 'requested_at', 'recorded_by',
])]
class BenefitAmountAdjustment extends Model
{
    use HasFactory;

    public const KIND_ADJUSTMENT = 'adjustment';

    /** The history row written when an adjustment (or all of them) is reverted. */
    public const KIND_REVERT = 'revert';

    protected function casts(): array
    {
        return [
            'old_amount' => 'decimal:2',
            'new_amount' => 'decimal:2',
            'requested_at' => 'date',
            'increase_amount' => 'decimal:2',
            'outstanding_excess_before' => 'decimal:2',
            'excess_covered_amount' => 'decimal:2',
            'excess_remaining_after' => 'decimal:2',
            'available_increase_amount' => 'decimal:2',
            'reverted_at' => 'datetime',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** Per-reimbursement amounts this adjustment covered (the reversal ledger). */
    public function coverages(): HasMany
    {
        return $this->hasMany(ExcessCoverage::class, 'benefit_amount_adjustment_id');
    }

    public function revertedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reverted_by');
    }

    public function isAdjustment(): bool
    {
        return $this->kind === self::KIND_ADJUSTMENT;
    }

    public function isReverted(): bool
    {
        return $this->reverted_at !== null;
    }
}
