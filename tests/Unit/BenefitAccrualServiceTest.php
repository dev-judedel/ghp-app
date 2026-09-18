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
}
