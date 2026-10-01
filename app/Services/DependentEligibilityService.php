<?php

namespace App\Services;

use App\Models\Dependent;
use App\Models\Member;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Single home for the two dependent-creation/eligibility actions that more
 * than one controller needs:
 *
 *   - addDependent(): the NORMAL path — date_added = today,
 *     eligibility_date = start of the next coverage cycle (the existing
 *     Benefit Period waiting rule, unchanged).
 *   - grantImmediate(): the administrator-controlled EXCEPTION — pulls that
 *     one dependent's eligibility_date forward to today.
 *
 * Neither generates, resets, reopens, voids or deletes a BenefitPeriod. The GHP
 * side effects are computed by the one existing engine
 * (BenefitAccrualService) — there is no second GHP calculation here:
 *   1. the members.ghp_amount sync (syncGhpAmount()), as always, and
 *   2. when that changes the amount for a period that is ALREADY generated,
 *      the current period's ghp_amount / available balance are recomputed
 *      from the member's records (recalculateBenefit()), so an eligible
 *      dependent shows up in the Benefit balance immediately. Recomputed,
 *      not incremented — running it twice changes nothing.
 */
class DependentEligibilityService
{
    public function __construct(private readonly BenefitAccrualService $accrual)
    {
    }

    /**
     * Creates a dependent under the normal eligibility rule.
     */
    public function addDependent(Member $member, array $attributes): Dependent
    {
        $today = Carbon::now();

        return $member->dependents()->create($attributes + [
            'date_added' => $today->toDateString(),
            'eligibility_date' => $this->accrual->resolveDependentEligibilityDate($member, $today)->toDateString(),
        ]);
    }

    /**
     * Applies Immediate Eligibility to one dependent.
     *
     * Re-validated here (under a row lock) rather than trusting the caller:
     * the dependent must belong to $member and must still be 'pending'
     * under the normal rule. Throws RuntimeException with a
     * human-readable message otherwise, so callers can surface it.
     */
    public function grantImmediate(Member $member, Dependent $dependent, ?User $by): Dependent
    {
        return DB::transaction(function () use ($member, $dependent, $by) {
            $locked = Dependent::whereKey($dependent->id)->lockForUpdate()->firstOrFail();

            if ($locked->member_id !== $member->id) {
                throw new RuntimeException('That dependent does not belong to this member.');
            }

            if ($locked->eligibility_status === 'immediate') {
                throw new RuntimeException("{$locked->name} was already granted immediate eligibility.");
            }

            if (! $locked->canBeMadeImmediatelyEligible()) {
                throw new RuntimeException(
                    "{$locked->name} is not waiting on the normal eligibility rule, so Immediate Eligibility does not apply."
                );
            }

            $now = Carbon::now();
            $normalEligibilityDate = $locked->eligibility_date;
            [$periodFrom, $periodTo] = $this->accrual->coveragePeriod($member, $now);

            // The model's own auto-log would record this as a bare
            // eligibility_date change. Suppress it and write ONE explicit
            // entry below that says what actually happened and why.
            $locked->disableLogging();
            $locked->update([
                'eligibility_date' => $now->toDateString(),
                'immediate_eligibility_at' => $now,
                'immediate_eligibility_by' => $by?->id,
            ]);
            $locked->enableLogging();

            activity('dependent')
                ->performedOn($locked)
                ->causedBy($by)
                ->withProperties([
                    'action' => 'Immediate Eligibility',
                    'dependent_id' => $locked->id,
                    'member_id' => $member->id,
                    'processed_by' => $by?->id,
                    'processed_at' => $now->toDateTimeString(),
                    'benefit_period' => $periodFrom->toDateString().' to '.$periodTo->toDateString(),
                    'normal_eligibility_date' => optional($normalEligibilityDate)->toDateString(),
                    'immediate_eligibility_date' => $now->toDateString(),
                ])
                ->log(sprintf(
                    'Immediate Eligibility granted to %s (%s) for the benefit period %s – %s; normal eligibility would have started %s',
                    $locked->name,
                    $locked->relation,
                    $periodFrom->format('M d, Y'),
                    $periodTo->format('M d, Y'),
                    $normalEligibilityDate ? $normalEligibilityDate->format('M d, Y') : 'n/a',
                ));

            $this->recalculateBenefit($member);

            return $locked->refresh();
        });
    }

    /**
     * Same purpose as DependentController::syncGhpAmount(): keep the
     * stored members.ghp_amount in step with what the ONE existing engine
     * says it should be. A manual override (ghp_amount_is_manual) is
     * returned unchanged by the engine, so this is a no-op for those.
     * Deliberately does NOT call BenefitAccrualService::accrue() — the
     * existing BenefitPeriod row is left exactly as it is.
     */
    public function syncGhpAmount(Member $member): void
    {
        $member->unsetRelation('dependents');
        $member->load('dependents');
        $member->update(['ghp_amount' => $this->accrual->resolveGhpAmount($member)]);
    }

    /**
     * Full backend recalculation after ANY dependent change (added, edited,
     * removed, made immediately eligible): sync members.ghp_amount, then
     * refresh the current cycle's generated period IF the eligible-dependent
     * outcome changed its GHP amount. Atomic, and idempotent (see
     * BenefitAccrualService::refreshCurrentPeriodIfAmountChanged()).
     *
     * A dependent that is still pending leaves the amount at the base rate,
     * so nothing is raised prematurely.
     */
    public function recalculateBenefit(Member $member): void
    {
        DB::transaction(function () use ($member) {
            $this->syncGhpAmount($member);
            $this->accrual->refreshCurrentPeriodIfAmountChanged($member);
        });
    }

    /**
     * Shown (page tooltip and backend rejection alike) when a dependent can't be removed.
     */
    public const REMOVAL_BLOCKED_MESSAGE = 'This dependent cannot be removed because the member has an active reimbursement.';

    /**
     * Whether the member has a reimbursement that blocks removing dependents.
     *
     * This project's only reimbursement states are Active and Voided (the
     * existing "cancel" action — Reimbursement::void()); there is no
     * Pending / Approved / Rejected workflow. So a claim that is ACTIVE or
     * filed is any one that is not voided (soft-deleted rows are excluded by
     * the model's default scope). A voided claim no longer counts against the
     * GHP fund and no longer blocks removal; if the member has several, one
     * qualifying claim is enough to block.
     */
    public function hasActiveReimbursement(Member $member): bool
    {
        return $member->reimbursements()->notVoided()->exists();
    }

    /**
     * The ONE guarded way to remove a dependent. Re-checks the rule on the
     * backend under the same member row lock ReimbursementController uses when
     * filing, so a claim filed at the same moment can't slip past the check.
     * Nothing is deleted or recalculated when removal is blocked.
     *
     * Throws RuntimeException(REMOVAL_BLOCKED_MESSAGE) when blocked; callers
     * surface the message. Any other code path that removes a dependent must
     * go through here.
     */
    public function removeDependent(Member $member, Dependent $dependent): void
    {
        DB::transaction(function () use ($member, $dependent) {
            Member::whereKey($member->id)->lockForUpdate()->firstOrFail();

            if ($this->hasActiveReimbursement($member)) {
                throw new RuntimeException(self::REMOVAL_BLOCKED_MESSAGE);
            }

            $dependent->delete();

            $this->recalculateBenefit($member);
        });
    }

    /**
     * Whether $member already has a dependent recorded as a spouse.
     * Relation is free text in this schema (legacy data), so compare
     * case-insensitively and ignoring surrounding whitespace.
     */
    public function hasSpouse(Member $member): bool
    {
        return $member->dependents()
            ->whereRaw('LOWER(TRIM(relation)) = ?', ['spouse'])
            ->exists();
    }
}
