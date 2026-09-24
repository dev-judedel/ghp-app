<?php

namespace Tests\Feature;

use App\Models\BenefitPeriod;
use App\Models\Member;
use App\Models\Reimbursement;
use App\Models\User;
use App\Services\BenefitAccrualService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the Void / Delete addition to Benefit Period Year History
 * (BenefitPeriodController::void()/destroy()): correcting a mistakenly
 * generated Benefit Period without losing history, and the knock-on effect
 * on "Generate this year's benefit period" (MemberController::generateBenefitPeriod())
 * and the live balance shown on the member page (MemberController::show()).
 */
class BenefitPeriodManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-06-15')); // inside Apr 2026–Mar 2027
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

    private function nonAdmin(): User
    {
        return User::factory()->create(['role' => 'staff', 'is_active' => true]);
    }

    private function activeMember(array $overrides = []): Member
    {
        return Member::factory()->create(array_merge([
            'member_type' => Member::MEMBER_TYPE_EMPLOYEE,
            'is_active' => true,
            'deduction_start_date' => '2026-04-01',
            'ghp_amount' => 3600,
            'ghp_amount_is_manual' => false,
        ], $overrides));
    }

    // ---- Void ----

    public function test_an_admin_can_void_a_benefit_period(): void
    {
        $member = $this->activeMember();
        $period = app(BenefitAccrualService::class)->accrue($member);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('members.benefit-periods.void', [$member, $period]), [
            'void_reason' => 'Generated with the wrong dependent count.',
        ])->assertRedirect(route('members.show', $member));

        $period->refresh();
        $this->assertTrue($period->is_voided);
        $this->assertSame('Generated with the wrong dependent count.', $period->voided_reason);
        $this->assertNotNull($period->voided_at);
        $this->assertSame($admin->id, $period->voided_by);
    }

    public function test_a_non_admin_cannot_void_a_benefit_period(): void
    {
        $member = $this->activeMember();
        $period = app(BenefitAccrualService::class)->accrue($member);

        $this->actingAs($this->nonAdmin())->post(route('members.benefit-periods.void', [$member, $period]), [
            'void_reason' => 'Attempted void.',
        ])->assertForbidden();

        $this->assertFalse($period->fresh()->is_voided);
    }

    public function test_voiding_a_benefit_period_requires_a_reason(): void
    {
        $member = $this->activeMember();
        $period = app(BenefitAccrualService::class)->accrue($member);

        $this->actingAs($this->admin())->post(route('members.benefit-periods.void', [$member, $period]), [])
            ->assertSessionHasErrors('void_reason');

        $this->assertFalse($period->fresh()->is_voided);
    }

    public function test_an_already_voided_benefit_period_cannot_be_voided_again(): void
    {
        $member = $this->activeMember();
        $period = app(BenefitAccrualService::class)->accrue($member);
        $period->void('first void', $this->admin());

        $this->actingAs($this->admin())->post(route('members.benefit-periods.void', [$member, $period]), [
            'void_reason' => 'second attempt',
        ]);

        $this->assertSame('first void', $period->fresh()->voided_reason);
    }

    public function test_a_benefit_period_belonging_to_a_different_member_returns_404_on_void(): void
    {
        $member = $this->activeMember();
        $otherMember = $this->activeMember();
        $period = app(BenefitAccrualService::class)->accrue($otherMember);

        $this->actingAs($this->admin())->post(route('members.benefit-periods.void', [$member, $period]), [
            'void_reason' => 'wrong member',
        ])->assertNotFound();
    }

    // ---- Generate button unblocked after Void ----

    public function test_generating_is_blocked_while_an_active_period_exists_for_the_cycle(): void
    {
        $member = $this->activeMember();
        app(BenefitAccrualService::class)->accrue($member);

        $this->actingAs($this->admin())->post(route('members.generate-benefit-period', $member));

        $this->assertSame(1, $member->benefitPeriods()->count());
    }

    public function test_voiding_the_current_periods_benefit_period_allows_generation_again(): void
    {
        $member = $this->activeMember();
        $period = app(BenefitAccrualService::class)->accrue($member);

        $this->actingAs($this->admin())->post(route('members.benefit-periods.void', [$member, $period]), [
            'void_reason' => 'Mistakenly generated.',
        ]);

        $this->actingAs($this->admin())->post(route('members.generate-benefit-period', $member))
            ->assertRedirect(route('members.show', $member));

        // The voided row stays (history) alongside a brand-new active row —
        // never two active rows for the same cycle.
        $this->assertSame(2, $member->benefitPeriods()->count());
        $this->assertSame(1, $member->benefitPeriods()->where('is_voided', false)->count());
        $this->assertSame(1, $member->benefitPeriods()->where('is_voided', true)->count());
    }

    public function test_the_member_pages_current_benefit_period_ignores_a_voided_one(): void
    {
        $member = $this->activeMember();
        $period = app(BenefitAccrualService::class)->accrue($member);
        $period->void('Mistakenly generated.', $this->admin());

        $response = $this->actingAs($this->admin())->get(route('members.show', $member));

        $response->assertOk();
        // No active period exists after the void, so the balance card
        // should not be showing the voided period's stale figures.
        $response->assertViewHas('currentBenefitPeriod', null);
        $response->assertViewHas('currentCyclePeriod', null);
    }

    // ---- Delete ----

    public function test_an_admin_can_delete_a_voided_benefit_period(): void
    {
        $member = $this->activeMember();
        $period = app(BenefitAccrualService::class)->accrue($member);
        $period->void('Mistakenly generated.', $this->admin());

        $this->actingAs($this->admin())->delete(route('members.benefit-periods.destroy', [$member, $period]))
            ->assertRedirect(route('members.show', $member));

        $this->assertDatabaseMissing('benefit_periods', ['id' => $period->id]);
    }

    public function test_an_active_benefit_period_cannot_be_deleted(): void
    {
        $member = $this->activeMember();
        $period = app(BenefitAccrualService::class)->accrue($member);

        $this->actingAs($this->admin())->delete(route('members.benefit-periods.destroy', [$member, $period]))
            ->assertStatus(422);

        $this->assertDatabaseHas('benefit_periods', ['id' => $period->id]);
    }

    public function test_a_non_admin_cannot_delete_a_voided_benefit_period(): void
    {
        $member = $this->activeMember();
        $period = app(BenefitAccrualService::class)->accrue($member);
        $period->void('Mistakenly generated.', $this->admin());

        $this->actingAs($this->nonAdmin())->delete(route('members.benefit-periods.destroy', [$member, $period]))
            ->assertForbidden();

        $this->assertDatabaseHas('benefit_periods', ['id' => $period->id]);
    }

    public function test_deleting_a_benefit_period_does_not_delete_linked_reimbursements(): void
    {
        $member = $this->activeMember();
        $period = app(BenefitAccrualService::class)->accrue($member);

        $reimbursement = Reimbursement::create([
            'member_id' => $member->id,
            'benefit_period_id' => $period->id,
            'or_date' => '2026-05-10',
            'or_amount' => 500,
        ]);

        $period->void('Mistakenly generated.', $this->admin());

        $this->actingAs($this->admin())->delete(route('members.benefit-periods.destroy', [$member, $period]));

        $this->assertDatabaseHas('reimbursements', ['id' => $reimbursement->id]);
        $this->assertNull($reimbursement->fresh()->benefit_period_id);
    }

    public function test_a_benefit_period_belonging_to_a_different_member_returns_404_on_delete(): void
    {
        $member = $this->activeMember();
        $otherMember = $this->activeMember();
        $period = app(BenefitAccrualService::class)->accrue($otherMember);
        $period->void('Mistakenly generated.', $this->admin());

        $this->actingAs($this->admin())->delete(route('members.benefit-periods.destroy', [$member, $period]))
            ->assertNotFound();

        $this->assertDatabaseHas('benefit_periods', ['id' => $period->id]);
    }
}
