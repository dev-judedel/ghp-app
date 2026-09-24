<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\CarbonInterface;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

#[Fillable(['member_id', 'name', 'relation', 'birthdate', 'date_added', 'eligibility_date', 'immediate_eligibility_at', 'immediate_eligibility_by'])]
class Dependent extends Model
{
    use HasFactory, LogsActivity;

    /**
     * Relation values that trigger the age-21 eligibility cutoff in the legacy
     * system. NOTE: the live data also contains 'Daugther'/'Dauther' (typos)
     * and 'Anak' (Tagalog for child), which the legacy code's exact-match
     * check silently treated as always-eligible regardless of age. Flagged
     * in the migration analysis doc §2.3 for a business-owner decision before
     * this list is finalized — don't assume the exact-match behavior is
     * intentional just because it's what the old code did.
     */
    private const CHILD_RELATIONS = ['Son', 'Daughter', 'Child'];

    protected function casts(): array
    {
        return [
            'birthdate' => 'date',
            'date_added' => 'date',
            'eligibility_date' => 'date',
            'immediate_eligibility_at' => 'datetime',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function immediateEligibilityBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'immediate_eligibility_by');
    }

    /**
     * Whether the administrator-controlled "Immediate Eligibility"
     * exception can still be applied: only while the dependent is
     * genuinely waiting on the normal Benefit Period rule ('pending').
     * Already-eligible, already-immediate and age-ineligible dependents
     * all return false.
     */
    public function canBeMadeImmediatelyEligible(): bool
    {
        return $this->eligibility_status === 'pending';
    }

    protected function age(): Attribute
    {
        return Attribute::get(fn () => $this->birthdate?->age);
    }

    /**
     * Mirrors the legacy eligibility rule: non-child dependents are always
     * eligible; child-relation dependents are eligible only under age 21.
     *
     * NOTE: this is the age/relation rule only — it says nothing about
     * *when* the dependent becomes eligible to actually raise the GHP
     * amount. See eligibility_date / isGhpEligibleAsOf() for that.
     */
    protected function isEligible(): Attribute
    {
        return Attribute::get(function () {
            if (! in_array($this->relation, self::CHILD_RELATIONS, true)) {
                return true;
            }

            return $this->age !== null && $this->age < 21;
        });
    }

    /**
     * Whether this dependent counts toward the higher (4,200) GHP amount
     * as of a given date — combines the existing age/relation rule with
     * the "added mid-cycle doesn't count until the next benefit period"
     * rule (see BenefitAccrualService::resolveDependentEligibilityDate()).
     *
     * A NULL eligibility_date means "not time-gated" — every dependent on
     * file before this feature existed, and any dependent created
     * directly (factories/tests/legacy import) rather than through
     * DependentController::store(), is always eligible per the age rule
     * alone, exactly as before. Only dependents added through the real
     * "Add dependent" form have an eligibility_date, and therefore only
     * those are gated by it.
     */
    public function isGhpEligibleAsOf(CarbonInterface $asOf): bool
    {
        if (! $this->is_eligible) {
            return false;
        }

        if ($this->eligibility_date === null) {
            return true;
        }

        return $asOf->greaterThanOrEqualTo($this->eligibility_date);
    }

    /**
     * Display-only status for the Dependents table: 'eligible',
     * 'immediate' (an administrator bypassed the normal waiting rule via
     * Immediate Eligibility), 'pending' (added this cycle, waiting for the
     * next one), or 'not_eligible' (fails the age/relation rule regardless
     * of timing).
     * Always evaluated as of today — for the GHP-amount-affecting
     * decision as of an arbitrary date, use isGhpEligibleAsOf() instead.
     */
    protected function eligibilityStatus(): Attribute
    {
        return Attribute::get(function () {
            if (! $this->is_eligible) {
                return 'not_eligible';
            }

            if ($this->eligibility_date !== null && now()->lessThan($this->eligibility_date)) {
                return 'pending';
            }

            if ($this->immediate_eligibility_at !== null) {
                return 'immediate';
            }

            return 'eligible';
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('dependent')
            ->logOnly(['member_id', 'name', 'relation', 'birthdate', 'eligibility_date'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
