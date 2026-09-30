<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

#[Fillable([
    'member_id', 'benefit_period_id', 'or_no', 'or_date', 'or_amount', 'available_ghp', 'excess_amount', 'original_excess_amount', 'excess_covered_amount', 'hospital_name', 'description', 'remarks',
    'is_voided', 'voided_at', 'voided_reason', 'voided_by',
])]
class Reimbursement extends Model
{
    use HasFactory, SoftDeletes, LogsActivity;

    protected function casts(): array
    {
        return [
            'or_date' => 'date',
            'or_amount' => 'decimal:2',
            'available_ghp' => 'decimal:2',
            'excess_amount' => 'decimal:2',
            'original_excess_amount' => 'decimal:2',
            'excess_covered_amount' => 'decimal:2',
            'is_voided' => 'boolean',
            'voided_at' => 'datetime',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /**
     * The Coverage Year / Benefit Period this reimbursement's OR date
     * falls into (see ReimbursementController::linkToBenefitPeriod()).
     * Nullable — reimbursements filed before this link existed, or ones
     * backdated into a coverage year with no BenefitPeriod row yet, may
     * not have one.
     */
    public function benefitPeriod(): BelongsTo
    {
        return $this->belongsTo(BenefitPeriod::class);
    }

    /**
     * HISTORICAL only: an excess deduction that was recorded by hand before
     * excess GHP became automatic (see ExcessDeduction). New excess GHP lives
     * in this model's own excess_amount column. Kept so old records are never
     * lost; nothing creates these any more.
     */
    public function excessDeduction(): HasOne
    {
        return $this->hasOne(ExcessDeduction::class);
    }

    /**
     * True when this claim went past the Available GHP at filing time (the
     * automatically calculated excess GHP is greater than zero). Drives the
     * red marking. A voided claim is excluded like everywhere else — its
     * stored figure is kept untouched but it is no longer highlighted or
     * totalled. Tracking only: never part of GHP usage.
     */
    public function hasExcess(): bool
    {
        return ! $this->is_voided && (float) $this->excess_amount > 0;
    }

    /**
     * Part of this claim's excess GHP that a later dependent-driven increase
     * in the benefit fund has already covered (see BenefitAccrualService::
     * applyFundIncreaseToExcess()). excess_amount is what is STILL outstanding;
     * original_excess_amount is what it was when the claim was filed.
     */
    public function hasCoveredExcess(): bool
    {
        return (float) $this->excess_covered_amount > 0;
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function scopeNotVoided(Builder $query): Builder
    {
        return $query->where('is_voided', false);
    }

    public function void(string $reason, ?User $by = null): void
    {
        $this->update([
            'is_voided' => true,
            'voided_at' => now(),
            'voided_reason' => $reason,
            'voided_by' => $by?->id,
        ]);
    }

    public function unvoid(): void
    {
        $this->update([
            'is_voided' => false,
            'voided_at' => null,
            'voided_reason' => null,
            'voided_by' => null,
        ]);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('reimbursement')
            ->logOnly([
                'member_id', 'or_no', 'or_date', 'or_amount', 'excess_amount', 'excess_covered_amount', 'hospital_name',
                'is_voided', 'voided_reason',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
