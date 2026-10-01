<?php

namespace Tests\Feature;

use App\Models\Dependent;
use App\Models\Member;
use App\Models\Reimbursement;
use App\Models\User;
use App\Services\BenefitAccrualService;
use App\Services\DependentEligibilityService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A dependent can't be removed while the member has an active reimbursement.
 *
 * This project's reimbursement states are Active and Voided (void = the
 * existing "cancel"); there is no Pending / Approved / Rejected workflow. So
 * "active or filed" = any reimbursement that is not voided (and not
 * soft-deleted). The Remove button is disabled on the page AND the backend
 * refuses the DELETE on its own.
 *
 * NOT YET RUN — written from reading the code.
 * Run: php artisan test --filter=DependentRemovalGuardTest
 */
class DependentRemovalGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Inside the member's Apr 2026 – Mar 2027 cycle, all 12 months elapsed.
        Carbon::setTestNow(Carbon::parse('2027-03-15'));
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

    private function member(): Member
    {
        return Member::factory()->create([
            'member_type' => Member::MEMBER_TYPE_EMPLOYEE,
            'is_active' => true,
            'deduction_start_date' => '2026-04-01',
            'ghp_amount' => 3600,
            'ghp_amount_is_manual' => false,
        ]);
    }

    private function dependent(Member $member, string $name = 'Jane Doe'): Dependent
    {
        return $member->dependents()->create([
            'name' => $name,
            'relation' => 'Spouse',
            'birthdate' => '1990-01-01',
        ]);
    }

    private function claim(Member $member, float $amount = 500, string $date = '2026-06-10'): Reimbursement
    {
        return $member->reimbursements()->create([
            'or_no' => 'OR-'.$amount,
            'or_date' => $date,
            'or_amount' => $amount,
        ]);
    }

    private function remove(User $admin, Member $member, Dependent $dependent)
    {
        return $this->actingAs($admin)->delete(route('members.dependents.destroy', [$member, $dependent]));
    }

    private function removeUrl(Member $member, Dependent $dependent): string
    {
        return route('members.dependents.destroy', [$member, $dependent]);
    }

    // ---- Test 1: active reimbursement -> button disabled, nothing to click ----

    public function test_the_remove_button_is_disabled_when_the_member_has_an_active_reimbursement(): void
    {
        $member = $this->member();
        $dependent = $this->dependent($member);
        $admin = $this->admin();

        // File it the real way (through the File reimbursement endpoint).
        $this->actingAs($admin)->post(route('members.reimbursements.store', $member), [
            'or_no' => 'OR-1', 'or_date' => '2026-06-10', 'or_amount' => 500,
        ])->assertSessionHasNoErrors();

        $html = $this->actingAs($admin)->get(route('members.show', $member))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'data-dependent-remove="blocked"'));
        $this->assertSame(0, substr_count($html, 'data-dependent-remove="allowed"'));
        $this->assertStringContainsString(DependentEligibilityService::REMOVAL_BLOCKED_MESSAGE, $html);   // tooltip text

        // Disabled, and there is no form/URL behind it, so a click can't submit or open a confirm.
        $this->assertMatchesRegularExpression('/<button[^>]*disabled[^>]*data-dependent-remove="blocked"/s', $html);
        $this->assertStringNotContainsString($this->removeUrl($member, $dependent), $html);
        $this->assertStringNotContainsString("Remove {$dependent->name} as a dependent?", $html);
    }

    // ---- Test 2: backend protection ----

    public function test_a_direct_delete_request_is_rejected_and_the_dependent_is_unchanged(): void
    {
        $member = $this->member();
        $dependent = $this->dependent($member);
        $this->claim($member);

        $this->remove($this->admin(), $member, $dependent)
            ->assertRedirect(route('members.show', $member))
            ->assertSessionHas('status', DependentEligibilityService::REMOVAL_BLOCKED_MESSAGE);

        $this->assertDatabaseHas('dependents', [
            'id' => $dependent->id,
            'member_id' => $member->id,
            'name' => 'Jane Doe',
            'relation' => 'Spouse',
        ]);
    }

    public function test_the_guard_lives_in_the_service_so_no_other_caller_can_bypass_it(): void
    {
        $member = $this->member();
        $dependent = $this->dependent($member);
        $this->claim($member);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(DependentEligibilityService::REMOVAL_BLOCKED_MESSAGE);

        try {
            app(DependentEligibilityService::class)->removeDependent($member, $dependent);
        } finally {
            $this->assertDatabaseHas('dependents', ['id' => $dependent->id]);
        }
    }

    // ---- Test 3: no reimbursement -> works as before ----

    public function test_remove_works_as_before_when_the_member_has_no_reimbursement(): void
    {
        $member = $this->member();
        $dependent = $this->dependent($member);
        $admin = $this->admin();

        $html = $this->actingAs($admin)->get(route('members.show', $member))->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'data-dependent-remove="allowed"'));
        $this->assertSame(0, substr_count($html, 'data-dependent-remove="blocked"'));
        $this->assertStringContainsString($this->removeUrl($member, $dependent), $html);

        $this->remove($admin, $member, $dependent)
            ->assertRedirect(route('members.show', $member))
            ->assertSessionHas('status', "Dependent removed for {$member->code}.");

        $this->assertDatabaseMissing('dependents', ['id' => $dependent->id]);
    }

    // ---- Test 4: multiple reimbursements ----

    public function test_it_stays_disabled_while_at_least_one_qualifying_reimbursement_exists(): void
    {
        $member = $this->member();
        $dependent = $this->dependent($member);
        $admin = $this->admin();

        $voided = $this->claim($member, 100, '2026-05-10');
        $active = $this->claim($member, 200, '2026-06-10');
        $voided->void('filed in error', $admin);

        // One voided + one active -> still blocked.
        $this->actingAs($admin)->get(route('members.show', $member))->assertOk()
            ->assertSee('data-dependent-remove="blocked"', false);
        $this->remove($admin, $member, $dependent)
            ->assertSessionHas('status', DependentEligibilityService::REMOVAL_BLOCKED_MESSAGE);
        $this->assertDatabaseHas('dependents', ['id' => $dependent->id]);

        // Two active ones, then voiding only one of them -> still blocked.
        $second = $this->claim($member, 300, '2026-07-10');
        $active->void('duplicate', $admin);
        $this->actingAs($admin)->get(route('members.show', $member))
            ->assertSee('data-dependent-remove="blocked"', false);

        // Void the last one -> available again.
        $second->void('duplicate', $admin);
        $this->actingAs($admin)->get(route('members.show', $member))->assertOk()
            ->assertSee('data-dependent-remove="allowed"', false)
            ->assertDontSee('data-dependent-remove="blocked"', false);
    }

    // ---- Test 5: voided (cancelled) -> available again, automatically ----

    public function test_voiding_the_only_active_reimbursement_re_enables_remove_without_extra_steps(): void
    {
        $member = $this->member();
        $dependent = $this->dependent($member);
        $admin = $this->admin();
        $claim = $this->claim($member);

        $this->actingAs($admin)->get(route('members.show', $member))
            ->assertSee('data-dependent-remove="blocked"', false);

        // Cancel it through the real void action, then follow its redirect.
        $this->actingAs($admin)->post(route('members.reimbursements.void', [$member, $claim]), ['reason' => 'Filed in error'])
            ->assertRedirect(route('members.show', $member));

        $this->actingAs($admin)->get(route('members.show', $member))->assertOk()
            ->assertSee('data-dependent-remove="allowed"', false)
            ->assertDontSee('data-dependent-remove="blocked"', false);

        $this->remove($admin, $member, $dependent)->assertSessionHas('status', "Dependent removed for {$member->code}.");
        $this->assertDatabaseMissing('dependents', ['id' => $dependent->id]);
    }

    public function test_unvoiding_the_reimbursement_blocks_removal_again(): void
    {
        $member = $this->member();
        $dependent = $this->dependent($member);
        $admin = $this->admin();
        $claim = $this->claim($member);
        $claim->void('filed in error', $admin);

        $this->actingAs($admin)->post(route('members.reimbursements.unvoid', [$member, $claim]));

        $this->actingAs($admin)->get(route('members.show', $member))->assertSee('data-dependent-remove="blocked"', false);
        $this->remove($admin, $member, $dependent)->assertSessionHas('status', DependentEligibilityService::REMOVAL_BLOCKED_MESSAGE);
        $this->assertDatabaseHas('dependents', ['id' => $dependent->id]);
    }

    public function test_a_deleted_reimbursement_no_longer_blocks_removal(): void
    {
        $member = $this->member();
        $dependent = $this->dependent($member);
        $this->claim($member)->delete();   // soft-deleted

        $this->remove($this->admin(), $member, $dependent)->assertSessionHas('status', "Dependent removed for {$member->code}.");
        $this->assertDatabaseMissing('dependents', ['id' => $dependent->id]);
    }

    public function test_another_members_reimbursement_does_not_block_this_member(): void
    {
        $member = $this->member();
        $dependent = $this->dependent($member);
        $this->claim($this->member());   // a different member's active claim

        $this->remove($this->admin(), $member, $dependent)->assertSessionHas('status', "Dependent removed for {$member->code}.");
        $this->assertDatabaseMissing('dependents', ['id' => $dependent->id]);
    }

    // ---- Test 6: a blocked removal changes nothing about GHP ----

    public function test_a_blocked_removal_leaves_every_ghp_figure_unchanged(): void
    {
        $member = $this->member();
        $dependent = $this->dependent($member);
        $this->claim($member, 500);
        $accrual = app(BenefitAccrualService::class);
        $accrual->accrue($member->fresh());

        $snapshot = function () use ($member, $accrual) {
            $m = $member->fresh();
            $period = $m->benefitPeriods()->firstOrFail();
            $calc = $accrual->calculate($m);
            $req = $accrual->requiredAmountForCycle($m);

            return [
                'member_ghp_amount' => (float) $m->ghp_amount,
                'period_amount' => (float) $period->ghp_amount,
                'period_used' => (float) $period->ghp_used,
                'period_available' => (float) $period->ghp_available,
                'calc_amount' => $calc['ghp_amount'],
                'calc_used' => $calc['used'],
                'calc_available' => $calc['available'],
                'monthly' => $req['monthly_rate'],
                'required' => $req['required_amount'],
                'dependents' => $m->dependents()->count(),
                'reimbursements' => $m->reimbursements()->count(),
            ];
        };

        $before = $snapshot();

        $this->remove($this->admin(), $member, $dependent);   // rejected

        $this->assertEquals($before, $snapshot());
    }

    // ---- Existing rules are untouched ----

    public function test_a_non_admin_still_cannot_remove_and_a_foreign_dependent_is_still_a_404(): void
    {
        $member = $this->member();
        $dependent = $this->dependent($member);
        $other = $this->member();
        $staff = User::factory()->create(['role' => 'staff', 'is_active' => true]);

        $this->actingAs($staff)->delete($this->removeUrl($member, $dependent))->assertForbidden();
        $this->actingAs($this->admin())->delete($this->removeUrl($other, $dependent))->assertNotFound();

        $this->assertDatabaseHas('dependents', ['id' => $dependent->id]);
    }

    public function test_removing_an_eligible_dependent_without_a_reimbursement_still_recalculates_the_amount(): void
    {
        $member = $this->member();
        $dependent = $this->dependent($member);   // eligible (no date gating) -> 4,200
        app(DependentEligibilityService::class)->recalculateBenefit($member);
        $this->assertSame(4200.0, (float) $member->fresh()->ghp_amount);

        $this->remove($this->admin(), $member, $dependent);

        $this->assertSame(3600.0, (float) $member->fresh()->ghp_amount);
    }

    public function test_adding_a_dependent_is_not_blocked_by_an_active_reimbursement(): void
    {
        $member = $this->member();
        $this->claim($member);

        $this->actingAs($this->admin())->post(route('members.dependents.store', $member), [
            'name' => 'Kid One', 'relation' => 'Son', 'birthdate' => '2018-05-20',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('dependents', ['member_id' => $member->id, 'name' => 'Kid One']);
    }
}
