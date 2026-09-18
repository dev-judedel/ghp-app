<?php

namespace App\Services;

use App\Models\BenefitPeriod;
use App\Models\Member;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Reimplements the GHP benefit accrual calculation from the legacy
 * ghp_common_function.py (get_avail_ghp / update_ghp_of_members /
 * new_load_avail_used_ghp).
 *
 * IMPORTANT — this is NOT a literal line-by-line port. The legacy file
 * contains three separate, mutually-inconsistent implementations of this
 * calculation, and at least one clear bug: in get_avail_ghp(), the
 * "deduction day >= 15" cutoff check sits OUTSIDE the monthly accrual loop,
 * so it acts as a single all-or-nothing gate on the entire year rather than
 * a per-month rule — meaning a member who enrolled on the 14th of a month
 * would accrue nothing for the whole coverage year, while one who enrolled
 * on the 15th+ accrues normally. That reads as a coding mistake, not a
 * policy, so it is NOT reproduced here.
 *
 * What IS carried over faithfully (consistent across all three legacy
 * versions):
 *   - Coverage year boundaries: Employees Apr 1–Mar 31, Agents Jun 1–May 31
 *   - Monthly accrual rate = ghp_amount / 12
 *   - 10% carry-forward of the prior coverage year's ghp_amount, but ONLY
 *     if that prior year was entirely unused (used_amount == 0)
 *   - ghp_amount itself: 3600 base, 4200 if the member has at least one
 *     GHP-eligible dependent (see Dependent::isEligible)
 *   - "Used" is capped at the available fund balance: a reimbursement can
 *     be filed for more than the member has left (the receipt/OR amount is
 *     always recorded in full, unmodified, on the Reimbursement row itself
 *     — that's the audit trail), but the benefit period's ghp_used/
 *     ghp_available never reflect more than what the fund actually had.
 *     The uncapped total is available via calculate()['claimed'] if needed.
 *   - Voided reimbursements (Reimbursement::void()) are excluded from the
 *     "used" sum entirely — they never counted against the fund at all,
 *     as opposed to a capped claim which did count, just partially.
 *
 * What was reinterpreted (flagged, needs business-owner confirmation):
 *   - The "day >= 15" cutoff is applied PER MONTH here: a month counts
 *     toward accrual if enrollment fell on/before the 15th of that month
 *     (standard mid-month payroll convention), rather than as a single
 *     gate on the whole year. Validate this against real history using
 *     `php artisan ghp:validate-accrual` before trusting it in production.
 *
 * See migration analysis doc §3 for the original findings.
 */
class BenefitAccrualService
{
    private const BASE_GHP_AMOUNT = 3600.0;

    private const DEPENDENT_GHP_AMOUNT = 4200.0;

    /**
     * Returns [Carbon $from, Carbon $to] for the coverage year containing
     * $referenceDate. Employees: Apr 1–Mar 31. Agents: Jun 1–May 31.
     */
    public function coveragePeriod(int $memberType, CarbonInterface $referenceDate): array
    {
        $startMonth = $memberType === Member::MEMBER_TYPE_AGENT ? 6 : 4;

        $year = $referenceDate->month >= $startMonth
            ? $referenceDate->year
            : $referenceDate->year - 1;

        $from = Carbon::create($year, $startMonth, 1)->startOfDay();
        $to = $from->copy()->addYear()->subDay()->endOfDay();

        return [$from, $to];
    }

    /**
     * Mirrors check_update_dependents(): 4200 if the member has at least
     * one GHP-eligible dependent, 3600 otherwise.
     *
     * EXCEPTION: if members.ghp_amount_is_manual is true, the member's
     * stored ghp_amount is used as-is instead — this is how a manual
     * amount adjustment (see AmountAdjustmentController) actually sticks
     * through future benefit period generation, rather than being
     * silently recalculated back to 3600/4200 the next time dependents
     * change or "Generate benefit period" is clicked.
     */
    public function resolveGhpAmount(Member $member): float
    {
        if ($member->ghp_amount_is_manual) {
            return (float) $member->ghp_amount;
        }

        $hasEligibleDependent = $member->dependents->contains(
            fn ($dependent) => $dependent->is_eligible
        );

        return $hasEligibleDependent ? self::DEPENDENT_GHP_AMOUNT : self::BASE_GHP_AMOUNT;
    }

    /**
     * Mirrors the CORRECT of the two contradictory legacy implementations
     * (the one in get_ghp_with_contri, not the buggy standalone
     * get_ghp_contri which only returns 100 for exactly 1 dependent).
     * 0 dependents -> 75. 1+ dependents -> 100.
     */
    public function contributionTier(Member $member): int
    {
        return $member->dependents->isNotEmpty() ? 100 : 75;
    }

    /**
     * 10% of the prior coverage year's ghp_amount, but only if that year
     * was entirely unused. Returns 0 if there is no prior period, or if
     * the prior period had any usage.
     */
    public function carryForward(?BenefitPeriod $priorPeriod): float
    {
        if ($priorPeriod === null) {
            return 0.0;
        }

        if ((float) $priorPeriod->ghp_used > 0.0) {
            return 0.0;
        }

        return (float) $priorPeriod->ghp_amount * 0.10;
    }

    /**
     * Counts how many months, from $accrualStart through min($periodEnd, $asOf),
     * count toward accrual under the per-month "enrolled by the 15th" rule.
     * See class docblock — this is a reinterpretation of the legacy cutoff,
     * not a literal port. Validate with `ghp:validate-accrual`.
     */
    public function countAccruedMonths(CarbonInterface $accrualStart, CarbonInterface $periodEnd, CarbonInterface $asOf): int
    {
        $end = $asOf->lessThan($periodEnd) ? $asOf : $periodEnd;

        if ($accrualStart->greaterThan($end)) {
            return 0;
        }

        $months = 0;
        $cursor = $accrualStart->copy()->startOfMonth();

        while ($cursor->lte($end)) {
            $isEnrollmentMonth = $cursor->isSameMonth($accrualStart) && $cursor->isSameYear($accrualStart);
            $countsThisMonth = $isEnrollmentMonth ? $accrualStart->day <= 15 : true;

            if ($countsThisMonth) {
                $months++;
            }

            $cursor = $cursor->addMonthNoOverflow();
        }

        return $months;
    }

    /**
     * Full calculation for a member's coverage period, WITHOUT persisting.
     * Useful for validation/comparison against historical data.
     *
     * @return array{ghp_amount: float, months_accrued: int, accrued: float,
     *               carry_forward: float, used: float, available: float,
     *               from: CarbonInterface, to: CarbonInterface}
     */
    public function calculate(Member $member, ?CarbonInterface $asOf = null): array
    {
        $asOf = $asOf ?? Carbon::now();

        [$from, $to] = $this->coveragePeriod($member->member_type, $asOf);

        $ghpAmount = $this->resolveGhpAmount($member);

        $priorPeriod = $member->benefitPeriods()
            ->whereDate('to_date', $from->copy()->subDay())
            ->first();

        $carryForward = $this->carryForward($priorPeriod);

        $dedStart = $member->deduction_start_date ?? $from;
        $accrualStart = $dedStart->greaterThan($from) ? $dedStart : $from;

        $monthsAccrued = $this->countAccruedMonths($accrualStart, $to, $asOf);
        $monthlyRate = $ghpAmount / 12;
        $accrued = round($monthlyRate * $monthsAccrued, 2);

        $used = (float) $member->reimbursements()
            ->notVoided()
            ->whereBetween('or_date', [$from, $to])
            ->sum('or_amount');

        $fundBalance = $accrued + $carryForward;

        // The full claimed amount on each reimbursement is always kept as-is
        // (that's the actual receipt/OR value — audit trail, not adjustable
        // here). But what counts as "used" against the fund is capped at
        // whatever was actually available, so a claim that exceeds the
        // remaining balance still gets recorded in full on the
        // reimbursement itself, while the fund's available balance floors
        // at 0 instead of going negative.
        $cappedUsed = min($used, $fundBalance);
        $available = max(round($fundBalance - $cappedUsed, 2), 0.0);

        return [
            'ghp_amount' => $ghpAmount,
            'months_accrued' => $monthsAccrued,
            'accrued' => $accrued,
            'carry_forward' => round($carryForward, 2),
            'used' => round($cappedUsed, 2),
            'claimed' => round($used, 2),
            'available' => $available,
            'from' => $from,
            'to' => $to,
        ];
    }

    /**
     * Calculates and persists (or updates) the BenefitPeriod for a member's
     * current coverage year. Idempotent — safe to re-run.
     */
    public function accrue(Member $member, ?CarbonInterface $asOf = null): BenefitPeriod
    {
        $result = $this->calculate($member, $asOf);

        return BenefitPeriod::updateOrCreate(
            [
                'member_id' => $member->id,
                'from_date' => $result['from'],
                'to_date' => $result['to'],
            ],
            [
                'ghp_amount' => $result['ghp_amount'],
                'ghp_available' => $result['available'],
                'ghp_used' => $result['used'],
                'member_type' => $member->member_type,
                'division_id' => $member->division_id,
                'department_id' => $member->department_id,
            ]
        );
    }
}
