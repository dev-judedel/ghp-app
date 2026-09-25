<?php

namespace Tests\Unit;

use App\Models\Dependent;
use App\Models\Member;
use App\Services\BenefitAccrualService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * These tests cover only the parts of the accrual engine that are
 * unambiguous and consistent across all three legacy implementations
 * (see BenefitAccrualService docblock). The legacy per-month "day >= 15"
 * cutoff was superseded entirely by the Sep 2026 Deduction Date rework —
 * countAccruedMonths() is now purely calendar-month based and never
 * looks at day-of-month, so there is no cutoff rule left to validate
 * here or via a separate command; see BenefitAccrualService's class
 * docblock for the full history.
 */
class BenefitAccrualServiceTest extends TestCase
{
    use RefreshDatabase;

    private BenefitAccrualService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BenefitAccrualService;
    }

    public function test_employee_coverage_period_is_april_to_march(): void
    {
        [$from, $to] = $this->service->coveragePeriod(
            Member::MEMBER_TYPE_EMPLOYEE,
            Carbon::parse('2025-07-15')
        );

        $this->assertSame('2025-04-01', $from->toDateString());
        $this->assertSame('2026-03-31', $to->toDateString());
    }

    public function test_employee_coverage_period_before_april_uses_prior_year(): void
    {
        [$from, $to] = $this->service->coveragePeriod(
            Member::MEMBER_TYPE_EMPLOYEE,
            Carbon::parse('2026-02-10')
        );

        $this->assertSame('2025-04-01', $from->toDateString());
        $this->assertSame('2026-03-31', $to->toDateString());
    }

    public function test_agent_coverage_period_is_june_to_may(): void
    {
        [$from, $to] = $this->service->coveragePeriod(
            Member::MEMBER_TYPE_AGENT,
            Carbon::parse('2025-09-01')
        );

        $this->assertSame('2025-06-01', $from->toDateString());
        $this->assertSame('2026-05-31', $to->toDateString());
    }

    public function test_ghp_amount_is_base_when_no_eligible_dependents(): void
    {
        $member = Member::factory()->create();

        $this->assertSame(3600.0, $this->service->resolveGhpAmount($member->fresh(['dependents'])));
    }

    public function test_ghp_amount_is_higher_with_an_eligible_child_dependent(): void
    {
        $member = Member::factory()->create();

        Dependent::factory()->for($member)->create([
            'relation' => 'Son',
            'birthdate' => Carbon::now()->subYears(10),
        ]);

        $this->assertSame(4200.0, $this->service->resolveGhpAmount($member->fresh(['dependents'])));
    }

    public function test_ghp_amount_ignores_a_child_dependent_over_21(): void
    {
        $member = Member::factory()->create();

        Dependent::factory()->for($member)->create([
            'relation' => 'Daughter',
            'birthdate' => Carbon::now()->subYears(25),
        ]);

        $this->assertSame(3600.0, $this->service->resolveGhpAmount($member->fresh(['dependents'])));
    }

    public function test_ghp_amount_counts_a_spouse_regardless_of_age(): void
    {
        $member = Member::factory()->create();

        Dependent::factory()->for($member)->create([
            'relation' => 'Spouse',
            'birthdate' => Carbon::now()->subYears(60),
        ]);

        $this->assertSame(4200.0, $this->service->resolveGhpAmount($member->fresh(['dependents'])));
    }

    // ---- Dependent eligibility gating (added mid-cycle -> next cycle) ----

    /**
     * Primary worked example from the spec: cycle Apr 2026-Mar 2027,
     * dependent added June 2026 -> eligible starting Apr 1, 2027.
     */
    public function test_dependent_eligibility_date_is_the_start_of_the_next_cycle(): void
    {
        $eligibilityDate = $this->service->resolveDependentEligibilityDate(
            Member::MEMBER_TYPE_EMPLOYEE,
            Carbon::parse('2026-06-15')
        );

        $this->assertSame('2027-04-01', $eligibilityDate->toDateString());
    }

    /**
     * Edge case 2: a dependent added right at the start of a NEW period
     * still follows that new period's own eligibility rules -> pending
     * for the whole of that cycle, eligible only the cycle after.
     */
    public function test_dependent_added_at_the_start_of_a_new_cycle_is_pending_for_that_whole_cycle(): void
    {
        $eligibilityDate = $this->service->resolveDependentEligibilityDate(
            Member::MEMBER_TYPE_EMPLOYEE,
            Carbon::parse('2027-04-10')
        );

        $this->assertSame('2028-04-01', $eligibilityDate->toDateString());
    }

    public function test_agent_dependent_eligibility_date_follows_the_agent_jun_may_cycle(): void
    {
        $eligibilityDate = $this->service->resolveDependentEligibilityDate(
            Member::MEMBER_TYPE_AGENT,
            Carbon::parse('2026-09-01')
        );

        $this->assertSame('2027-06-01', $eligibilityDate->toDateString());
    }

    /**
     * A dependent with an eligibility_date in the future does not count
     * toward the higher GHP amount when evaluated before that date, but
     * does once evaluated on/after it -- this is the mechanism
     * DependentController::store() relies on (see resolveGhpAmount()'s
     * $asOf parameter).
     */
    public function test_ghp_amount_ignores_a_pending_dependent_until_its_eligibility_date(): void
    {
        $member = Member::factory()->create();

        Dependent::factory()->for($member)->create([
            'relation' => 'Spouse',
            'birthdate' => Carbon::now()->subYears(30),
            'date_added' => '2026-06-15',
            'eligibility_date' => '2027-04-01',
        ]);

        $member->load('dependents');

        $this->assertSame(3600.0, $this->service->resolveGhpAmount($member, Carbon::parse('2027-03-31')));
        $this->assertSame(4200.0, $this->service->resolveGhpAmount($member, Carbon::parse('2027-04-01')));
    }

    /**
     * Case 3: multiple dependents added mid-cycle all stay pending
     * together -- the amount never bumps twice, and never bumps early
     * just because more than one was added.
     */
    public function test_multiple_pending_dependents_do_not_double_count_or_bump_early(): void
    {
        $member = Member::factory()->create();

        Dependent::factory()->for($member)->create([
            'relation' => 'Son',
            'birthdate' => Carbon::now()->subYears(5),
            'date_added' => '2026-06-15',
            'eligibility_date' => '2027-04-01',
        ]);

        Dependent::factory()->for($member)->create([
            'relation' => 'Daughter',
            'birthdate' => Carbon::now()->subYears(3),
            'date_added' => '2026-08-01',
            'eligibility_date' => '2027-04-01',
        ]);

        $member->load('dependents');

        $this->assertSame(3600.0, $this->service->resolveGhpAmount($member, Carbon::parse('2027-03-31')));
        $this->assertSame(4200.0, $this->service->resolveGhpAmount($member, Carbon::parse('2027-04-01')));
    }

    /**
     * A dependent with no eligibility_date at all (every dependent that
     * predates this feature, or that was created directly rather than
     * through DependentController::store()) is never time-gated -- it
     * counts immediately, exactly like before this feature existed.
     */
    public function test_a_dependent_with_no_eligibility_date_is_never_time_gated(): void
    {
        $member = Member::factory()->create();

        Dependent::factory()->for($member)->create([
            'relation' => 'Spouse',
            'birthdate' => Carbon::now()->subYears(30),
            'date_added' => null,
            'eligibility_date' => null,
        ]);

        $member->load('dependents');

        $this->assertSame(4200.0, $this->service->resolveGhpAmount($member, Carbon::parse('2000-01-01')));
        $this->assertSame(4200.0, $this->service->resolveGhpAmount($member, Carbon::parse('2099-01-01')));
    }

    public function test_contribution_tier_is_75_with_no_dependents(): void
    {
        $member = Member::factory()->create();

        $this->assertSame(75, $this->service->contributionTier($member->fresh(['dependents'])));
    }

    public function test_contribution_tier_is_100_with_any_dependents(): void
    {
        $member = Member::factory()->create();

        Dependent::factory()->for($member)->create();

        $this->assertSame(100, $this->service->contributionTier($member->fresh(['dependents'])));
    }

    /**
     * countAccruedMonths() flattens $accrualStart to the start of its
     * month before counting, so April 10 and April 20 both count April
     * as a full month — there is no "before/after the 15th" distinction
     * anymore (see class docblock). Apr, May, Jun = 3 months either way.
     */
    public function test_month_counting_ignores_the_day_of_month_only_the_calendar_month_matters(): void
    {
        $monthsFromEarlyApril = $this->service->countAccruedMonths(
            Carbon::parse('2025-04-10'),
            Carbon::parse('2026-03-31'),
            Carbon::parse('2025-06-30')
        );

        $monthsFromLateApril = $this->service->countAccruedMonths(
            Carbon::parse('2025-04-20'),
            Carbon::parse('2026-03-31'),
            Carbon::parse('2025-06-30')
        );

        $this->assertSame(3, $monthsFromEarlyApril);
        $this->assertSame(3, $monthsFromLateApril);
    }

    public function test_month_counting_stops_at_as_of_date(): void
    {
        $months = $this->service->countAccruedMonths(
            Carbon::parse('2025-04-01'),
            Carbon::parse('2026-03-31'),
            Carbon::parse('2025-04-15')
        );

        // Only April has started by the as-of date.
        $this->assertSame(1, $months);
    }

    public function test_carry_forward_is_zero_when_no_prior_period(): void
    {
        $this->assertSame(0.0, $this->service->carryForward(null));
    }

    /**
     * Walks through the exact scenario described when the apply/deduction
     * date requirement was added: an Employee (Apr 1–Mar 31 coverage year)
     * joining mid-year. deduction_start_date is always the 1st of the
     * month AFTER start_date (see resolveDeductionStartDate() — never a
     * raw mid-month value; a member who started June 20, 2026 gets
     * deduction_start_date = 2026-07-01). Base ₱3,600/yr (₱300/mo) with
     * no dependents, deduction starting July 1 → April, May, and June
     * don't count — only July through March (9 months) do: ₱300 × 9 =
     * ₱2,700.
     */
    public function test_mid_year_enrollment_prorates_to_nine_months(): void
    {
        $member = Member::factory()->create([
            'member_type' => Member::MEMBER_TYPE_EMPLOYEE,
            'deduction_start_date' => Carbon::parse('2026-07-01'),
            'ghp_amount' => 3600,
            'ghp_amount_is_manual' => false,
        ]);

        $result = $this->service->calculate($member->fresh(['dependents', 'reimbursements', 'benefitPeriods']), Carbon::parse('2027-03-31'));

        $this->assertSame(3600.0, $result['ghp_amount']);
        $this->assertSame(9, $result['months_accrued']);
        $this->assertSame(2700.0, $result['accrued']);
    }

    /**
     * Same scenario, but the member has an eligible dependent — the base
     * amount used for proration should switch to ₱4,200/yr (₱350/mo)
     * automatically: ₱350 × 9 = ₱3,150.
     */
    public function test_mid_year_enrollment_with_a_dependent_prorates_from_the_higher_base(): void
    {
        $member = Member::factory()->create([
            'member_type' => Member::MEMBER_TYPE_EMPLOYEE,
            'deduction_start_date' => Carbon::parse('2026-07-01'),
            'ghp_amount' => 3600,
            'ghp_amount_is_manual' => false,
        ]);

        Dependent::factory()->for($member)->create([
            'relation' => 'Spouse',
            'birthdate' => Carbon::now()->subYears(30),
        ]);

        $result = $this->service->calculate($member->fresh(['dependents', 'reimbursements', 'benefitPeriods']), Carbon::parse('2027-03-31'));

        $this->assertSame(4200.0, $result['ghp_amount']);
        $this->assertSame(9, $result['months_accrued']);
        $this->assertSame(3150.0, $result['accrued']);
    }

    /**
     * Regression test for the accrual rate calculation — restored to the
     * project's flat-rate behavior 2026-09-24 per explicit request (see
     * task.md §2.17), after a brief period (§2.16) using a month-by-month
     * historical rate instead.
     *
     * Employee, cycle Apr 2026 - Mar 2027, deduction starting exactly at
     * the cycle start. No eligible dependent for the first three months
     * (Apr/May/Jun -> P300/mo = 900 total), then a dependent becomes
     * GHP-eligible starting July 1, 2026 (mirrors an Immediate Eligibility
     * grant mid-cycle -- the mechanism doesn't matter to this calculation,
     * only that isGhpEligibleAsOf() starts returning true from that date
     * on). Evaluated again three months later (asOf Sep 30, 2026;
     * Jul/Aug/Sep now also accrued):
     *
     * The restored flat-rate calculation multiplies ALL 6 elapsed months
     * by TODAY'S rate (350/mo) -- 6 x 350 = P2,100 -- including
     * April-June, which only ever actually accrued at 300/mo at the time.
     * This is the intended, current behavior; do not "fix" this back to a
     * month-by-month historical rate without an explicit request to do so.
     */
    public function test_dependent_becoming_eligible_mid_period_uses_the_current_flat_rate_for_the_whole_elapsed_span(): void
    {
        $member = Member::factory()->create([
            'member_type' => Member::MEMBER_TYPE_EMPLOYEE,
            'deduction_start_date' => Carbon::parse('2026-04-01'),
            'ghp_amount' => 3600,
            'ghp_amount_is_manual' => false,
        ]);

        // Snapshot BEFORE the dependent exists at all: three months in,
        // base rate only.
        $before = $this->service->calculate($member->fresh(['dependents', 'reimbursements', 'benefitPeriods']), Carbon::parse('2026-06-30'));
        $this->assertSame(3600.0, $before['ghp_amount']);
        $this->assertSame(3, $before['months_accrued']);
        $this->assertSame(900.0, $before['accrued']);
        $this->assertSame(900.0, $before['available']);

        // Dependent becomes GHP-eligible starting July 1 (e.g. granted
        // Immediate Eligibility on June 15, effective the next month).
        Dependent::factory()->for($member)->create([
            'relation' => 'Spouse',
            'birthdate' => Carbon::now()->subYears(30),
            'date_added' => '2026-06-15',
            'eligibility_date' => '2026-07-01',
            'immediate_eligibility_at' => Carbon::parse('2026-06-15'),
        ]);

        $after = $this->service->calculate($member->fresh(['dependents', 'reimbursements', 'benefitPeriods']), Carbon::parse('2026-09-30'));

        // Required/GHP amount as of today correctly reflects the
        // now-eligible dependent.
        $this->assertSame(4200.0, $after['ghp_amount']);
        $this->assertSame(6, $after['months_accrued']);

        // The restored flat-rate calculation: 350 x 6 = 2100 -- NOT the
        // month-by-month 300 x 3 + 350 x 3 = 1950 figure from the brief
        // §2.16 period.
        $this->assertSame(2100.0, $after['accrued']);
        $this->assertSame(2100.0, $after['available']);
    }

    /**
     * Same restored flat-rate behavior, exercised through the
     * reimbursement-facing entry point (availableBalanceFor(), what
     * ReimbursementController actually gates on) rather than calculate()
     * directly.
     */
    public function test_available_balance_for_reimbursement_uses_the_current_flat_rate_when_a_dependent_becomes_eligible_today(): void
    {
        $member = Member::factory()->create([
            'member_type' => Member::MEMBER_TYPE_EMPLOYEE,
            'deduction_start_date' => Carbon::parse('2026-04-01'),
            'ghp_amount' => 3600,
            'ghp_amount_is_manual' => false,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-06-30'));

        try {
            // Dependent becomes eligible TODAY (Immediate Eligibility),
            // partway through the three months already accrued at 300/mo.
            Dependent::factory()->for($member)->create([
                'relation' => 'Spouse',
                'birthdate' => Carbon::now()->subYears(30),
                'date_added' => '2026-06-30',
                'eligibility_date' => '2026-06-30',
                'immediate_eligibility_at' => Carbon::now(),
            ]);

            $balance = $this->service->availableBalanceFor(
                $member->fresh(['dependents', 'reimbursements', 'benefitPeriods']),
                Carbon::parse('2026-06-30')
            );

            // Apr, May, Jun all at the new 350/mo flat rate = 1050.
            $this->assertSame(1050.0, $balance['available']);
        } finally {
            Carbon::setTestNow();
        }
    }

    // ---- GHP amount is a FLAT 3,600/4,200 tier, never multiplied by
    // ---- dependent count. resolveGhpAmount() has always used
    // ---- Collection::contains() (a boolean "is at least one dependent
    // ---- eligible" check), never count() * 4200 -- these tests lock
    // ---- that in per an explicit request to verify/guard it. ----

    /**
     * Two SIMULTANEOUSLY eligible dependents still resolve to exactly
     * 4,200 -- not 4,200 x 2 = 8,400. Uses two dependents with no
     * eligibility_date (immediately eligible, same as legacy-imported /
     * factory-created records) so both are eligible at the same time,
     * unlike the existing "multiple PENDING dependents" test above, which
     * never has more than one eligible at once.
     */
    public function test_ghp_amount_with_two_simultaneously_eligible_dependents_is_still_4200_not_doubled(): void
    {
        $member = Member::factory()->create();

        Dependent::factory()->for($member)->create([
            'relation' => 'Spouse',
            'birthdate' => Carbon::now()->subYears(35),
        ]);

        Dependent::factory()->for($member)->create([
            'relation' => 'Son',
            'birthdate' => Carbon::now()->subYears(8),
        ]);

        $member->load('dependents');

        $this->assertSame(4200.0, $this->service->resolveGhpAmount($member));
        $this->assertNotEquals(8400.0, $this->service->resolveGhpAmount($member));
    }

    /**
     * Three, then five, simultaneously eligible dependents -- still
     * exactly 4,200, never 12,600 or 21,000.
     */
    public function test_ghp_amount_with_three_and_five_simultaneously_eligible_dependents_is_still_4200(): void
    {
        $member = Member::factory()->create();

        Dependent::factory()->for($member)->create(['relation' => 'Spouse', 'birthdate' => Carbon::now()->subYears(35)]);
        Dependent::factory()->for($member)->create(['relation' => 'Son', 'birthdate' => Carbon::now()->subYears(8)]);
        Dependent::factory()->for($member)->create(['relation' => 'Daughter', 'birthdate' => Carbon::now()->subYears(5)]);

        $member->load('dependents');
        $this->assertSame(4200.0, $this->service->resolveGhpAmount($member));
        $this->assertNotEquals(12600.0, $this->service->resolveGhpAmount($member));

        Dependent::factory()->for($member)->create(['relation' => 'Child', 'birthdate' => Carbon::now()->subYears(2)]);
        Dependent::factory()->for($member)->create(['relation' => 'Son', 'birthdate' => Carbon::now()->subYears(1)]);

        $member->load('dependents');
        $this->assertCount(5, $member->dependents);
        $this->assertSame(4200.0, $this->service->resolveGhpAmount($member));
        $this->assertNotEquals(21000.0, $this->service->resolveGhpAmount($member));
    }

    /**
     * Deterministic / no duplicate-increase on repeated evaluation:
     * calling resolveGhpAmount() (what every refresh, page view, and
     * "Generate benefit period" click ultimately relies on) five times in
     * a row for the same eligible-dependent state always returns the
     * exact same 4,200 -- it is a pure function of "is at least one
     * dependent eligible right now", not a running total that accumulates
     * on each call.
     */
    public function test_resolving_ghp_amount_repeatedly_is_deterministic_and_never_accumulates(): void
    {
        $member = Member::factory()->create();

        Dependent::factory()->for($member)->create(['relation' => 'Spouse', 'birthdate' => Carbon::now()->subYears(35)]);
        Dependent::factory()->for($member)->create(['relation' => 'Son', 'birthdate' => Carbon::now()->subYears(8)]);

        $member->load('dependents');

        $results = [];
        for ($i = 0; $i < 5; $i++) {
            $results[] = $this->service->resolveGhpAmount($member);
        }

        $this->assertSame([4200.0, 4200.0, 4200.0, 4200.0, 4200.0], $results);
    }

    /**
     * Removal/ineligibility: with two eligible dependents (4,200), removing
     * ONE still leaves one eligible dependent on file, so the amount stays
     * 4,200 -- it does not step down proportionally. Only once the LAST
     * eligible dependent is gone does it fall back to the base 3,600.
     */
    public function test_removing_one_of_two_eligible_dependents_keeps_4200_removing_the_last_reverts_to_3600(): void
    {
        $member = Member::factory()->create();

        $first = Dependent::factory()->for($member)->create(['relation' => 'Spouse', 'birthdate' => Carbon::now()->subYears(35)]);
        $second = Dependent::factory()->for($member)->create(['relation' => 'Son', 'birthdate' => Carbon::now()->subYears(8)]);

        $member->load('dependents');
        $this->assertSame(4200.0, $this->service->resolveGhpAmount($member));

        // Remove one of the two eligible dependents -- one still remains.
        $first->delete();
        $member->unsetRelation('dependents')->load('dependents');
        $this->assertCount(1, $member->dependents);
        $this->assertSame(4200.0, $this->service->resolveGhpAmount($member));

        // Remove the last remaining eligible dependent -- now none are
        // eligible, so the amount correctly falls back to the base rate.
        $second->delete();
        $member->unsetRelation('dependents')->load('dependents');
        $this->assertCount(0, $member->dependents);
        $this->assertSame(3600.0, $this->service->resolveGhpAmount($member));
    }

    /**
     * Removal/ineligibility via age-out rather than deletion: a
     * child-relation dependent who has turned 21 is no longer
     * GHP-eligible (Dependent::isEligible) -- with one still-eligible
     * spouse on file, the amount correctly stays 4,200 regardless.
     */
    public function test_a_dependent_aging_out_still_leaves_4200_if_another_eligible_dependent_remains(): void
    {
        $member = Member::factory()->create();

        Dependent::factory()->for($member)->create(['relation' => 'Spouse', 'birthdate' => Carbon::now()->subYears(35)]);
        Dependent::factory()->for($member)->create(['relation' => 'Son', 'birthdate' => Carbon::now()->subYears(22)]); // aged out

        $member->load('dependents');

        $this->assertSame(4200.0, $this->service->resolveGhpAmount($member));
    }

    // ---- Available GHP = Months Rendered x Applicable Monthly GHP,
    // ---- verifying the worked scenarios from the "Available GHP must
    // ---- be based on months rendered" request against calculate()
    // ---- end-to-end (not just resolveGhpAmount() in isolation). ----

    /**
     * Scenarios A-E: an Employee whose deduction starts exactly at the
     * cycle start (Apr 1, 2026), evaluated after 1 and after 3 rendered
     * months, with 0, 1, 2, and 5 simultaneously eligible dependents.
     * Available must always equal monthsRendered x applicableMonthlyRate
     * -- and the applicable rate must stay flat at 350/mo no matter how
     * many eligible dependents are on file (never 2x350, 5x350, etc.).
     */
    public function test_available_ghp_equals_months_rendered_times_applicable_monthly_rate_scenarios_a_through_e(): void
    {
        $singleMember = Member::factory()->create([
            'member_type' => Member::MEMBER_TYPE_EMPLOYEE,
            'deduction_start_date' => Carbon::parse('2026-04-01'),
            'ghp_amount' => 3600,
            'ghp_amount_is_manual' => false,
        ]);

        // Scenario A -- 1 month rendered, 0 dependents: 1 x 300 = 300.
        $scenarioA = $this->service->calculate($singleMember->fresh(['dependents', 'reimbursements', 'benefitPeriods']), Carbon::parse('2026-04-30'));
        $this->assertSame(3600.0, $scenarioA['ghp_amount']);
        $this->assertSame(1, $scenarioA['months_accrued']);
        $this->assertSame(300.0, $scenarioA['available']);

        // Scenario B -- 3 months rendered, 0 dependents: 3 x 300 = 900.
        $scenarioB = $this->service->calculate($singleMember->fresh(['dependents', 'reimbursements', 'benefitPeriods']), Carbon::parse('2026-06-30'));
        $this->assertSame(3600.0, $scenarioB['ghp_amount']);
        $this->assertSame(3, $scenarioB['months_accrued']);
        $this->assertSame(900.0, $scenarioB['available']);

        // Scenario C -- 3 months rendered, 1 eligible dependent:
        // 3 x 350 = 1050.
        $oneDependentMember = Member::factory()->create([
            'member_type' => Member::MEMBER_TYPE_EMPLOYEE,
            'deduction_start_date' => Carbon::parse('2026-04-01'),
            'ghp_amount' => 3600,
            'ghp_amount_is_manual' => false,
        ]);
        Dependent::factory()->for($oneDependentMember)->create(['relation' => 'Spouse', 'birthdate' => Carbon::now()->subYears(30)]);

        $scenarioC = $this->service->calculate($oneDependentMember->fresh(['dependents', 'reimbursements', 'benefitPeriods']), Carbon::parse('2026-06-30'));
        $this->assertSame(4200.0, $scenarioC['ghp_amount']);
        $this->assertSame(3, $scenarioC['months_accrued']);
        $this->assertSame(1050.0, $scenarioC['available']);

        // Scenario D -- 3 months rendered, 2 eligible dependents: still
        // 3 x 350 = 1050, NOT 3 x 700.
        $twoDependentsMember = Member::factory()->create([
            'member_type' => Member::MEMBER_TYPE_EMPLOYEE,
            'deduction_start_date' => Carbon::parse('2026-04-01'),
            'ghp_amount' => 3600,
            'ghp_amount_is_manual' => false,
        ]);
        Dependent::factory()->for($twoDependentsMember)->create(['relation' => 'Spouse', 'birthdate' => Carbon::now()->subYears(30)]);
        Dependent::factory()->for($twoDependentsMember)->create(['relation' => 'Son', 'birthdate' => Carbon::now()->subYears(8)]);

        $scenarioD = $this->service->calculate($twoDependentsMember->fresh(['dependents', 'reimbursements', 'benefitPeriods']), Carbon::parse('2026-06-30'));
        $this->assertSame(4200.0, $scenarioD['ghp_amount']);
        $this->assertSame(1050.0, $scenarioD['available']);

        // Scenario E -- 3 months rendered, 5 eligible dependents: still
        // 3 x 350 = 1050, NOT 3 x 1750.
        $fiveDependentsMember = Member::factory()->create([
            'member_type' => Member::MEMBER_TYPE_EMPLOYEE,
            'deduction_start_date' => Carbon::parse('2026-04-01'),
            'ghp_amount' => 3600,
            'ghp_amount_is_manual' => false,
        ]);
        foreach (range(1, 5) as $i) {
            Dependent::factory()->for($fiveDependentsMember)->create(['relation' => 'Child', 'birthdate' => Carbon::now()->subYears(2 + $i)]);
        }

        $scenarioE = $this->service->calculate($fiveDependentsMember->fresh(['dependents', 'reimbursements', 'benefitPeriods']), Carbon::parse('2026-06-30'));
        $this->assertSame(4200.0, $scenarioE['ghp_amount']);
        $this->assertCount(5, $fiveDependentsMember->dependents);
        $this->assertSame(1050.0, $scenarioE['available']);
    }

    /**
     * The exact "+P150" worked example: a member with 3 months already
     * rendered (900 Available at the base rate) who then gains an
     * eligible dependent, re-evaluated at that SAME point in time. Available
     * must become exactly 3 x 350 = 1050 -- an increase of exactly
     * 3 x (350 - 300) = 150, matching "months already rendered x rate
     * difference" from the spec, via the same single flat-rate formula
     * used everywhere else (no separate/duplicate calculation).
     */
    public function test_available_ghp_increases_by_exactly_150_when_a_dependent_becomes_eligible_after_3_months_rendered(): void
    {
        $member = Member::factory()->create([
            'member_type' => Member::MEMBER_TYPE_EMPLOYEE,
            'deduction_start_date' => Carbon::parse('2026-04-01'),
            'ghp_amount' => 3600,
            'ghp_amount_is_manual' => false,
        ]);

        $asOf = Carbon::parse('2026-06-30');

        $before = $this->service->calculate($member->fresh(['dependents', 'reimbursements', 'benefitPeriods']), $asOf);
        $this->assertSame(900.0, $before['available']);

        // Dependent becomes eligible with no time-gating (immediately
        // eligible as of the SAME $asOf used above).
        Dependent::factory()->for($member)->create(['relation' => 'Spouse', 'birthdate' => Carbon::now()->subYears(30)]);

        $after = $this->service->calculate($member->fresh(['dependents', 'reimbursements', 'benefitPeriods']), $asOf);
        $this->assertSame(1050.0, $after['available']);
        $this->assertSame(150.0, round($after['available'] - $before['available'], 2));
    }
}
