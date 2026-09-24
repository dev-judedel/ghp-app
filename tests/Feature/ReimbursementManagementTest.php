<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Reimbursement;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers ReimbursementController: filing, editing, voiding/unvoiding, the
 * benefit-period balance refresh that follows each of those actions, and
 * the benefit-period linking used by the Coverage Year History drill-down.
 * This controller touches financial data directly (GHP fund balances) and
 * had no test coverage before this file — flagged as the riskiest gap in
 * task.md.
 */
class ReimbursementManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Frozen inside the member's own coverage period (Apr 2026–Mar
        // 2027), late enough that the full 12 months have already elapsed
        // by the time calculate() defaults to Carbon::now(). Without this,
        // these tests depend on what today's real date happens to be —
        // a deduction_start_date of 2026-04-01 only shows ~6 months
        // accrued as of the actual current date, not the full ₱3,600
        // these tests assert against.
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

    // ---- Filing (store) ----

    public function test_an_admin_can_file_a_reimbursement(): void
    {
        $member = $this->activeMember();

        $this->actingAs($this->admin())->post(route('members.reimbursements.store', $member), [
            'or_no' => 'OR-001',
            'or_date' => '2026-05-10',
            'or_amount' => 500,
            'hospital_name' => 'City Hospital',
        ])->assertRedirect(route('members.show', $member));

        $this->assertDatabaseHas('reimbursements', [
            'member_id' => $member->id,
            'or_no' => 'OR-001',
            'or_amount' => 500,
        ]);
    }

    public function test_a_non_admin_cannot_file_a_reimbursement(): void
    {
        $member = $this->activeMember();

        $this->actingAs($this->nonAdmin())->post(route('members.reimbursements.store', $member), [
            'or_date' => '2026-05-10',
            'or_amount' => 500,
        ])->assertForbidden();

        $this->assertDatabaseMissing('reimbursements', ['member_id' => $member->id]);
    }

    public function test_or_date_is_required(): void
    {
        $member = $this->activeMember();

        $this->actingAs($this->admin())->post(route('members.reimbursements.store', $member), [
            'or_amount' => 500,
        ])->assertSessionHasErrors('or_date');
    }

    public function test_or_amount_is_required_and_must_be_positive(): void
    {
        $member = $this->activeMember();

        $this->actingAs($this->admin())->post(route('members.reimbursements.store', $member), [
            'or_date' => '2026-05-10',
            'or_amount' => 0,
        ])->assertSessionHasErrors('or_amount');
    }

    public function test_filing_a_reimbursement_refreshes_the_current_benefit_period_balance(): void
    {
        $member = $this->activeMember(); // full cycle, 3600 available before any claim

        $this->actingAs($this->admin())->post(route('members.reimbursements.store', $member), [
            'or_date' => '2026-05-10',
            'or_amount' => 500,
        ]);

        $period = $member->benefitPeriods()->first();

        $this->assertNotNull($period);
        $this->assertSame(500.0, (float) $period->ghp_used);
        $this->assertSame(3100.0, (float) $period->ghp_available);
    }

    public function test_filing_a_reimbursement_links_it_to_the_matching_benefit_period(): void
    {
        $member = $this->activeMember();

        $this->actingAs($this->admin())->post(route('members.reimbursements.store', $member), [
            'or_date' => '2026-05-10',
            'or_amount' => 500,
        ]);

        $reimbursement = Reimbursement::where('member_id', $member->id)->firstOrFail();
        $period = $member->benefitPeriods()->first();

        $this->assertSame($period->id, $reimbursement->benefit_period_id);
    }

    /**
     * Business rule as of the GHP Reimbursement Rule change: a claim that
     * exceeds the available balance is rejected outright and never saved
     * — this replaces the previous "record in full, cap what counts
     * against the fund" behavior (see BenefitAccrualService's class-level
     * doc comment and ReimbursementController::assertWithinBalance()).
     */
    public function test_a_claim_exceeding_the_available_balance_is_rejected_and_not_saved(): void
    {
        $member = $this->activeMember(); // 3600 available
        app(\App\Services\BenefitAccrualService::class)->accrue($member);

        $this->actingAs($this->admin())->post(route('members.reimbursements.store', $member), [
            'or_date' => '2026-05-10',
            'or_amount' => 5000, // exceeds the 3600 available
        ])->assertSessionHasErrors('or_amount');

        $this->assertDatabaseMissing('reimbursements', ['member_id' => $member->id]);

        $period = $member->benefitPeriods()->first();
        $this->assertSame(0.0, (float) $period->ghp_used);
        $this->assertSame(3600.0, (float) $period->ghp_available);
    }

    public function test_a_claim_exactly_equal_to_the_available_balance_is_allowed(): void
    {
        $member = $this->activeMember(); // 3600 available

        $this->actingAs($this->admin())->post(route('members.reimbursements.store', $member), [
            'or_date' => '2026-05-10',
            'or_amount' => 3600,
        ])->assertRedirect(route('members.show', $member));

        $reimbursement = Reimbursement::where('member_id', $member->id)->firstOrFail();
        $period = $member->benefitPeriods()->first();

        $this->assertSame(3600.0, (float) $reimbursement->or_amount);
        $this->assertSame(3600.0, (float) $period->ghp_used);
        $this->assertSame(0.0, (float) $period->ghp_available);
    }

    public function test_a_second_claim_that_would_exceed_the_remaining_balance_is_rejected(): void
    {
        $member = $this->activeMember(); // 3600 available

        $this->actingAs($this->admin())->post(route('members.reimbursements.store', $member), [
            'or_date' => '2026-05-10',
            'or_amount' => 3000,
        ]);

        // 600 left — 601 should be rejected, 600 should be allowed.
        $this->actingAs($this->admin())->post(route('members.reimbursements.store', $member), [
            'or_date' => '2026-06-10',
            'or_amount' => 601,
        ])->assertSessionHasErrors('or_amount');

        $this->assertDatabaseMissing('reimbursements', ['member_id' => $member->id, 'or_amount' => 601]);

        $period = $member->benefitPeriods()->first();
        $this->assertSame(3000.0, (float) $period->ghp_used);
        $this->assertSame(600.0, (float) $period->ghp_available);
    }

    public function test_editing_a_reimbursement_to_exceed_the_balance_is_rejected(): void
    {
        $member = $this->activeMember(); // 3600 available
        $reimbursement = $member->reimbursements()->create([
            'or_date' => '2026-05-10',
            'or_amount' => 500,
        ]);
        app(\App\Services\BenefitAccrualService::class)->accrue($member);

        // 3100 remaining after the existing 500 claim — raising it to 4000
        // would need 3500 more than what's left, so it must be rejected and
        // the original amount must stay untouched.
        $this->actingAs($this->admin())->put(route('members.reimbursements.update', [$member, $reimbursement]), [
            'or_date' => '2026-05-10',
            'or_amount' => 4000,
        ])->assertSessionHasErrors('or_amount');

        $this->assertSame(500.0, (float) $reimbursement->fresh()->or_amount);
    }

    // ---- Editing (update) ----

    public function test_an_admin_can_update_a_reimbursement(): void
    {
        $member = $this->activeMember();
        $reimbursement = $member->reimbursements()->create([
            'or_no' => 'OR-001',
            'or_date' => '2026-05-10',
            'or_amount' => 500,
        ]);

        $this->actingAs($this->admin())->put(route('members.reimbursements.update', [$member, $reimbursement]), [
            'or_no' => 'OR-001-REVISED',
            'or_date' => '2026-05-10',
            'or_amount' => 700,
        ])->assertRedirect(route('members.show', $member));

        $reimbursement->refresh();
        $this->assertSame('OR-001-REVISED', $reimbursement->or_no);
        $this->assertSame(700.0, (float) $reimbursement->or_amount);
    }

    public function test_updating_a_reimbursement_refreshes_the_benefit_period_balance(): void
    {
        $member = $this->activeMember();
        $reimbursement = $member->reimbursements()->create([
            'or_date' => '2026-05-10',
            'or_amount' => 500,
        ]);
        app(\App\Services\BenefitAccrualService::class)->accrue($member);

        $this->actingAs($this->admin())->put(route('members.reimbursements.update', [$member, $reimbursement]), [
            'or_date' => '2026-05-10',
            'or_amount' => 900,
        ]);

        $period = $member->benefitPeriods()->first();
        $this->assertSame(900.0, (float) $period->ghp_used);
    }

    public function test_a_voided_reimbursement_cannot_be_edited_directly(): void
    {
        $member = $this->activeMember();
        $reimbursement = $member->reimbursements()->create([
            'or_date' => '2026-05-10',
            'or_amount' => 500,
        ]);
        $reimbursement->void('duplicate entry', $this->admin());

        $this->actingAs($this->admin())->put(route('members.reimbursements.update', [$member, $reimbursement]), [
            'or_date' => '2026-05-10',
            'or_amount' => 900,
        ]);

        $this->assertSame(500.0, (float) $reimbursement->fresh()->or_amount);
    }

    public function test_a_reimbursement_belonging_to_a_different_member_returns_404(): void
    {
        $member = $this->activeMember();
        $otherMember = $this->activeMember();
        $reimbursement = $otherMember->reimbursements()->create([
            'or_date' => '2026-05-10',
            'or_amount' => 500,
        ]);

        $this->actingAs($this->admin())->put(route('members.reimbursements.update', [$member, $reimbursement]), [
            'or_date' => '2026-05-10',
            'or_amount' => 900,
        ])->assertNotFound();
    }

    // ---- Voiding ----

    public function test_an_admin_can_void_a_reimbursement(): void
    {
        $member = $this->activeMember();
        $reimbursement = $member->reimbursements()->create([
            'or_date' => '2026-05-10',
            'or_amount' => 500,
        ]);

        $this->actingAs($this->admin())->post(route('members.reimbursements.void', [$member, $reimbursement]), [
            'reason' => 'Filed in error',
        ])->assertRedirect(route('members.show', $member));

        $reimbursement->refresh();
        $this->assertTrue($reimbursement->is_voided);
        $this->assertSame('Filed in error', $reimbursement->voided_reason);
        $this->assertNotNull($reimbursement->voided_at);
    }

    public function test_voiding_requires_a_reason(): void
    {
        $member = $this->activeMember();
        $reimbursement = $member->reimbursements()->create([
            'or_date' => '2026-05-10',
            'or_amount' => 500,
        ]);

        $this->actingAs($this->admin())->post(route('members.reimbursements.void', [$member, $reimbursement]), [])
            ->assertSessionHasErrors('reason');

        $this->assertFalse($reimbursement->fresh()->is_voided);
    }

    public function test_voiding_a_reimbursement_removes_it_from_the_fund_balance(): void
    {
        $member = $this->activeMember(); // 3600 available
        $reimbursement = $member->reimbursements()->create([
            'or_date' => '2026-05-10',
            'or_amount' => 500,
        ]);
        app(\App\Services\BenefitAccrualService::class)->accrue($member);

        $this->actingAs($this->admin())->post(route('members.reimbursements.void', [$member, $reimbursement]), [
            'reason' => 'Filed in error',
        ]);

        $period = $member->benefitPeriods()->first();
        $this->assertSame(0.0, (float) $period->ghp_used);
        $this->assertSame(3600.0, (float) $period->ghp_available);
    }

    public function test_an_already_voided_reimbursement_cannot_be_voided_again(): void
    {
        $member = $this->activeMember();
        $reimbursement = $member->reimbursements()->create([
            'or_date' => '2026-05-10',
            'or_amount' => 500,
        ]);
        $reimbursement->void('first void', $this->admin());

        $this->actingAs($this->admin())->post(route('members.reimbursements.void', [$member, $reimbursement]), [
            'reason' => 'second attempt',
        ]);

        // Original reason/timestamp untouched by the second, ignored attempt.
        $this->assertSame('first void', $reimbursement->fresh()->voided_reason);
    }

    // ---- Unvoiding ----

    public function test_an_admin_can_unvoid_a_reimbursement(): void
    {
        $member = $this->activeMember();
        $reimbursement = $member->reimbursements()->create([
            'or_date' => '2026-05-10',
            'or_amount' => 500,
        ]);
        $reimbursement->void('filed in error', $this->admin());

        $this->actingAs($this->admin())->post(route('members.reimbursements.unvoid', [$member, $reimbursement]))
            ->assertRedirect(route('members.show', $member));

        $reimbursement->refresh();
        $this->assertFalse($reimbursement->is_voided);
        $this->assertNull($reimbursement->voided_reason);
        $this->assertNull($reimbursement->voided_at);
    }

    public function test_unvoiding_a_reimbursement_restores_it_to_the_fund_balance(): void
    {
        $member = $this->activeMember();
        $reimbursement = $member->reimbursements()->create([
            'or_date' => '2026-05-10',
            'or_amount' => 500,
        ]);
        $reimbursement->void('filed in error', $this->admin());
        app(\App\Services\BenefitAccrualService::class)->accrue($member);

        $this->actingAs($this->admin())->post(route('members.reimbursements.unvoid', [$member, $reimbursement]));

        $period = $member->benefitPeriods()->first();
        $this->assertSame(500.0, (float) $period->ghp_used);
    }

    public function test_a_non_voided_reimbursement_cannot_be_unvoided(): void
    {
        $member = $this->activeMember();
        $reimbursement = $member->reimbursements()->create([
            'or_date' => '2026-05-10',
            'or_amount' => 500,
        ]);

        $this->actingAs($this->admin())->post(route('members.reimbursements.unvoid', [$member, $reimbursement]))
            ->assertRedirect(route('members.show', $member));

        $this->assertFalse($reimbursement->fresh()->is_voided);
    }
}
