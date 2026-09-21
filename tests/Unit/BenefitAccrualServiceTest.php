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
 * (see BenefitAccrualService docblock). The reinterpreted per-month
 * cutoff rule is validated separately against real historical data via
 * `php artisan ghp:validate-accrual`, not with contrived fixtures here.
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

    public function test_month_counting_includes_enrollment_month_when_started_on_or_before_15th(): void
    {
        $months = $this->service->countAccruedMonths(
            Carbon::parse('2025-04-10'),
            Carbon::parse('2026-03-31'),
            Carbon::parse('2025-06-30')
        );

        // Apr, May, Jun = 3 months (enrolled on the 10th, counts from Apr)
        $this->assertSame(3, $months);
    }

    public function test_month_counting_excludes_enrollment_month_when_started_after_15th(): void
    {
        $months = $this->service->countAccruedMonths(
            Carbon::parse('2025-04-20'),
            Carbon::parse('2026-03-31'),
            Carbon::parse('2025-06-30')
        );

        // Started Apr 20 (after the 15th) -> April doesn't count.
        // May, Jun = 2 months.
        $this->assertSame(2, $months);
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
     * joining mid-year, after the 15th, so the enrollment month itself
     * doesn't count (see the per-month cutoff tests above). Base ₱3,600/yr
     * (₱300/mo) with no dependents, joined June 20, 2026 → April, May,
     * and June (the enrollment month, since day > 15) don't count — only
     * July through March (9 months) do: ₱300 × 9 = ₱2,700.
     */
    public function test_mid_year_enrollment_after_the_15th_prorates_to_nine_months(): void
    {
        $member = Member::factory()->create([
            'member_type' => Member::MEMBER_TYPE_EMPLOYEE,
            'deduction_start_date' => Carbon::parse('2026-06-20'),
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
            'deduction_start_date' => Carbon::parse('2026-06-20'),
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
