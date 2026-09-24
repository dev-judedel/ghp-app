<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Generate this year's benefit period" button + the backend rule behind it:
 * exactly one BenefitPeriod per member per GHP cycle (Employees Apr 1 – Mar 31),
 * with the DATABASE as the source of truth for whether the button is enabled.
 *
 * Root cause this guards against: the enabled button called
 * generateBenefitPeriodModal.showModal(), but no such <dialog> existed, so
 * clicking did nothing (looked "not clickable"). The first assertions below
 * check the modal the button opens is actually on the page.
 *
 * Markers used to read the button state from the rendered page:
 *   enabled  -> id="generateBenefitPeriodButton" and the modal are present
 *   disabled -> neither is present, and the "already generated" hint is shown
 *
 * NOT YET RUN — run `php artisan test --filter=GenerateBenefitPeriodButtonTest`.
 */
class GenerateBenefitPeriodButtonTest extends TestCase
{
    use RefreshDatabase;

    private const ENABLED_BUTTON = 'id="generateBenefitPeriodButton"';
    private const MODAL = 'id="generateBenefitPeriodModal"';
    private const ALREADY_GENERATED_HINT = 'Benefit period already generated for the current GHP cycle';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-06-15 10:00:00');
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

    private function member(array $overrides = []): Member
    {
        return Member::factory()->create($overrides + [
            'member_type' => Member::MEMBER_TYPE_EMPLOYEE,
            'is_active' => true,
            'ghp_amount' => 3600,
            'ghp_amount_is_manual' => false,
            'start_date' => '2026-05-01',
            'deduction_start_date' => '2026-06-01',
        ]);
    }

    private function assertButtonEnabled(User $admin, Member $member): void
    {
        $this->actingAs($admin)->get(route('members.show', $member))
            ->assertOk()
            ->assertSee(self::ENABLED_BUTTON, false)
            ->assertSee(self::MODAL, false)
            ->assertDontSee(self::ALREADY_GENERATED_HINT);
    }

    private function assertButtonDisabled(User $admin, Member $member): void
    {
        $this->actingAs($admin)->get(route('members.show', $member))
            ->assertOk()
            ->assertDontSee(self::ENABLED_BUTTON, false)
            ->assertDontSee(self::MODAL, false)
            ->assertSee(self::ALREADY_GENERATED_HINT);
    }

    // ---- Test 1: not generated -> enabled (and clickable: its modal exists) ----

    public function test_the_button_is_enabled_and_its_modal_exists_when_the_cycle_is_not_generated(): void
    {
        $admin = $this->admin();
        $member = $this->member();

        $this->assertButtonEnabled($admin, $member);
        $this->assertSame(0, $member->benefitPeriods()->count());
    }

    // ---- Test 2 + 3: generate -> created, message, disabled, stays disabled on refresh ----

    public function test_generating_creates_the_period_and_disables_the_button_and_stays_disabled_on_refresh(): void
    {
        $admin = $this->admin();
        $member = $this->member();

        $this->actingAs($admin)
            ->post(route('members.generate-benefit-period', $member))
            ->assertRedirect(route('members.show', $member))
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'Benefit period generated successfully'));

        $period = $member->benefitPeriods()->firstOrFail();
        $this->assertSame('2026-04-01', $period->from_date->toDateString());
        $this->assertSame('2027-03-31', $period->to_date->toDateString());

        $this->assertButtonDisabled($admin, $member);
        // "Refresh": load it again.
        $this->assertButtonDisabled($admin, $member);
    }

    // ---- Test 4: duplicate rejected on the backend ----

    public function test_a_second_generation_request_is_rejected_and_creates_no_duplicate(): void
    {
        $admin = $this->admin();
        $member = $this->member();

        $this->actingAs($admin)->post(route('members.generate-benefit-period', $member));

        $this->actingAs($admin)
            ->post(route('members.generate-benefit-period', $member))
            ->assertRedirect(route('members.show', $member))
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'already generated'));

        $this->assertSame(1, $member->benefitPeriods()->count());
        $this->assertButtonDisabled($admin, $member);
    }

    public function test_a_non_admin_cannot_generate(): void
    {
        $member = $this->member();
        $staff = User::factory()->create(['role' => 'user', 'is_active' => true]);

        $this->actingAs($staff)
            ->post(route('members.generate-benefit-period', $member))
            ->assertForbidden();

        $this->assertSame(0, $member->benefitPeriods()->count());
    }

    // ---- Test 5: a failed generation leaves the button enabled for a retry ----

    public function test_a_rejected_generation_does_not_disable_the_button(): void
    {
        $admin = $this->admin();
        $inactive = $this->member(['is_active' => false]);

        $this->actingAs($admin)
            ->post(route('members.generate-benefit-period', $inactive))
            ->assertSessionHas('status', fn ($s) => str_contains($s, "Can't generate"));

        $this->assertSame(0, $inactive->benefitPeriods()->count());
        $this->assertButtonEnabled($admin, $inactive);

        // ...and once the cause is fixed, the retry succeeds.
        $inactive->update(['is_active' => true]);
        $this->actingAs($admin)->post(route('members.generate-benefit-period', $inactive));

        $this->assertSame(1, $inactive->benefitPeriods()->count());
        $this->assertButtonDisabled($admin, $inactive);
    }

    // ---- Tests 6 + 7 and the cycle boundary: April 1 starts the next cycle ----

    public function test_the_button_stays_disabled_through_march_31_and_re_enables_on_april_1(): void
    {
        $admin = $this->admin();
        $member = $this->member();

        $this->actingAs($admin)->post(route('members.generate-benefit-period', $member));

        Carbon::setTestNow('2027-03-31 12:00:00');
        $this->assertButtonDisabled($admin, $member->fresh());

        Carbon::setTestNow('2027-04-01 00:30:00');
        $this->assertButtonEnabled($admin, $member->fresh());
    }

    public function test_the_new_cycle_can_be_generated_once_and_then_disables_again(): void
    {
        $admin = $this->admin();
        $member = $this->member();

        $this->actingAs($admin)->post(route('members.generate-benefit-period', $member));

        Carbon::setTestNow('2027-04-01 09:00:00');

        $this->actingAs($admin)->post(route('members.generate-benefit-period', $member->fresh()));

        $periods = $member->benefitPeriods()->orderBy('from_date')->get();
        $this->assertCount(2, $periods);
        $this->assertSame('2027-04-01', $periods->last()->from_date->toDateString());
        $this->assertSame('2028-03-31', $periods->last()->to_date->toDateString());

        // Test 7: new cycle already generated -> disabled.
        $this->assertButtonDisabled($admin, $member->fresh());

        // ...and a repeat attempt is still rejected.
        $this->actingAs($admin)->post(route('members.generate-benefit-period', $member->fresh()));
        $this->assertSame(2, $member->benefitPeriods()->count());
    }

    // ---- Other actions must NOT disable the button ----

    public function test_adding_a_dependent_a_spouse_immediate_eligibility_and_an_amount_change_do_not_disable_it(): void
    {
        $admin = $this->admin();
        $member = $this->member();

        // Dependent added.
        $this->actingAs($admin)->post(route('members.dependents.store', $member), [
            'name' => 'Jane Doe', 'relation' => 'Spouse', 'birthdate' => '1995-01-10',
        ]);
        $this->assertButtonEnabled($admin, $member);

        // Immediate eligibility used.
        $spouse = $member->dependents()->firstOrFail();
        $this->actingAs($admin)->post(route('members.dependents.immediate-eligibility', [$member, $spouse]));
        $this->assertButtonEnabled($admin, $member);

        // GHP amount changed (manual adjustment) and reverted.
        $this->actingAs($admin)->post(route('members.amount-adjustments.store', $member), [
            'new_amount' => 5000, 'reason' => 'Board approval',
        ]);
        $this->assertButtonEnabled($admin, $member);

        $this->actingAs($admin)->post(route('members.amount-adjustments.revert-to-automatic', $member));
        $this->assertButtonEnabled($admin, $member);

        // Spouse added through Edit Member.
        $other = $this->member(['civil_status' => Member::CIVIL_STATUS_SINGLE]);
        $this->actingAs($admin)->put(route('members.update', $other), [
            'code' => $other->code,
            'email' => $other->email,
            'member_type' => '0',
            'last_name' => $other->last_name,
            'first_name' => $other->first_name,
            'apply_date' => '2026-04-01',
            'start_date' => '2026-05-01',
            'civil_status' => '1',
            'spouse_name' => 'Maria Doe',
            'spouse_birthdate' => '1996-02-02',
        ]);
        $this->assertButtonEnabled($admin, $other);

        $this->assertSame(0, $member->benefitPeriods()->count());
        $this->assertSame(0, $other->benefitPeriods()->count());
    }
}
