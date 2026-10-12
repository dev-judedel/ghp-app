<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row = "this GHP amount adjustment covered ₱X of this reimbursement's
 * excess GHP". It is the ledger that makes an adjustment fully reversible:
 * reverting adjustment N restores exactly the not-yet-reversed rows that carry
 * adjustment N's id — never a figure rebuilt from current balances.
 *
 * Tracking only: nothing in the GHP calculation reads this table (the covered
 * amount lives on reimbursements.excess_covered_amount, the same column the
 * dependent increase uses; this table records WHICH source put it there).
 * Rows are never deleted; a reversal stamps reversed_at.
 */
class ExcessCoverage extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'reversed_at' => 'datetime',
        ];
    }

    public function reimbursement(): BelongsTo
    {
        return $this->belongsTo(Reimbursement::class)->withTrashed();
    }

    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(BenefitAmountAdjustment::class, 'benefit_amount_adjustment_id');
    }

    public function isReversed(): bool
    {
        return $this->reversed_at !== null;
    }
}
