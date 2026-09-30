<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A recorded excess deduction: actual payroll deduction minus the required
 * monthly GHP for the benefit period (required 300, actual 500 -> excess 200).
 *
 * TRACKING / REPORTING ONLY. It is deliberately not a reimbursement, not a
 * GHP contribution and not a benefit usage, and nothing in
 * BenefitAccrualService reads this table — so it cannot change GHP usage,
 * the available balance, the GHP amount, the monthly GHP or the required
 * amount. Keep it that way: do not add it to any of those calculations.
 *
 * Audit trail: ExcessDeductionController writes explicit entries to the
 * activity log (on the member) when one is recorded or removed, so this model
 * intentionally does not use LogsActivity (it would double-log).
 */
#[Fillable([
    'member_id', 'benefit_period_id', 'reimbursement_id', 'deduction_month',
    'required_amount', 'actual_deduction', 'excess_amount', 'remarks', 'recorded_by',
])]
class ExcessDeduction extends Model
{
    protected function casts(): array
    {
        return [
            'deduction_month' => 'date',
            'required_amount' => 'decimal:2',
            'actual_deduction' => 'decimal:2',
            'excess_amount' => 'decimal:2',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function benefitPeriod(): BelongsTo
    {
        return $this->belongsTo(BenefitPeriod::class);
    }

    /**
     * The reimbursement record this excess deduction is associated with, if
     * one was chosen when recording it. Optional.
     */
    public function reimbursement(): BelongsTo
    {
        return $this->belongsTo(Reimbursement::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
