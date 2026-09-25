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
}
