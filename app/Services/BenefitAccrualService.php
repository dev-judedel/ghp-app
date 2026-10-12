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
 *   - "Used" is capped at the available fund balance. Since the GHP
 *     Reimbursement Rule change, a reimbursement is NO LONGER blocked for being
 *     larger than the available balance (only for the member having no
 *     available balance at all — see ReimbursementController::
 *     assertHasAvailableBalance()), so this cap is now what keeps a large
 *     claim from over-drawing the fund: the reimbursement keeps its full OR
 *     amount, while what counts as GHP used stops at what the fund could
 *     cover and Available floors at 0, never negative. It also still protects
 *     the displayed balance against data that predates that rule or a manual
 *     BenefitPeriod correction (see BenefitPeriodController::update()). The
 *     uncapped total is available via calculate()['claimed'] if needed.
 *     Excess deductions (App\Models\ExcessDeduction) are historical tracking
 *     records only and are deliberately NOT read anywhere in this class.
 *     A reimbursement's own excess_covered_amount IS read (calculate()): when a
 *     dependent becomes eligible the fund grows, the growth is applied to the
 *     claim's outstanding excess (applyFundIncreaseToExcess()), and that covered
 *     part is reported separately instead of as additional "used". Available is
 *     computed exactly as before.
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
     * The standard annual GHP amount for a member with no eligible
     * dependent. Exposed so the Add Member form's default and the
     * "is this amount a manual override?" check at registration read the
     * same value the engine itself uses, instead of a second hardcoded 3600.
     */
    public function defaultGhpAmount(): float
    {
        return self::BASE_GHP_AMOUNT;
    }

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
     * $referenceDate.
     *
     * Pass a Member to honor that member's CONFIGURED cycle (see
     * configuredCoveragePeriod()); a member with no configured cycle — every
     * member that existed before the setup became editable, and any imported
     * one — falls back to the standard member-type cycle below, unchanged.
     * Pass a plain member-type int to ask for the standard cycle directly
     * (Employees: Apr 1–Mar 31. Agents: Jun 1–May 31) — the default the Add
     * Member form suggests, and what every pre-existing caller did.
     */
    public function coveragePeriod(int|Member $subject, CarbonInterface $referenceDate): array
    {
        if ($subject instanceof Member) {
            $configured = $this->configuredCoveragePeriod($subject, $referenceDate);

            if ($configured !== null) {
                return $configured;
            }

            $subject = $subject->member_type;
        }

        $startMonth = $subject === Member::MEMBER_TYPE_AGENT ? 6 : 4;

        $year = $referenceDate->month >= $startMonth
            ? $referenceDate->year
            : $referenceDate->year - 1;

        $from = Carbon::create($year, $startMonth, 1)->startOfDay();
        $to = $from->copy()->addYear()->subDay()->endOfDay();

        return [$from, $to];
    }

    /**
     * Suggested Coverage year / Apply Date / End Date for each member type
     * as of $asOf, keyed by member type — what the Add/Edit Member forms
     * start from. Derived from the standard cycle logic, never hardcoded
     * months; every value stays editable in the form.
     *
     * @return array<int, array{year: int, from: string, to: string}>
     */
    public function standardCycleDefaults(?CarbonInterface $asOf = null): array
    {
        $asOf = $asOf ?? Carbon::now();
        $defaults = [];

        foreach ([Member::MEMBER_TYPE_EMPLOYEE, Member::MEMBER_TYPE_AGENT] as $memberType) {
            [$from, $to] = $this->coveragePeriod($memberType, $asOf);

            $defaults[$memberType] = [
                'year' => $from->year,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ];
        }

        return $defaults;
    }

    /**
     * The member's own configured cycle containing $referenceDate, or null
     * when none is configured (members.coverage_end_date is NULL).
     *
     * A configured End Date fixes the cycle's anchor: every cycle is exactly
     * 12 months, the configured one starting on (End Date + 1 day − 1 year),
     * and every cycle before/after it is that start moved by whole years.
     * That is what lets the same setting cover April–March, January–December
     * or anything else, and roll forward year after year without any
     * hardcoded month. Consecutive cycles never overlap and never leave a gap.
     *
     * The Apply Date is NOT the cycle start: it is the day this member's
     * coverage begins inside the cycle (a member who joins in June is still
     * in the Apr–Mar cycle, prorated by Start Date — the existing rule).
     */
    public function configuredCoveragePeriod(Member $member, CarbonInterface $referenceDate): ?array
    {
        if ($member->coverage_end_date === null) {
            return null;
        }

        return $this->cycleFromAnchor($this->coverageAnchor($member->coverage_end_date), $referenceDate);
    }

    /**
     * First day of the 12-month cycle that ends on $endDate.
     * (Mar 31, 2027 -> Apr 1, 2026;  Dec 31, 2026 -> Jan 1, 2026.)
     */
    public function coverageAnchor(CarbonInterface $endDate): Carbon
    {
        return Carbon::instance($endDate)->startOfDay()->addDay()->subYearsNoOverflow(1);
    }

    /**
     * [$from, $to] of the anchor-aligned 12-month cycle containing $referenceDate.
     */
    private function cycleFromAnchor(Carbon $anchor, CarbonInterface $referenceDate): array
    {
        $ref = Carbon::instance($referenceDate)->startOfDay();

        // Cycle k starts at anchor + k years. Start from the calendar-year
        // difference and correct by at most a step or two either way.
        $k = $ref->year - $anchor->year;

        while ($anchor->copy()->addYearsNoOverflow($k)->greaterThan($ref)) {
            $k--;
        }

        while ($anchor->copy()->addYearsNoOverflow($k + 1)->lessThanOrEqualTo($ref)) {
            $k++;
        }

        return [
            $anchor->copy()->addYearsNoOverflow($k)->startOfDay(),
            $anchor->copy()->addYearsNoOverflow($k + 1)->subDay()->endOfDay(),
        ];
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
    public function resolveDependentEligibilityDate(int|Member $subject, CarbonInterface $dateAdded): Carbon
    {
        // Day after the containing cycle ends == the next cycle's first day,
        // for the standard member-type cycle (Apr 1 / Jun 1) and for any
        // configured one alike.
        [, $periodEnd] = $this->coveragePeriod($subject, $dateAdded);

        return $periodEnd->copy()->addDay()->startOfDay();
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

        [$from, $to] = $this->coveragePeriod($member, $referenceDate);

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

        [$from, $to] = $this->coveragePeriod($member, $asOf);

        $ghpAmount = $this->resolveGhpAmount($member, $asOf);

        // The prior cycle may now hold several benefit period RECORDS (task.md §2.35):
        // "entirely unused" has to be judged across all of them, never from whichever
        // single row a query happens to return first, or a cycle whose usage sits in
        // a later record would wrongly qualify for the carry-forward.
        $priorPeriod = BenefitPeriod::summarizeCycle(
            $member->benefitPeriods()->whereDate('to_date', $from->copy()->subDay())->get()
        );

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

        // Excess GHP that a dependent-driven increase of the fund has already
        // covered (see applyFundIncreaseToExcess()). That part of the fund was
        // set against the claim's excess — it is NOT new GHP usage — so it is
        // shown separately and taken out of "used". Available is unaffected:
        // fund - used - covered is the same number as fund - min(claimed, fund).
        $covered = (float) $member->reimbursements()
            ->notVoided()
            ->whereBetween('or_date', [$from, $to])
            ->sum('excess_covered_amount');
        $coveredApplied = min($covered, $cappedUsed);

        return [
            'ghp_amount' => $ghpAmount,
            'months_accrued' => $monthsAccrued,
            'accrued' => $accrued,
            'carry_forward' => round($carryForward, 2),
            'used' => round($cappedUsed - $coveredApplied, 2),
            'excess_covered' => round($coveredApplied, 2),
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
        [, $periodEnd] = $this->coveragePeriod($member, $orDate);

        $now = Carbon::now();
        $asOf = $now->lessThan($periodEnd) ? $now : $periodEnd->copy();

        return $this->calculate($member, $asOf);
    }

    /**
     * Calculates and persists the BenefitPeriod RECORD for a member's coverage
     * year. Idempotent — safe to re-run.
     *
     * A coverage cycle can hold several records (task.md §2.35). This method:
     *
     *  - creates record 1 when the cycle has no record yet (that is what "Generate
     *    benefit period" and the daily job are);
     *  - otherwise refreshes ONLY the cycle's open (Unclaimed) record. A Claimed
     *    record is finalized: its stored figures are never touched again, so
     *    filing, an adjustment or a dependent change can't rewrite history;
     *  - NEVER creates record 2, 3 ...: those are created only at the moment a
     *    reimbursement is actually filed (recordForFiling()), never as a side
     *    effect of a refresh. When every record is Claimed it changes nothing and
     *    returns the latest one.
     *
     * NO DOUBLE ENTITLEMENT. calculate() works on the CYCLE (the member's amount,
     * the months accrued and every reimbursement by OR date), so the cycle's fund
     * and Available are computed exactly once however many records exist. The open
     * record stores:
     *   ghp_amount     the cycle's entitlement (the same figure on every record)
     *   ghp_used       the cycle's used MINUS what the earlier (claimed) records
     *                  already hold — its own share, so the shares add up to the
     *                  cycle's used and nothing is counted twice
     *   ghp_available  the cycle's remaining balance after every record
     * With a single record there is nothing to subtract, so the stored figures are
     * exactly what they have always been.
     */
    public function accrue(Member $member, ?CarbonInterface $asOf = null): BenefitPeriod
    {
        $result = $this->calculate($member, $asOf);

        // Match on date-only strings, not the raw Carbon instances.
        // to_date is a DATE column, but coveragePeriod() builds $to
        // with ->endOfDay() (time = 23:59:59) for accurate "as of"
        // comparisons elsewhere (see countAccruedMonths()). Comparing that raw
        // value against MySQL's midnight-padded DATE never matched.
        $fromDate = $result['from']->toDateString();
        $toDate = $result['to']->toDateString();

        $records = BenefitPeriod::query()
            ->where('member_id', $member->id)
            ->whereDate('from_date', $fromDate)
            ->whereDate('to_date', $toDate)
            ->orderBy('record_no')
            ->orderBy('id')
            ->get();

        if ($records->isEmpty()) {
            return BenefitPeriod::create($this->recordAttributes($member, $result) + [
                'from_date' => $fromDate,
                'to_date' => $toDate,
                'record_no' => 1,
                'ghp_used' => $result['used'],
                'ghp_available' => $result['available'],
            ]);
        }

        $open = $records->last(fn (BenefitPeriod $record) => ! $record->isClaimed());

        if ($open === null) {
            // Every record is Claimed (finalized): nothing to refresh, nothing to create.
            return $records->last();
        }

        $earlierUsed = (float) $records->where('id', '!=', $open->id)->sum('ghp_used');

        $open->fill($this->recordAttributes($member, $result) + [
            'ghp_used' => max(round($result['used'] - $earlierUsed, 2), 0.0),
            'ghp_available' => $result['available'],
        ])->save();

        return $open;
    }

    /** Columns every record carries the same way (the entitlement and the labels). */
    private function recordAttributes(Member $member, array $result): array
    {
        return [
            'member_id' => $member->id,
            // Labels the record with the coverage year its cycle began in
            // (a configured cycle can start in any month, so this is
            // stored rather than assumed from the member type).
            'coverage_year' => $result['from']->year,
            'ghp_amount' => $result['ghp_amount'],
            'member_type' => $member->member_type,
            'division_id' => $member->division_id,
            'department_id' => $member->department_id,
        ];
    }

    /**
     * The Benefit Period record a reimbursement with this OR date is filed INTO
     * (task.md §2.35). Call it inside the filing transaction with the member row
     * already locked: the member lock serializes every filing, claim and revert
     * for that member, and the records are locked here too.
     *
     *  - an OPEN (Unclaimed) record exists for the cycle -> that one: any number
     *    of reimbursements go into the same record until it is claimed;
     *  - the cycle has records but ALL are Claimed -> a NEW record for the same
     *    cycle is created now (record_no + 1, same dates and coverage year).
     *    It exists only because a reimbursement is being filed right now: it is
     *    never created empty, in advance, or by a refresh;
     *  - the cycle has no record yet -> the current cycle gets its first record
     *    (what Generate would have made); a past cycle gets none (returns null,
     *    the reimbursement stays unlinked rather than fabricating history).
     *
     * The new record's entitlement is the cycle's, never a fresh annual grant:
     * its Available is the cycle's remaining balance (earlier records' usage is
     * already in it) and its Used starts at 0 until the reimbursement is counted.
     */
    public function recordForFiling(Member $member, CarbonInterface $orDate): ?BenefitPeriod
    {
        [$from, $to] = $this->coveragePeriod($member, $orDate);

        $records = BenefitPeriod::query()
            ->where('member_id', $member->id)
            ->whereDate('from_date', $from->toDateString())
            ->whereDate('to_date', $to->toDateString())
            ->orderBy('record_no')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $now = Carbon::now();

        if ($records->isEmpty()) {
            [$currentFrom] = $this->coveragePeriod($member, $now);

            return $from->isSameDay($currentFrom) ? $this->accrue($member, $now) : null;
        }

        $open = $records->last(fn (BenefitPeriod $record) => ! $record->isClaimed());

        if ($open !== null) {
            return $open;
        }

        $asOf = $now->lessThan($to) ? $now : $to->copy();
        $result = $this->calculate($member, $asOf);

        return BenefitPeriod::create($this->recordAttributes($member, $result) + [
            'from_date' => $from->toDateString(),
            'to_date' => $to->toDateString(),
            'record_no' => $records->last()->record_no + 1,
            'ghp_used' => 0,
            'ghp_available' => $result['available'],
        ]);
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
        [$from, $to] = $this->coveragePeriod($member, $asOf ?? Carbon::now());

        $exists = $member->benefitPeriods()
            ->whereDate('from_date', $from->toDateString())
            ->whereDate('to_date', $to->toDateString())
            ->exists();

        return $exists ? $this->accrue($member, $asOf) : null;
    }

    /**
     * The actual INCREASE in a period's fund caused by moving its STORED
     * ghp_amount to member.ghp_amount's CURRENT value — same months
     * rendered, priced at the new rate instead of the period's stored one
     * (e.g. 3 x 350 - 3 x 300 = 150). This is the one formula for "what did
     * this change actually add to the fund", shared by a dependent-driven
     * refresh (refreshCurrentPeriodIfAmountChanged(), new rate =
     * resolveGhpAmount()) and a GHP amount adjustment
     * (AmountAdjustmentService::apply(), new rate = the admin-typed
     * override) — deliberately NOT new_amount - old_amount, which would be
     * the whole year's difference regardless of how many months are left
     * to accrue it over.
     */
    public function fundIncrease(Member $member, BenefitPeriod $period, CarbonInterface $asOf): float
    {
        $after = $this->calculate($member, $asOf);
        $fundBefore = round(((float) $period->ghp_amount / 12) * $after['months_accrued'], 2) + (float) $after['carry_forward'];
        $fundAfter = (float) $after['accrued'] + (float) $after['carry_forward'];

        return round($fundAfter - $fundBefore, 2);
    }

    /**
     * Brings the current cycle's ALREADY-GENERATED BenefitPeriod in line
     * with dependent eligibility, but only when eligibility has actually
     * changed the GHP amount (stored period amount != what resolveGhpAmount()
     * now says). Never creates a period.
     *
     * This is how a dependent becoming eligible (Immediate Eligibility, or a
     * dependent removed/edited so it no longer qualifies) shows up in the
     * Benefit balance card straight away. It does NOT add a stored
     * "+300" delta: accrue() recomputes ghp_amount / 12 x months rendered
     * from the member's actual records (dependents, reimbursements, prior
     * period), so
     *   3,600 -> 4,200 with 6 months rendered  =>  1,800 -> 2,100
     * falls out of the existing formula, existing deductions are preserved
     * ("used" is always re-summed from reimbursements), and running it
     * again is a no-op: the amounts already match, so it returns null and
     * writes nothing. That is the duplicate-adjustment guard.
     *
     * Manual amount overrides (ghp_amount_is_manual) resolve to the stored
     * amount, so they never trigger this. A hand-corrected period whose
     * amount already matches is likewise left untouched.
     *
     * $source: forwarded to applyFundIncreaseToExcess() — pass the
     * BenefitAmountAdjustment this refresh is being done FOR when the
     * caller is AmountAdjustmentService::apply(), so each claim's covered
     * share is written to the excess_coverages ledger and can later be
     * reverted by exactly that adjustment. Every other caller (the
     * dependent-eligibility flow) leaves this null, unchanged from before —
     * a dependent-driven increase is not individually revertible the same
     * way, so it is applied straight to reimbursements.excess_covered_amount
     * with no ledger row, exactly as it always has been.
     *
     * @return BenefitPeriod|null the refreshed period, or null if nothing needed changing
     */
    public function refreshCurrentPeriodIfAmountChanged(Member $member, ?CarbonInterface $asOf = null, ?\App\Models\BenefitAmountAdjustment $source = null): ?BenefitPeriod
    {
        $asOf = $asOf ?? Carbon::now();

        [$from, $to] = $this->coveragePeriod($member, $asOf);

        // The cycle's OPEN record (the highest-numbered Unclaimed one), else its latest
        // claimed one. A claimed record is frozen at its old amount, so comparing
        // against it would report a "change" forever; the open record is refreshed
        // by accrue() and therefore always carries the amount last applied.
        $period = $member->benefitPeriods()
            ->whereDate('from_date', $from->toDateString())
            ->whereDate('to_date', $to->toDateString())
            ->orderByRaw('CASE WHEN claimed_at IS NULL THEN 0 ELSE 1 END')
            ->orderByDesc('record_no')
            ->first();

        if ($period === null) {
            return null;
        }

        // Every record of the cycle is Claimed (finalized): accrue() would change nothing, and
        // comparing against a frozen amount would report a "change" on every call. The next
        // reimbursement opens a new record from the cycle's current balance (recordForFiling()).
        if ($period->isClaimed()) {
            return null;
        }

        // Always evaluate against the dependents as they are in the DB now,
        // not a relation loaded before the change.
        $member->unsetRelation('dependents');
        $member->load('dependents');

        $resolved = $this->resolveGhpAmount($member, $asOf);

        if (abs((float) $period->ghp_amount - $resolved) < 0.005) {
            return null;
        }

        // Runs only when the amount really changed (guard above), so the same
        // increase can never be applied twice: the next call finds the amounts
        // already equal and returns before reaching this point.
        $increase = $this->fundIncrease($member, $period, $asOf);

        if ($increase > 0) {
            $this->applyFundIncreaseToExcess($member, $from, $to, $increase, $source);
        }

        return $this->accrue($member, $asOf);
    }

    /**
     * Applies an INCREASE of the benefit fund (a dependent becoming eligible)
     * toward the outstanding excess GHP of the member's active claims in the
     * coverage period, oldest claim first (OR date, then id), never more than
     * the increase itself and never more than each claim's remaining excess.
     *
     * The claim keeps its id and its full OR amount; only its excess figures
     * change: excess_covered_amount grows and excess_amount (the remaining)
     * shrinks. Voided claims are skipped. Whatever part of the increase is
     * not needed simply stays in Available GHP. It is NOT usage: calculate()
     * reports the covered part separately from "used".
     *
     * Call inside a transaction (recalculateBenefit() does). Returns the
     * amount applied.
     *
     * $source: when the increase comes from a GHP amount ADJUSTMENT (not a
     * dependent), pass it so each claim's share is also written to the
     * excess_coverages ledger. That ledger is what lets the adjustment be
     * reversed later by exactly what it covered. The calculation itself is the
     * same for both sources — there is deliberately one implementation.
     */
    public function applyFundIncreaseToExcess(Member $member, CarbonInterface $from, CarbonInterface $to, float $increase, ?\App\Models\BenefitAmountAdjustment $source = null): float
    {
        $left = round($increase, 2);
        $applied = 0.0;

        if ($left <= 0) {
            return 0.0;
        }

        // CLAIMED records are finalized and LOCKED (task.md §2.32): their excess is
        // never covered by a later GHP increase — neither a GHP amount adjustment
        // nor a dependent becoming eligible, because both come through here. Only
        // Filed / Reverted records are eligible. A reverted record is eligible again
        // for FUTURE increases only; nothing is re-covered retroactively.
        $claims = $member->reimbursements()
            ->notVoided()
            ->unclaimed()
            ->whereBetween('or_date', [$from, $to])
            ->where('excess_amount', '>', 0)
            ->orderBy('or_date')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($claims as $claim) {
            if ($left <= 0) {
                break;
            }

            $remaining = (float) $claim->excess_amount;
            $apply = round(min($left, $remaining), 2);

            $claim->update([
                // Keep the excess as it was first calculated, for older rows that predate the column.
                'original_excess_amount' => (float) $claim->original_excess_amount > 0 ? $claim->original_excess_amount : $remaining,
                'excess_covered_amount' => round((float) $claim->excess_covered_amount + $apply, 2),
                'excess_amount' => round($remaining - $apply, 2),
            ]);

            if ($source !== null && $apply > 0) {
                \App\Models\ExcessCoverage::create([
                    'member_id' => $member->id,
                    'reimbursement_id' => $claim->id,
                    'benefit_amount_adjustment_id' => $source->id,
                    'amount' => $apply,
                ]);
            }

            $left = round($left - $apply, 2);
            $applied = round($applied + $apply, 2);
        }

        return $applied;
    }

    /**
     * Why moving $member onto the coverage cycle that ends on $newEndDate
     * would collide with benefit periods ALREADY generated, or null if the
     * change is safe.
     *
     * Generated benefit history is never rewritten, re-dated or deleted by a
     * configuration change. So a change that would put the member on a cycle
     * calendar in which an existing period partly overlaps a cycle (rather
     * than matching it exactly) is refused with an explanation, instead of
     * leaving two overlapping periods that would count the same
     * reimbursements twice. Only the cycles the change actually touches are
     * checked — the first configured cycle and the one containing today — so
     * old, unrelated rows (e.g. historical or data-quality-corrupted ones)
     * can never block an edit.
     *
     * Leaving the End Date as it is (or setting the same one again) changes
     * nothing and is always safe. Apply Date, Start Date (deduction) and the
     * GHP amount do not move cycle boundaries and are not checked here.
     */
    public function coverageChangeConflict(Member $member, ?CarbonInterface $newEndDate): ?string
    {
        if ($newEndDate === null) {
            return null;
        }

        if ($member->coverage_end_date !== null && $member->coverage_end_date->isSameDay($newEndDate)) {
            return null;
        }

        $anchor = $this->coverageAnchor($newEndDate);

        $touched = [
            $this->cycleFromAnchor($anchor, $anchor),
            $this->cycleFromAnchor($anchor, Carbon::now()),
        ];

        foreach ($member->benefitPeriods()->get() as $period) {
            foreach ($touched as [$from, $to]) {
                $overlaps = $period->from_date->lessThanOrEqualTo($to) && $period->to_date->greaterThanOrEqualTo($from);
                $identical = $period->from_date->isSameDay($from) && $period->to_date->isSameDay($to);

                if ($overlaps && ! $identical) {
                    return sprintf(
                        'The benefit period %s – %s is already generated for this member and would overlap the new coverage cycle (%s – %s). Generated benefit history is never rewritten or deleted, so this coverage period can\'t be applied.',
                        $period->from_date->format('M d, Y'),
                        $period->to_date->format('M d, Y'),
                        $from->format('M d, Y'),
                        $to->format('M d, Y'),
                    );
                }
            }
        }

        return null;
    }
}
