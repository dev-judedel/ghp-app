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
 *   - "Used" is capped at the available fund balance as a defensive
 *     floor only — it should never actually bind in normal operation,
 *     since ReimbursementController now blocks any new reimbursement
 *     whose amount exceeds availableBalanceFor() BEFORE it's ever saved
 *     (see the GHP Reimbursement Rule note there). This cap stays in
 *     place to protect the displayed balance against ever going negative
 *     from data that predates that rule or from a manual BenefitPeriod
 *     correction (see BenefitPeriodController::update()) — it is not the
 *     primary gate anymore. The uncapped total is available via
 *     calculate()['claimed'] if needed.
 *   - Voided reimbursements (Reimbursement::void()) are excluded from the
 *     "used" sum entirely — they never counted against the fund at all,
 *     as opposed to a capped claim which did count, just partially.
 *
 * What was reinterpreted (SUPERSEDED — see below, no longer flagged as
 * needing confirmation as of the Sep 2026 Deduction Date rework):
 *   - Originally: the legacy "day >= 15" cutoff was applied PER MONTH
 *     (a month counted if enrollment fell on/before the 15th). That has
 *     been replaced entirely by an explicit, confirmed business rule: the
 *     member's start_date month NEVER counts, and the first deduction
 *     month is always the calendar month immediately following start_date
 *     (see resolveDeductionStartDate()) — regardless of which day of the
 *     month start_date falls on. This is no longer an assumption needing
 *     validation; it's how deduction_start_date is now computed, always.
 *
 * See migration analysis doc §3 for the original findings.
 */
class BenefitAccrualService
{
    private const BASE_GHP_AMOUNT = 3600.0;

    private const DEPENDENT_GHP_AMOUNT = 4200.0;

    /**
     * First-day-of-the-month-after-$startDate, per the Deduction Date
     * business rule: the member's start month is never deducted — the
     * first deduction always falls on the 1st of the following calendar
     * month, regardless of what day within the start month $startDate is.
     * Purely calendar-based, not cycle-aware on purpose — a member who
     * starts in the cycle's last month (e.g. March for Employees) correctly
     * gets a deduction_start_date that falls in the NEXT cycle (April),
     * which is exactly the intended "0 months this cycle" outcome.
     *
     * Accepts a plain string on purpose: $request->validated('start_date')
     * returns a raw string (Laravel's 'date' validation rule validates the
     * format, it doesn't cast the value), and Carbon::parse() handles
     * either a string or an existing Carbon/DateTime instance identically.
     */
    public function resolveDeductionStartDate(string|CarbonInterface $startDate): Carbon
    {
        return Carbon::parse($startDate)->startOfMonth()->addMonthNoOverflow();
    }

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
     *
     * $asOf gates each dependent through Dependent::isGhpEligibleAsOf() —
     * a dependent added mid-cycle (via DependentController::store(), which
     * sets eligibility_date to the START of the NEXT coverage cycle) does
     * NOT count until $asOf reaches that date. Defaults to now() when
     * omitted, which is what every pre-existing caller effectively did
     * before this parameter existed (dependent eligibility used to have
     * no time component at all).
     */
    public function resolveGhpAmount(Member $member, ?CarbonInterface $asOf = null): float
    {
        if ($member->ghp_amount_is_manual) {
            return (float) $member->ghp_amount;
        }

        $asOf = $asOf ?? Carbon::now();

        $hasEligibleDependent = $member->dependents->contains(
            fn ($dependent) => $dependent->isGhpEligibleAsOf($asOf)
        );

        return $hasEligibleDependent ? self::DEPENDENT_GHP_AMOUNT : self::BASE_GHP_AMOUNT;
    }

    /**
     * The first day of the coverage cycle AFTER the one containing
     * $dateAdded — this is when a dependent added mid-cycle becomes
     * eligible for the higher GHP amount (see Dependent::eligibility_date
     * / isGhpEligibleAsOf()). A dependent added on any day of cycle N is
     * pending for the rest of cycle N and eligible starting cycle N+1,
     * regardless of how early or late in cycle N they were added — there
     * is no partial-cycle credit, matching how ghp_amount itself is a
     * flat per-cycle rate rather than something accrued per dependent.
     */
    public function resolveDependentEligibilityDate(int $memberType, CarbonInterface $dateAdded): Carbon
    {
        [$periodStart] = $this->coveragePeriod($memberType, $dateAdded);

        return $periodStart->copy()->addYear();
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
     * Counts whole calendar months from $accrualStart through
     * min($periodEnd, $asOf), inclusive. $accrualStart is expected to
     * already be month-aligned (deduction_start_date is always the 1st of
     * a month — see resolveDeductionStartDate()), but this is written to
     * degrade gracefully for any day-of-month too (legacy records
     * imported before this field existed), simply by counting calendar
     * months spanned rather than doing day-level cutoff logic.
     */
    public function countAccruedMonths(CarbonInterface $accrualStart, CarbonInterface $periodEnd, CarbonInterface $asOf): int
    {
        $end = $asOf->lessThan($periodEnd) ? $asOf : $periodEnd;

        if ($accrualStart->greaterThan($end)) {
            return 0;
        }

        $start = $accrualStart->copy()->startOfMonth();
        $endMonth = $end->copy()->startOfMonth();

        return (int) $start->diffInMonths($endMonth) + 1;
    }

    /**
     * Full-cycle target amount for display ("Required GHP Amount" on the
     * member page / Benefit Setup) — NOT the same thing as calculate()
     * ['available'], which caps at today's date for reimbursement-gating
     * purposes. This is the member's total obligation for the WHOLE
     * remaining coverage cycle, independent of what today's date is —
     * computed by passing the cycle's own end date as the "as of" cutoff
     * instead of now(), so no capping happens.
     *
     * @return array{ghp_amount: float, applicable_months: int, monthly_rate: float,
     *               required_amount: float, from: CarbonInterface, to: CarbonInterface}
     */
    public function requiredAmountForCycle(Member $member, ?CarbonInterface $referenceDate = null): array
    {
        $referenceDate = $referenceDate ?? Carbon::now();

        [$from, $to] = $this->coveragePeriod($member->member_type, $referenceDate);

        // Evaluated as of the cycle's own END, not $referenceDate —
        // dependent eligibility (see resolveDependentEligibilityDate()) is
        // an all-or-nothing-per-cycle rule, so the "required for this
        // whole cycle" figure should reflect whatever will be true for the
        // ENTIRE cycle, not just whatever happens to be true on the day
        // someone is looking at this page.
        $ghpAmount = $this->resolveGhpAmount($member, $to);

        // Clamped to the CURRENT cycle's own start — without this, a
        // member's second/third/... cycle would keep counting months all
        // the way back to their original enrollment date instead of
        // correctly resetting to a full cycle. Only the member's very
        // first (enrollment) cycle should ever come up short of 12 months;
        // every cycle after that, they were already active before the
        // cycle began, so it's a full cycle. Mirrors the same clamp
        // calculate() already does for the real persisted balance.
        $dedStart = $member->deduction_start_date ?? $from;
        $accrualStart = $dedStart->greaterThan($from) ? $dedStart : $from;

        $applicableMonths = $this->countAccruedMonths($accrualStart, $to, $to);
        $monthlyRate = $ghpAmount / 12;
        $requiredAmount = round($monthlyRate * $applicableMonths, 2);

        return [
            'ghp_amount' => $ghpAmount,
            'applicable_months' => $applicableMonths,
            'monthly_rate' => round($monthlyRate, 2),
            'required_amount' => $requiredAmount,
            'from' => $from,
            'to' => $to,
        ];
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

        $ghpAmount = $this->resolveGhpAmount($member, $asOf);

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
     * Available balance for the coverage period that CONTAINS $orDate —
     * used to gate a reimbursement request against the correct coverage
     * year (Coverage Year History) rather than always against whichever
     * period happens to be "current" today. See ReimbursementController's
     * GHP Reimbursement Rule.
     *
     * Delegates to calculate(), just resolving the right "as of" cutoff
     * first: if $orDate's period is the current, still-running one, that's
     * min(now(), period end) — the same cutoff calculate() uses by default
     * — so an in-progress period's accrual stays correctly prorated by
     * month. If $orDate's period has already fully elapsed, the cutoff
     * becomes the period's own end date, so a past period's balance is
     * evaluated as a complete cycle rather than staying frozen at whatever
     * partial accrual it had on the day it ended.
     */
    public function availableBalanceFor(Member $member, CarbonInterface $orDate): array
    {
        [, $periodEnd] = $this->coveragePeriod($member->member_type, $orDate);

        $now = Carbon::now();
        $asOf = $now->lessThan($periodEnd) ? $now : $periodEnd->copy();

        return $this->calculate($member, $asOf);
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
                // Match on date-only strings, not the raw Carbon instances.
                // to_date is a DATE column, but coveragePeriod() builds $to
                // with ->endOfDay() (time = 23:59:59) for accurate "as of"
                // comparisons elsewhere (see countAccruedMonths()). Passing
                // that raw value here made updateOrCreate()'s lookup compare
                // '...23:59:59' against MySQL's midnight-padded DATE value,
                // never match, then hit the unique constraint on insert.
                'from_date' => $result['from']->toDateString(),
                'to_date' => $result['to']->toDateString(),
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

    /**
     * Refreshes the current cycle's BenefitPeriod ONLY if it has already
     * been generated — never creates one.
     *
     * accrue() is updateOrCreate(), so any "just refresh the balance"
     * caller that used it directly also silently GENERATED the period for a
     * member who didn't have one, which flipped the member page's Generate
     * button to disabled without anyone generating anything. Callers that
     * are only reacting to a change (e.g. a GHP amount adjustment) use this
     * instead, so generation stays an explicit action (Generate button /
     * bulk action / scheduled job) and the database's "does the current
     * cycle's period exist?" answer only changes when one is really
     * generated.
     */
    public function refreshCurrentPeriodIfGenerated(Member $member, ?CarbonInterface $asOf = null): ?BenefitPeriod
    {
        [$from, $to] = $this->coveragePeriod($member->member_type, $asOf ?? Carbon::now());

        $exists = $member->benefitPeriods()
            ->whereDate('from_date', $from->toDateString())
            ->whereDate('to_date', $to->toDateString())
            ->exists();

        return $exists ? $this->accrue($member, $asOf) : null;
    }
}
