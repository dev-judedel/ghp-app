<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\User;
use App\Services\BenefitAccrualService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the GHP Benefit Setup rework: Apply Date / Start Date / Deduction
 * Date as three distinct fields, the deduction-date auto-calculation rule
 * (never deduct the start month, first deduction = 1st of the following
 * month), required-date validation, and the dependent-eligibility-driven
 * GHP amount recalculation.
 *
 * NOTE on Test 1 (single member, 12 months): a member who starts ON the
 * cycle's own first month (e.g. April 1 for Employees) does NOT get 12
 * applicable months under the "never deduct the start month" rule — their
 * first deduction is May 1, giving 11 months. A full 12 months only
 * happens when deduction_start_date itself lands exactly on the cycle's
 * first day, which happens for a member whose start_date falls in the
 * PRECEDING month (e.g. March 1 for an Employee's April–March cycle). This
 * test uses that setup deliberately — see the class-level flag in the
 * implementation summary for the full explanation of this conflict between
 * the two source specs.
 */
class MemberBenefitSetupTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'is_active' => true]);
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'email' => 'juan@example.test',
            'member_type' => '0',
            'last_name' => 'Dela Cruz',
            'first_name' => 'Juan',
            'ghp_amount' => 3600,
            'apply_date' => '2026-04-01',
            'start_date' => '2026-06-01',
        ], $overrides);
    }

    // ---- Required dates (Doc 6 §1) ----

    public function test_apply_date_is_required(): void
    {
        $payload = $this->validPayload();
        unset($payload['apply_date']);

        $this->actingAs($this->admin())
            ->post(route('members.store'), $payload)
            ->assertSessionHasErrors('apply_date');
    }

    public function test_start_date_is_required(): void
    {
        $payload = $this->validPayload();
        unset($payload['start_date']);

        $this->actingAs($this->admin())
            ->post(route('members.store'), $payload)
            ->assertSessionHasErrors('start_date');
    }

    public function test_both_dates_missing_shows_both_errors(): void
    {
        $payload = $this->validPayload();
        unset($payload['apply_date'], $payload['start_date']);

        $response = $this->actingAs($this->admin())->post(route('members.store'), $payload);

        $response->assertSessionHasErrors(['apply_date', 'start_date']);
    }

    public function test_the_member_is_not_saved_when_dates_are_missing(): void
    {
        $payload = $this->validPayload();
        unset($payload['start_date']);

        $this->actingAs($this->admin())->post(route('members.store'), $payload);

        $this->assertDatabaseMissing('members', ['email' => 'juan@example.test']);
    }

    // ---- Deduction date auto-calculation (Doc 7) ----

    public function test_deduction_date_is_the_first_of_the_month_after_start_date(): void
    {
        $this->actingAs($this->admin())->post(route('members.store'), $this->validPayload([
            'start_date' => '2026-06-01',
        ]));

        $member = Member::where('email', 'juan@example.test')->firstOrFail();

        $this->assertSame('2026-07-01', $member->deduction_start_date->toDateString());
    }

    public function test_deduction_date_correctly_rolls_over_the_year(): void
    {
        $this->actingAs($this->admin())->post(route('members.store'), $this->validPayload([
            'start_date' => '2026-12-01',
        ]));

        $member = Member::where('email', 'juan@example.test')->firstOrFail();

        $this->assertSame('2027-01-01', $member->deduction_start_date->toDateString());
    }

    public function test_a_march_start_pushes_the_first_deduction_into_the_next_ghp_cycle(): void
    {
        $this->actingAs($this->admin())->post(route('members.store'), $this->validPayload([
            'start_date' => '2027-03-01',
        ]));

        $member = Member::where('email', 'juan@example.test')->firstOrFail();

        $this->assertSame('2027-04-01', $member->deduction_start_date->toDateString());
    }

    public function test_a_submitted_deduction_start_date_is_ignored_and_always_recomputed(): void
    {
        $this->actingAs($this->admin())->post(route('members.store'), $this->validPayload([
            'start_date' => '2026-06-01',
            'deduction_start_date' => '1999-01-01', // not a real field — must be ignored
        ]));

        $member = Member::where('email', 'juan@example.test')->firstOrFail();

        $this->assertSame('2026-07-01', $member->deduction_start_date->toDateString());
    }

    public function test_editing_a_member_recomputes_the_deduction_date_from_the_new_start_date(): void
    {
        $admin = $this->admin();
        $member = Member::factory()->create([
            'start_date' => '2026-06-01',
            'deduction_start_date' => '2026-07-01',
        ]);

        $this->actingAs($admin)->put(route('members.update', $member), [
            'code' => $member->code,
            'email' => $member->email,
            'member_type' => '0',
            'last_name' => $member->last_name,
            'first_name' => $member->first_name,
            'apply_date' => '2026-04-01',
            'start_date' => '2026-08-01', // changed
        ]);

        $this->assertSame('2026-09-01', $member->fresh()->deduction_start_date->toDateString());
    }

    // ---- Applicable months / required amount (Doc 6, Doc 7's worked example) ----

    public function test_a_june_2026_member_without_a_dependent_gets_9_applicable_months_and_2700_required(): void
    {
        $accrual = app(BenefitAccrualService::class);
        $member = Member::factory()->create([
            'member_type' => Member::MEMBER_TYPE_EMPLOYEE,
            'deduction_start_date' => '2026-07-01', // June 1 start -> July 1 deduction
            'ghp_amount' => 3600,
            'ghp_amount_is_manual' => false,
        ]);

        $result = $accrual->requiredAmountForCycle($member, Carbon::parse('2026-06-15'));

        $this->assertSame(9, $result['applicable_months']);
        $this->assertSame(300.0, $result['monthly_rate']);
        $this->assertSame(2700.0, $result['required_amount']);
    }

    public function test_a_june_2026_member_with_an_eligible_dependent_gets_3150_required(): void
    {
        $accrual = app(BenefitAccrualService::class);
        $member = Member::factory()->create([
            'member_type' => Member::MEMBER_TYPE_EMPLOYEE,
            'deduction_start_date' => '2026-07-01',
            'ghp_amount_is_manual' => false,
        ]);
        // resolveGhpAmount() derives the amount LIVE from actual dependent
        // records when ghp_amount_is_manual is false — it ignores whatever
        // is sitting in the ghp_amount column. An eligible dependent has to
        // actually exist, not just be implied by setting ghp_amount=4200.
        $member->dependents()->create(['name' => 'Maria Dela Cruz', 'relation' => 'Spouse']);
        $member->load('dependents');

        $result = $accrual->requiredAmountForCycle($member, Carbon::parse('2026-06-15'));

        $this->assertSame(9, $result['applicable_months']);
        $this->assertSame(350.0, $result['monthly_rate']);
        $this->assertSame(3150.0, $result['required_amount']);
    }

    public function test_a_full_cycle_member_without_dependent_gets_12_months_and_3600_required(): void
    {
        // See class docblock: a full 12 months requires deduction_start_date
        // to land exactly on the cycle's own first day (Apr 1 for
        // Employees), which happens for a start_date in the preceding month.
        $accrual = app(BenefitAccrualService::class);
        $member = Member::factory()->create([
            'member_type' => Member::MEMBER_TYPE_EMPLOYEE,
            'deduction_start_date' => '2026-04-01',
            'ghp_amount' => 3600,
            'ghp_amount_is_manual' => false,
        ]);

        $result = $accrual->requiredAmountForCycle($member, Carbon::parse('2026-04-15'));

        $this->assertSame(12, $result['applicable_months']);
        $this->assertSame(3600.0, $result['required_amount']);
    }

    public function test_a_full_cycle_member_with_dependent_gets_12_months_and_4200_required(): void
    {
        $accrual = app(BenefitAccrualService::class);
        $member = Member::factory()->create([
            'member_type' => Member::MEMBER_TYPE_EMPLOYEE,
            'deduction_start_date' => '2026-04-01',
            'ghp_amount_is_manual' => false,
        ]);
        $member->dependents()->create(['name' => 'Maria Dela Cruz', 'relation' => 'Spouse']);
        $member->load('dependents');

        $result = $accrual->requiredAmountForCycle($member, Carbon::parse('2026-04-15'));

        $this->assertSame(12, $result['applicable_months']);
        $this->assertSame(4200.0, $result['required_amount']);
    }

    public function test_a_custom_monthly_amount_is_used_instead_of_the_default(): void
    {
        $accrual = app(BenefitAccrualService::class);
        $member = Member::factory()->create([
            'member_type' => Member::MEMBER_TYPE_EMPLOYEE,
            'deduction_start_date' => '2026-07-01',
            'ghp_amount' => 4500, // admin override, 375/month x 12
            'ghp_amount_is_manual' => true,
        ]);

        $result = $accrual->requiredAmountForCycle($member, Carbon::parse('2026-06-15'));

        $this->assertSame(9, $result['applicable_months']);
        $this->assertSame(375.0, $result['monthly_rate']);
        $this->assertSame(3375.0, $result['required_amount']);
    }

    public function test_a_member_who_started_in_the_cycles_last_month_has_zero_applicable_months_this_cycle(): void
    {
        $accrual = app(BenefitAccrualService::class);
        $member = Member::factory()->create([
            'member_type' => Member::MEMBER_TYPE_EMPLOYEE,
            'deduction_start_date' => '2027-04-01', // March 2027 start -> April 2027 deduction, next cycle
            'ghp_amount' => 3600,
            'ghp_amount_is_manual' => false,
        ]);

        // Asking for the cycle containing March 2027 (Apr 2026–Mar 2027):
        $result = $accrual->requiredAmountForCycle($member, Carbon::parse('2027-03-15'));

        $this->assertSame(0, $result['applicable_months']);
        $this->assertSame(0.0, $result['required_amount']);
    }

    /**
     * The "never deduct the start month" rule only applies to a member's
     * FIRST (enrollment) cycle. Once that partial cycle ends and a new one
     * begins, the member was already active the whole time, so they're
     * back to a full 12 months — not still counting from their original
     * start date. This is the scenario explicitly confirmed in review.
     */
    public function test_a_june_2026_members_second_cycle_is_back_to_the_full_12_months(): void
    {
        $accrual = app(BenefitAccrualService::class);
        $member = Member::factory()->create([
            'member_type' => Member::MEMBER_TYPE_EMPLOYEE,
            'deduction_start_date' => '2026-07-01', // enrolled June 2026, first deduction July 2026
            'ghp_amount' => 3600,
            'ghp_amount_is_manual' => false,
        ]);

        // First (enrollment) cycle: Apr 2026–Mar 2027 -> 9 months, as before.
        $firstCycle = $accrual->requiredAmountForCycle($member, Carbon::parse('2026-06-15'));
        $this->assertSame(9, $firstCycle['applicable_months']);
        $this->assertSame(2700.0, $firstCycle['required_amount']);

        // Second cycle: Apr 2027–Mar 2028 -> back to a full 12 months,
        // since the member was already active before this cycle started.
        $secondCycle = $accrual->requiredAmountForCycle($member, Carbon::parse('2027-06-15'));
        $this->assertSame(12, $secondCycle['applicable_months']);
        $this->assertSame(3600.0, $secondCycle['required_amount']);
    }

    // ---- Dependent eligibility recalculation (Doc 6 §4/§7) ----

    /**
     * GHP Dependent Eligibility Rule: adding a dependent mid-cycle must
     * NOT raise the member's GHP amount for the coverage period already
     * in progress — see DependentController::store() /
     * BenefitAccrualService::resolveDependentEligibilityDate(). The
     * dependent is recorded immediately (name, relation, dates all save
     * normally); only the amount bump is deferred to the next cycle.
     */
    public function test_adding_a_dependent_mid_cycle_does_not_immediately_raise_the_ghp_amount(): void
    {
        $admin = $this->admin();
        $member = Member::factory()->create([
            'civil_status' => Member::CIVIL_STATUS_SINGLE,
            'member_type' => Member::MEMBER_TYPE_EMPLOYEE,
            'ghp_amount' => 3600,
            'ghp_amount_is_manual' => false,
            'deduction_start_date' => '2026-07-01',
            'start_date' => '2026-06-01',
        ]);

        $this->actingAs($admin)->post(route('members.dependents.store', $member), [
            'name' => 'Maria Dela Cruz',
            'relation' => 'Spouse', // always eligible by age/relation
        ]);

        $member->refresh();

        // Still the base rate — the dependent does not count yet.
        $this->assertSame(3600.0, (float) $member->ghp_amount);
        $this->assertSame('2026-07-01', $member->deduction_start_date->toDateString());
        $this->assertSame('2026-06-01', $member->start_date->toDateString());
        // Exactly one member, one dependent — no duplicate records.
        $this->assertSame(1, Member::count());
        $this->assertSame(1, $member->dependents()->count());

        $dependent = $member->dependents()->first();
        $this->assertNotNull($dependent->date_added);
        $this->assertNotNull($dependent->eligibility_date);
        $this->assertSame('pending', $dependent->eligibility_status);
    }

    /**
     * The same dependent DOES count once evaluated as of a date inside the
     * NEXT coverage cycle — this is what actually drives the higher
     * amount once "Generate this year's benefit period" (or the daily
     * ghp:auto-generate-benefit-periods job) runs for that cycle.
     */
    public function test_a_dependent_added_mid_cycle_becomes_eligible_in_the_next_cycle(): void
    {
        $admin = $this->admin();
        $member = Member::factory()->create([
            'member_type' => Member::MEMBER_TYPE_EMPLOYEE,
            'ghp_amount' => 3600,
            'ghp_amount_is_manual' => false,
            'deduction_start_date' => '2026-07-01',
            'start_date' => '2026-06-01',
        ]);

        // Added June 2026 -> current cycle is Apr 2026-Mar 2027.
        $this->actingAs($admin)->post(route('members.dependents.store', $member), [
            'name' => 'Maria Dela Cruz',
            'relation' => 'Spouse',
        ]);

        $dependent = $member->fresh(['dependents'])->dependents->first();

        // Eligible starting the next cycle: Apr 1, 2027.
        $this->assertSame('2027-04-01', $dependent->eligibility_date->toDateString());

        $accrual = app(BenefitAccrualService::class);

        // Still pending for the remainder of the current cycle.
        $this->assertSame(3600.0, $accrual->resolveGhpAmount($member->fresh(['dependents']), Carbon::parse('2027-03-31')));

        // Eligible the moment the next cycle begins.
        $this->assertSame(4200.0, $accrual->resolveGhpAmount($member->fresh(['dependents']), Carbon::parse('2027-04-01')));

        // eligibility_status (see Dependent::eligibilityStatus()) is
        // evaluated against wall-clock now(), not an arbitrary $asOf, so
        // it can only be asserted 'pending' here — the real test-run date
        // hasn't reached this dependent's 2027-04-01 eligibility date yet.
        $this->assertSame('pending', $dependent->fresh()->eligibility_status);
    }

    public function test_removing_the_only_eligible_dependent_reverts_ghp_amount_to_the_base_rate(): void
    {
        $admin = $this->admin();
        $member = Member::factory()->create([
            'ghp_amount' => 4200,
            'ghp_amount_is_manual' => false,
        ]);
        $dependent = $member->dependents()->create(['name' => 'Maria', 'relation' => 'Spouse']);

        $this->actingAs($admin)->delete(route('members.dependents.destroy', [$member, $dependent]));

        $this->assertSame(3600.0, (float) $member->fresh()->ghp_amount);
    }

    public function test_a_manually_overridden_amount_is_not_reset_when_a_dependent_is_added(): void
    {
        $admin = $this->admin();
        $member = Member::factory()->create([
            'ghp_amount' => 5000,
            'ghp_amount_is_manual' => true,
        ]);

        $this->actingAs($admin)->post(route('members.dependents.store', $member), [
            'name' => 'Maria', 'relation' => 'Spouse',
        ]);

        $this->assertSame(5000.0, (float) $member->fresh()->ghp_amount);
    }
}
