<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

#[Fillable([
    'member_id', 'benefit_period_id', 'or_no', 'or_date', 'or_amount', 'available_ghp', 'excess_amount', 'original_excess_amount', 'excess_covered_amount', 'hospital_name', 'description', 'remarks',
    'is_voided', 'voided_at', 'voided_reason', 'voided_by', 'submission_token',
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

    /**
     * ================================================================
     * CLAIM STATE belongs to the BENEFIT PERIOD RECORD (task.md §2.35).
     * ================================================================
     * A reimbursement is Claimed / locked exactly when the Benefit Period record
     * it was filed into is Claimed — one source of truth (benefit_periods.claimed_at),
     * never a copy on the reimbursement. (reimbursements.claimed_at / claimed_by /
     * reverted_at / reverted_by are a retired per-reimbursement design: the columns
     * stay as history and nothing reads or writes them.)
     *
     *   Filed     its record is open (Unclaimed)
     *   Claimed   its record is Claimed — finalized and LOCKED: it can't be edited,
     *             voided or un-voided, and later GHP increases never cover its excess
     *   Reverted  its record was Claimed and has been reverted (Unclaimed again)
     *
     * A reimbursement with no record (legacy / backdated into a cycle with no
     * generated record) is simply Filed and unlocked.
     */
    public function isClaimed(): bool
    {
        return $this->benefitPeriod?->isClaimed() ?? false;
    }

    public function isReverted(): bool
    {
        return $this->benefitPeriod?->isReverted() ?? false;
    }

    /**
     * Claimed AND still an active (non-voided) claim: finalized, so it can't be
     * edited or voided and its excess is skipped by every later GHP increase
     * (BenefitAccrualService::applyFundIncreaseToExcess()).
     */
    public function isLocked(): bool
    {
        return ! $this->is_voided && $this->isClaimed();
    }

    /** Label: Voided / Claimed / Reverted / Filed. */
    public function recordStatus(): string
    {
        return match (true) {
            (bool) $this->is_voided => 'Voided',
            $this->isClaimed() => 'Claimed',
            $this->isReverted() => 'Reverted',
            default => 'Filed',
        };
    }

    /**
     * What THIS record drew from the GHP fund when it was filed: the part of the
     * reimbursement that fit inside the Available GHP at that moment. Read from
     * the filing-time snapshot (available_ghp + or_amount), so it never moves
     * once the record exists — a later accrual, adjustment or another filing
     * can't rewrite a Claimed record's history. NULL for a legacy row with no
     * snapshot (the view shows a dash rather than inventing a number).
     * Display only (task.md §2.33): never read by the GHP calculation, and the
     * records of one period never add up to more than the period's single fund
     * because each one's available_ghp already had the earlier records taken out.
     */
    public function recordUsed(): ?float
    {
        if ($this->available_ghp === null) {
            return null;
        }

        return round(min((float) $this->or_amount, (float) $this->available_ghp), 2);
    }

    /** What was left of the fund right after THIS record (filing-time snapshot, same rules as recordUsed()). */
    public function recordAvailableAfter(): ?float
    {
        if ($this->available_ghp === null) {
            return null;
        }

        return round(max((float) $this->available_ghp - (float) $this->or_amount, 0), 2);
    }

    /**
     * "Record #n" within this member's coverage year: the 1-based position of this
     * reimbursement among the member's reimbursements linked to the same benefit
     * period, in filing (id) order. Voided records keep their number so it never
     * shifts. Display only — nothing relies on it for logic.
     */
    public function recordNumber(): int
    {
        return 1 + static::query()
            ->where('member_id', $this->member_id)
            ->when(
                $this->benefit_period_id === null,
                fn ($q) => $q->whereNull('benefit_period_id'),
                fn ($q) => $q->where('benefit_period_id', $this->benefit_period_id),
            )
            ->where('id', '<', $this->id)
            ->count();
    }

    /**
     * Reimbursements whose Benefit Period record is NOT Claimed (or that have no
     * record) — the only ones a later GHP increase may cover the excess of. A
     * claimed record is finalized and locked (task.md §2.35).
     */
    public function scopeUnclaimed(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->whereNull('benefit_period_id')
                ->orWhereHas('benefitPeriod', fn (Builder $period) => $period->whereNull('claimed_at'));
        });
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

    /**
     * Which GHP amount adjustments covered part of this claim's excess (the
     * reversal ledger — see ExcessCoverage). Part of excess_covered_amount;
     * the rest (if any) came from a dependent-driven increase.
     */
    public function excessCoverages(): HasMany
    {
        return $this->hasMany(ExcessCoverage::class);
    }

    /** Amount of this claim's excess currently covered by (not reversed) GHP amount adjustments. */
    public function coveredByAdjustments(): float
    {
        return round((float) $this->excessCoverages->whereNull('reversed_at')->sum('amount'), 2);
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
