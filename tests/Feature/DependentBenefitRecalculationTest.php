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
 * "Update Benefit Balance and Dependents": when a dependent becomes
 * eligible, the member's GHP amount AND available balance follow, prorated
 * by months rendered (GHP amount / 12 x months rendered) — recomputed from
 * the records, never added as a stored delta.
 *
 * Time is frozen to 2026-09-15 (Employee cycle Apr 2026 - Mar 2027) with a
 * deduction start of 2026-04-01, so the member has rendered exactly 6 months
 * (Apr..Sep):
 *     3,600 / 12 x 6 = 1,800      4,200 / 12 x 6 = 2,100      (+300)
 *
 * NOT YET RUN — written from reading the service/controllers/views.
 * Run: php artisan test --filter=DependentBenefitRecalculationTest
 */
class DependentBenefitRecalculationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-15 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'is_active' => true]);
    }

    /** A member with 6 months rendered and an already-generated current period. */
    private function memberWithPeriod(array $overrides = []): Member
    {
        $member = Member::factory()->create($overrides + [
            'member_type' => Member::MEMBER_TYPE_EMPLOYEE,
            'civil_status' => Member::CIVIL_STATUS_SINGLE,
            'ghp_amount' => 3600,
            'ghp_amount_is_manual' => false,
            'start_date' => '2026-03-01',
            'deduction_start_date' => '2026-04-01',
        ]);

        $member->load('dependents');
        app(BenefitAccrualService::class)->accrue($member);

        return $member;
    }

    private function period(Member $member): BenefitPeriod
    {
        return BenefitPeriod::where('member_id', $member->id)->firstOrFail();
    }

    private function addViaForm(User $admin, Member $member, string $name, string $relation, string $birthdate)
    {
        return $this->actingAs($admin)->post(route('members.dependents.store', $member), [
            'name' => $name, 'relation' => $relation, 'birthdate' => $birthdate,
        ]);
    }

    private function grant(User $admin, Member $member, string $name)
    {
        $dependent = $member->dependents()->where('name', $name)->firstOrFail();

        return $this->actingAs($admin)->post(route('members.dependents.immediate-eligibility', [$member, $dependent]));
    }

    // ---- Test Case 1: no dependent ----

    public function test_a_member_without_a_dependent_keeps_the_existing_values(): void
    {
        $member = $this->memberWithPeriod();

        $this->assertSame(3600.0, (float) $this->period($member)->ghp_amount);
        $this->assertSame(1800.0, (float) $this->period($member)->ghp_available);
        $this->assertSame(6, app(BenefitAccrualService::class)->calculate($member)['months_accrued']);
    }

    // ---- Test Case 2: the worked example from the task ----

    public function test_an_eligible_dependent_raises_ghp_to_4200_and_available_from_1800_to_2100(): void
    {
        $admin = $this->admin();
        $member = $this->memberWithPeriod();

        $this->addViaForm($admin, $member, 'Jane Doe', 'Spouse', '1995-01-10');
        $this->grant($admin, $member, 'Jane Doe')->assertRedirect(route('members.show', $member));

        $period = $this->period($member);
        $this->assertSame(4200.0, (float) $period->ghp_amount);
        $this->assertSame(2100.0, (float) $period->ghp_available);          // 1,800 + 300
        $this->assertSame(0.0, (float) $period->ghp_used);
        $this->assertSame(4200.0, (float) $member->fresh()->ghp_amount);
        $this->assertSame(1, BenefitPeriod::where('member_id', $member->id)->count());
    }

    public function test_the_benefit_balance_page_shows_the_updated_values(): void
    {
        $admin = $this->admin();
        $member = $this->memberWithPeriod();

        $this->addViaForm($admin, $member, 'Jane Doe', 'Spouse', '1995-01-10');
        $this->grant($admin, $member, 'Jane Doe');

        $this->actingAs($admin)->get(route('members.show', $member))
            ->assertOk()
            ->assertSee('4,200.00')
            ->assertSee('2,100.00')
            ->assertSee('350.00');   // monthly GHP = 4,200 / 12
    }

    // ---- Test Case 3: not yet eligible ----

    public function test_a_dependent_who_is_not_yet_eligible_changes_nothing(): void
    {
        $admin = $this->admin();
        $member = $this->memberWithPeriod();

        $this->addViaForm($admin, $member, 'Jane Doe', 'Spouse', '1995-01-10');

        $dependent = $member->dependents()->firstOrFail();
        $this->assertSame('pending', $dependent->eligibility_status);
        $this->assertSame('2027-04-01', $dependent->eligibility_date->toDateString());

        $this->assertSame(3600.0, (float) $this->period($member)->ghp_amount);
        $this->assertSame(1800.0, (float) $this->period($member)->ghp_available);
        $this->assertSame(3600.0, (float) $member->fresh()->ghp_amount);
    }

    public function test_the_pending_dependent_shows_as_pending_in_the_dependents_table(): void
    {
        $admin = $this->admin();
        $member = $this->memberWithPeriod();

        $this->addViaForm($admin, $member, 'Jane Doe', 'Spouse', '1995-01-10');

        $this->actingAs($admin)->get(route('members.show', $member))
            ->assertOk()
            ->assertSee('Pending Eligibility');
    }

    public function test_the_dependent_counts_once_the_next_cycle_starts_without_any_manual_step(): void
    {
        $admin = $this->admin();
        $member = $this->memberWithPeriod();
        $this->addViaForm($admin, $member, 'Jane Doe', 'Spouse', '1995-01-10');

        // Existing "waits for the next benefit period" rule, unchanged:
        // evaluated in the next cycle the dependent is simply eligible.
        $result = app(BenefitAccrualService::class)->calculate(
            $member->fresh(['dependents']),
            Carbon::parse('2027-04-15')
        );

        $this->assertSame(4200.0, $result['ghp_amount']);
    }

    // ---- Test Case 4: multiple dependents ----

    public function test_multiple_eligible_dependents_do_not_duplicate_the_benefit(): void
    {
        $admin = $this->admin();
        $member = $this->memberWithPeriod();

        $this->addViaForm($admin, $member, 'Jane Doe', 'Spouse', '1995-01-10');
        $this->addViaForm($admin, $member, 'Kid One', 'Son', '2018-05-20');

        $this->grant($admin, $member, 'Jane Doe');
        $this->assertSame(2100.0, (float) $this->period($member)->ghp_available);

        // Second eligible dependent: the existing rule is one flat 4,200
        // rate for "1 or more eligible dependents" (see resolveGhpAmount()),
        // so nothing is added a second time.
        $this->grant($admin, $member, 'Kid One');

        $period = $this->period($member);
        $this->assertSame(4200.0, (float) $period->ghp_amount);
        $this->assertSame(2100.0, (float) $period->ghp_available);
        $this->assertSame(2, $member->dependents()->count());
    }

    public function test_an_age_ineligible_child_never_raises_the_amount(): void
    {
        $admin = $this->admin();
        $member = $this->memberWithPeriod();

        $this->addViaForm($admin, $member, 'Grown Son', 'Son', '1990-01-01');
        $this->grant($admin, $member, 'Grown Son');

        $this->assertSame(3600.0, (float) $this->period($member)->ghp_amount);
        $this->assertSame(1800.0, (float) $this->period($member)->ghp_available);
    }

    // ---- Test Case 5: refresh / duplicate prevention ----

    public function test_refreshing_the_page_and_repeating_the_request_never_adds_the_benefit_again(): void
    {
        $admin = $this->admin();
        $member = $this->memberWithPeriod();

        $this->addViaForm($admin, $member, 'Jane Doe', 'Spouse', '1995-01-10');
        $this->grant($admin, $member, 'Jane Doe');

        // Repeat the grant, and reload the page several times.
        $this->grant($admin, $member, 'Jane Doe');
        foreach (range(1, 3) as $ignored) {
            $this->actingAs($admin)->get(route('members.show', $member))->assertOk();
        }

        $period = $this->period($member);
        $this->assertSame(4200.0, (float) $period->ghp_amount);
        $this->assertSame(2100.0, (float) $period->ghp_available);
        $this->assertSame(1, BenefitPeriod::where('member_id', $member->id)->count());
    }

    public function test_the_recalculation_is_idempotent_at_the_service_level(): void
    {
        $admin = $this->admin();
        $member = $this->memberWithPeriod();
        $this->addViaForm($admin, $member, 'Jane Doe', 'Spouse', '1995-01-10');
        $this->grant($admin, $member, 'Jane Doe');

        $service = app(BenefitAccrualService::class);

        // Amounts already match => nothing to do, nothing written.
        $this->assertNull($service->refreshCurrentPeriodIfAmountChanged($member->fresh()));
        $this->assertNull($service->refreshCurrentPeriodIfAmountChanged($member->fresh()));
        $this->assertSame(2100.0, (float) $this->period($member)->ghp_available);
    }

    // ---- Existing deductions / history preserved ----

    public function test_existing_deductions_are_preserved_when_the_balance_is_recalculated(): void
    {
        $admin = $this->admin();
        $member = $this->memberWithPeriod();

        $member->reimbursements()->create(['or_no' => 'OR-1', 'or_date' => '2026-08-10', 'or_amount' => 500]);
        app(BenefitAccrualService::class)->accrue($member->fresh(['dependents']));

        $this->assertSame(500.0, (float) $this->period($member)->ghp_used);
        $this->assertSame(1300.0, (float) $this->period($member)->ghp_available);   // 1,800 - 500

        $this->addViaForm($admin, $member, 'Jane Doe', 'Spouse', '1995-01-10');
        $this->grant($admin, $member, 'Jane Doe');

        $period = $this->period($member);
        $this->assertSame(500.0, (float) $period->ghp_used);                          // unchanged
        $this->assertSame(1600.0, (float) $period->ghp_available);                    // 2,100 - 500
        $this->assertSame(1, $member->reimbursements()->count());
    }

    public function test_a_prior_period_is_never_touched(): void
    {
        $admin = $this->admin();
        $member = $this->memberWithPeriod();

        $prior = BenefitPeriod::create([
            'member_id' => $member->id,
            'from_date' => '2025-04-01',
            'to_date' => '2026-03-31',
            'ghp_amount' => 3600,
            'ghp_available' => 0,
            'ghp_used' => 3600,
            'member_type' => $member->member_type,
        ]);

        $this->addViaForm($admin, $member, 'Jane Doe', 'Spouse', '1995-01-10');
        $this->grant($admin, $member, 'Jane Doe');

        $this->assertSame(3600.0, (float) $prior->fresh()->ghp_amount);
        $this->assertSame(0.0, (float) $prior->fresh()->ghp_available);
        $this->assertSame(3600.0, (float) $prior->fresh()->ghp_used);
    }

    // ---- Removal keeps things consistent; manual override / no period edge cases ----

    public function test_removing_the_only_eligible_dependent_returns_to_the_base_amount(): void
    {
        $admin = $this->admin();
        $member = $this->memberWithPeriod();

        $this->addViaForm($admin, $member, 'Jane Doe', 'Spouse', '1995-01-10');
        $this->grant($admin, $member, 'Jane Doe');
        $this->assertSame(2100.0, (float) $this->period($member)->ghp_available);

        $dependent = $member->dependents()->firstOrFail();
        $this->actingAs($admin)->delete(route('members.dependents.destroy', [$member, $dependent]));

        $this->assertSame(3600.0, (float) $this->period($member)->ghp_amount);
        $this->assertSame(1800.0, (float) $this->period($member)->ghp_available);
    }

    public function test_a_manual_ghp_override_is_left_alone(): void
    {
        $admin = $this->admin();
        $member = $this->memberWithPeriod(['ghp_amount' => 5000, 'ghp_amount_is_manual' => true]);
        $before = $this->period($member)->only(['ghp_amount', 'ghp_available']);

        $this->addViaForm($admin, $member, 'Jane Doe', 'Spouse', '1995-01-10');
        $this->grant($admin, $member, 'Jane Doe');

        $this->assertEquals($before, $this->period($member)->only(['ghp_amount', 'ghp_available']));
        $this->assertSame(5000.0, (float) $member->fresh()->ghp_amount);
    }

    public function test_granting_never_creates_a_period_that_was_not_generated(): void
    {
        $admin = $this->admin();
        $member = Member::factory()->create([
            'member_type' => Member::MEMBER_TYPE_EMPLOYEE,
            'civil_status' => Member::CIVIL_STATUS_SINGLE,
            'ghp_amount' => 3600,
            'ghp_amount_is_manual' => false,
            'start_date' => '2026-03-01',
            'deduction_start_date' => '2026-04-01',
        ]);

        $this->addViaForm($admin, $member, 'Jane Doe', 'Spouse', '1995-01-10');
        $this->grant($admin, $member, 'Jane Doe');

        $this->assertSame(0, BenefitPeriod::where('member_id', $member->id)->count());
        $this->assertSame(4200.0, (float) $member->fresh()->ghp_amount);
    }
}
