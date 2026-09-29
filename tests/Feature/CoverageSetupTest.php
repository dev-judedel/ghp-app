<?php

namespace Tests\Feature;

use App\Models\BenefitPeriod;
use App\Models\Member;
use App\Models\User;
use App\Services\BenefitAccrualService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The editable GHP Benefit Setup (task.md §2.18): Coverage year, Apply date,
 * End date, Start date (deductions) and GHP amount are set when a member is
 * registered, and the engine follows the member's configured cycle instead of
 * a hardcoded Apr-Mar / Jun-May one. Members with NO configured cycle behave
 * exactly as before.
 *
 * The amount is the ANNUAL rate for a full 12-month cycle; the balance is
 * that amount / 12 x the months from the Start date (deductions) through the
 * End date — so the prorated Example 2 figure (P2,700) comes from a P3,600
 * amount, not from typing 2,700.
 */
class CoverageSetupTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'is_active' => true]);
    }

    private function payload(array $overrides = []): array
    {
        // Scenario 1 (default coverage period) unless overridden.
        return array_merge([
            'email' => 'juan@example.test',
            'member_type' => '0',
            'last_name' => 'Dela Cruz',
            'first_name' => 'Juan',
            'coverage_year' => 2026,
            'apply_date' => '2026-04-01',
            'coverage_end_date' => '2027-03-31',
            'start_date' => '2026-03-01',
            'deduction_start_date' => '2026-04-01',
            'ghp_amount' => 3600,
        ], $overrides);
    }

    private function register(array $overrides = []): Member
    {
        $this->actingAs($this->admin())
            ->post(route('members.store'), $this->payload($overrides))
            ->assertSessionHasNoErrors();

        return Member::where('email', $overrides['email'] ?? 'juan@example.test')->firstOrFail();
    }

    private function editPayload(Member $member, array $overrides = []): array
    {
        return array_merge([
            'code' => $member->code,
            'email' => $member->email,
            'member_type' => (string) $member->member_type,
            'last_name' => $member->last_name,
            'first_name' => $member->first_name,
            'is_active' => 1,
            'coverage_year' => $member->coverage_year,
            'apply_date' => $member->apply_date->toDateString(),
            'coverage_end_date' => optional($member->coverage_end_date)->toDateString(),
            'start_date' => $member->start_date->toDateString(),
            'deduction_start_date' => $member->deduction_start_date->toDateString(),
        ], $overrides);
    }

    // ---- Scenario 1: default coverage period ----

    public function test_scenario_1_default_period_saves_and_accrues_a_full_twelve_months(): void
    {
        $member = $this->register();

        $this->assertSame(2026, $member->coverage_year);
        $this->assertSame('2026-04-01', $member->apply_date->toDateString());
        $this->assertSame('2027-03-31', $member->coverage_end_date->toDateString());
        $this->assertSame('2026-04-01', $member->deduction_start_date->toDateString());
        $this->assertFalse($member->ghp_amount_is_manual);

        $service = app(BenefitAccrualService::class);

        $cycle = $service->requiredAmountForCycle($member, Carbon::parse('2026-06-15'));
        $this->assertSame('2026-04-01', $cycle['from']->toDateString());
        $this->assertSame('2027-03-31', $cycle['to']->toDateString());
        $this->assertSame(12, $cycle['applicable_months']);
        $this->assertSame(3600.0, $cycle['required_amount']);

        // Balance so far in June: Apr, May, Jun = 3 months x 300.
        $so_far = $service->calculate($member, Carbon::parse('2026-06-15'));
        $this->assertSame(3, $so_far['months_accrued']);
        $this->assertSame(900.0, $so_far['accrued']);
    }

    // ---- Scenario 2: member added during the coverage period ----

    public function test_scenario_2_mid_cycle_member_is_prorated_by_the_existing_rule(): void
    {
        $member = $this->register([
            'apply_date' => '2026-06-01',
            'start_date' => '2026-06-01',
            'deduction_start_date' => '2026-07-01',
        ]);

        // Apply date is NOT the cycle start: the member is still in Apr-Mar.
        $cycle = app(BenefitAccrualService::class)->requiredAmountForCycle($member, Carbon::parse('2026-08-01'));

        $this->assertSame('2026-04-01', $cycle['from']->toDateString());
        $this->assertSame('2027-03-31', $cycle['to']->toDateString());
        $this->assertSame(9, $cycle['applicable_months']);            // Jul 2026 - Mar 2027
        $this->assertSame(2700.0, $cycle['required_amount']);          // 3,600 / 12 x 9
    }

    public function test_the_amount_is_the_annual_rate_so_typing_2700_is_prorated_again_and_saved_as_manual(): void
    {
        $member = $this->register([
            'apply_date' => '2026-06-01',
            'start_date' => '2026-06-01',
            'deduction_start_date' => '2026-07-01',
            'ghp_amount' => 2700,
        ]);

        $this->assertTrue($member->ghp_amount_is_manual);
        $this->assertSame(2700.0, (float) $member->ghp_amount);

        $cycle = app(BenefitAccrualService::class)->requiredAmountForCycle($member, Carbon::parse('2026-08-01'));

        $this->assertSame(2025.0, $cycle['required_amount']);          // 2,700 / 12 x 9
    }

    public function test_a_blank_start_date_is_still_derived_from_the_member_start_date(): void
    {
        $member = $this->register([
            'apply_date' => '2026-06-01',
            'start_date' => '2026-06-20',
            'deduction_start_date' => '',
        ]);

        $this->assertSame('2026-07-01', $member->deduction_start_date->toDateString());
    }

    // ---- Scenario 3: custom (calendar-year) coverage period ----

    public function test_scenario_3_a_calendar_year_period_is_not_forced_into_april_to_march(): void
    {
        $member = $this->register([
            'apply_date' => '2026-01-01',
            'coverage_end_date' => '2026-12-31',
            'start_date' => '2026-01-01',
            'deduction_start_date' => '2026-02-01',
        ]);

        $service = app(BenefitAccrualService::class);

        [$from, $to] = $service->coveragePeriod($member, Carbon::parse('2026-06-15'));
        $this->assertSame('2026-01-01', $from->toDateString());
        $this->assertSame('2026-12-31', $to->toDateString());

        $cycle = $service->requiredAmountForCycle($member, Carbon::parse('2026-06-15'));
        $this->assertSame(11, $cycle['applicable_months']);            // Feb - Dec
        $this->assertSame(3300.0, $cycle['required_amount']);

        // ...and it rolls forward on the same calendar, no hardcoded month.
        [$nextFrom, $nextTo] = $service->coveragePeriod($member, Carbon::parse('2027-03-01'));
        $this->assertSame('2027-01-01', $nextFrom->toDateString());
        $this->assertSame('2027-12-31', $nextTo->toDateString());
    }

    public function test_a_configured_cycle_rolls_over_on_the_day_after_its_end_date(): void
    {
        $member = $this->register();
        $service = app(BenefitAccrualService::class);

        [$fromA, $toA] = $service->coveragePeriod($member, Carbon::parse('2027-03-31'));
        $this->assertSame(['2026-04-01', '2027-03-31'], [$fromA->toDateString(), $toA->toDateString()]);

        [$fromB, $toB] = $service->coveragePeriod($member, Carbon::parse('2027-04-01'));
        $this->assertSame(['2027-04-01', '2028-03-31'], [$fromB->toDateString(), $toB->toDateString()]);
    }

    public function test_members_without_a_configured_cycle_keep_the_standard_member_type_cycle(): void
    {
        $service = app(BenefitAccrualService::class);

        $employee = Member::factory()->create(['member_type' => Member::MEMBER_TYPE_EMPLOYEE, 'coverage_year' => null, 'coverage_end_date' => null]);
        $agent = Member::factory()->create(['member_type' => Member::MEMBER_TYPE_AGENT, 'coverage_year' => null, 'coverage_end_date' => null]);

        [$eFrom, $eTo] = $service->coveragePeriod($employee, Carbon::parse('2026-06-15'));
        [$aFrom, $aTo] = $service->coveragePeriod($agent, Carbon::parse('2026-06-15'));

        $this->assertSame(['2026-04-01', '2027-03-31'], [$eFrom->toDateString(), $eTo->toDateString()]);
        $this->assertSame(['2026-06-01', '2027-05-31'], [$aFrom->toDateString(), $aTo->toDateString()]);
    }

    // ---- Scenario 4: modified coverage period ----

    public function test_scenario_4_dates_can_be_edited_before_a_period_is_generated(): void
    {
        $member = $this->register();

        $this->actingAs($this->admin())
            ->put(route('members.update', $member), $this->editPayload($member, [
                'coverage_year' => 2027,
                'apply_date' => '2027-01-01',
                'coverage_end_date' => '2027-12-31',
                'deduction_start_date' => '2027-02-01',
            ]))
            ->assertSessionHasNoErrors();

        $member->refresh();

        $this->assertSame(2027, $member->coverage_year);
        $this->assertSame('2027-01-01', $member->apply_date->toDateString());
        $this->assertSame('2027-12-31', $member->coverage_end_date->toDateString());
        $this->assertSame('2027-02-01', $member->deduction_start_date->toDateString());
        $this->assertSame(1, Member::count());
    }

    public function test_changing_the_start_date_does_not_change_the_apply_or_end_date(): void
    {
        $member = $this->register();

        $this->actingAs($this->admin())
            ->put(route('members.update', $member), $this->editPayload($member, ['deduction_start_date' => '2026-07-01']))
            ->assertSessionHasNoErrors();

        $member->refresh();

        $this->assertSame('2026-07-01', $member->deduction_start_date->toDateString());
        $this->assertSame('2026-04-01', $member->apply_date->toDateString());
        $this->assertSame('2027-03-31', $member->coverage_end_date->toDateString());
    }

    public function test_a_change_that_would_overlap_a_generated_period_is_refused_and_history_is_untouched(): void
    {
        Carbon::setTestNow('2026-06-15');

        $member = $this->register();
        $this->actingAs($this->admin())->post(route('members.generate-benefit-period', $member));

        $period = BenefitPeriod::where('member_id', $member->id)->firstOrFail();
        $this->assertSame('2026-04-01', $period->from_date->toDateString());

        $this->actingAs($this->admin())
            ->put(route('members.update', $member), $this->editPayload($member, [
                'coverage_year' => 2027,
                'apply_date' => '2027-01-01',
                'coverage_end_date' => '2027-12-31',
                'deduction_start_date' => '2027-02-01',
            ]))
            ->assertSessionHasErrors('coverage_end_date');

        $this->assertSame(1, BenefitPeriod::where('member_id', $member->id)->count());
        $this->assertSame('2027-03-31', $member->fresh()->coverage_end_date->toDateString());
        $this->assertSame('2027-03-31', $period->fresh()->to_date->toDateString());
    }

    public function test_saving_an_unrelated_change_after_a_period_exists_is_not_blocked(): void
    {
        Carbon::setTestNow('2026-06-15');

        $member = $this->register();
        $this->actingAs($this->admin())->post(route('members.generate-benefit-period', $member));

        $this->actingAs($this->admin())
            ->put(route('members.update', $member), $this->editPayload($member, ['last_name' => 'Santos']))
            ->assertSessionHasNoErrors();

        $this->assertSame('Santos', $member->fresh()->last_name);
    }

    public function test_a_configured_period_cannot_be_cleared_back_to_none(): void
    {
        $member = $this->register();

        $this->actingAs($this->admin())
            ->put(route('members.update', $member), $this->editPayload($member, ['coverage_year' => null, 'coverage_end_date' => null]))
            ->assertSessionHasErrors('coverage_end_date');
    }

    public function test_a_legacy_member_can_be_edited_without_touching_coverage_fields(): void
    {
        $member = Member::factory()->create([
            'start_date' => '2019-05-01',
            'apply_date' => '2019-04-01',
            'deduction_start_date' => '2019-06-01',
            'coverage_year' => null,
            'coverage_end_date' => null,
        ]);

        $this->actingAs($this->admin())
            ->put(route('members.update', $member), $this->editPayload($member, ['last_name' => 'Reyes']))
            ->assertSessionHasNoErrors();

        $member->refresh();

        $this->assertSame('Reyes', $member->last_name);
        $this->assertNull($member->coverage_end_date);
    }

    // ---- Scenario 5: duplicate generation ----

    public function test_scenario_5_generating_twice_creates_one_period_labelled_with_its_coverage_year(): void
    {
        Carbon::setTestNow('2026-06-15');

        $member = $this->register([
            'apply_date' => '2026-01-01',
            'coverage_end_date' => '2026-12-31',
            'start_date' => '2026-01-01',
            'deduction_start_date' => '2026-02-01',
        ]);

        $this->actingAs($this->admin())->post(route('members.generate-benefit-period', $member));
        $second = $this->actingAs($this->admin())->post(route('members.generate-benefit-period', $member));

        $second->assertSessionHas('status', fn ($status) => str_contains($status, 'already generated'));
        $this->assertSame(1, BenefitPeriod::where('member_id', $member->id)->count());

        $period = BenefitPeriod::where('member_id', $member->id)->firstOrFail();
        $this->assertSame('2026-01-01', $period->from_date->toDateString());
        $this->assertSame('2026-12-31', $period->to_date->toDateString());
        $this->assertSame(2026, $period->coverage_year);
        $this->assertSame(3600.0, (float) $period->ghp_amount);
    }

    public function test_a_custom_cycle_period_is_not_flagged_as_corrupted_by_the_data_quality_report(): void
    {
        $member = $this->register([
            'apply_date' => '2026-01-01',
            'coverage_end_date' => '2026-12-31',
            'start_date' => '2026-01-01',
            'deduction_start_date' => '2026-02-01',
        ]);

        app(BenefitAccrualService::class)->accrue($member->fresh(), Carbon::parse('2026-06-15'));

        $this->actingAs($this->admin())
            ->get(route('data-quality.index'))
            ->assertOk()
            ->assertViewHas('badDatePeriods', fn ($periods) => $periods->isEmpty());
    }

    // ---- Validation ----

    public function test_end_date_must_be_later_than_the_apply_date(): void
    {
        $this->actingAs($this->admin())
            ->post(route('members.store'), $this->payload(['coverage_end_date' => '2026-04-01']))
            ->assertSessionHasErrors('coverage_end_date');

        $this->assertSame(0, Member::count());
    }

    public function test_coverage_year_must_match_the_year_the_cycle_begins(): void
    {
        $this->actingAs($this->admin())
            ->post(route('members.store'), $this->payload(['coverage_year' => 2027]))
            ->assertSessionHasErrors('coverage_year');
    }

    public function test_coverage_year_and_end_date_must_be_given_together(): void
    {
        $this->actingAs($this->admin())
            ->post(route('members.store'), $this->payload(['coverage_end_date' => '']))
            ->assertSessionHasErrors('coverage_end_date');
    }

    public function test_apply_date_must_fall_inside_the_coverage_period(): void
    {
        $this->actingAs($this->admin())
            ->post(route('members.store'), $this->payload(['apply_date' => '2025-12-01']))
            ->assertSessionHasErrors('apply_date');
    }

    public function test_start_date_must_not_be_before_the_apply_date(): void
    {
        $this->actingAs($this->admin())
            ->post(route('members.store'), $this->payload(['apply_date' => '2026-06-01', 'deduction_start_date' => '2026-05-01']))
            ->assertSessionHasErrors('deduction_start_date');
    }

    public function test_start_date_must_not_be_after_the_day_following_the_end_date(): void
    {
        $this->actingAs($this->admin())
            ->post(route('members.store'), $this->payload(['deduction_start_date' => '2027-06-01']))
            ->assertSessionHasErrors('deduction_start_date');
    }

    public function test_a_start_date_on_the_day_after_the_end_date_is_allowed_and_means_zero_months(): void
    {
        $member = $this->register(['deduction_start_date' => '2027-04-01']);

        $cycle = app(BenefitAccrualService::class)->requiredAmountForCycle($member, Carbon::parse('2026-06-15'));

        $this->assertSame(0, $cycle['applicable_months']);
    }

    public function test_the_ghp_amount_must_be_a_valid_non_negative_amount(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('members.store'), $this->payload(['ghp_amount' => -1]))
            ->assertSessionHasErrors('ghp_amount');
        $this->actingAs($admin)->post(route('members.store'), $this->payload(['ghp_amount' => 'abc']))
            ->assertSessionHasErrors('ghp_amount');
        $this->actingAs($admin)->post(route('members.store'), $this->payload(['ghp_amount' => 3600.555]))
            ->assertSessionHasErrors('ghp_amount');
    }

    public function test_an_invalid_date_is_rejected_with_a_clear_error(): void
    {
        $this->actingAs($this->admin())
            ->post(route('members.store'), $this->payload(['coverage_end_date' => 'not-a-date']))
            ->assertSessionHasErrors('coverage_end_date');
    }
}
