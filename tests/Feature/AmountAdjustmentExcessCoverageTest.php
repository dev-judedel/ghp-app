<?php

namespace Tests\Feature;

use App\Models\BenefitAmountAdjustment;
use App\Models\BenefitPeriod;
use App\Models\ExcessCoverage;
use App\Models\Member;
use App\Models\Reimbursement;
use App\Models\User;
use App\Services\AmountAdjustmentService;
use App\Services\BenefitAccrualService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A GHP amount adjustment follows the exact same fund-increase/excess-offset
 * logic as a dependent becoming eligible (see DependentExcessCoverageTest) —
 * BenefitAccrualService::fundIncrease() / applyFundIncreaseToExcess() — but
 * additionally tracks WHICH adjustment covered how much of WHICH claim
 * (ExcessCoverage, keyed by benefit_amount_adjustment_id), so "Revert to
 * automatic" can restore exactly what an adjustment covered instead of
 * guessing from current balances. See task.md §2.29.
 *
 * Same fixture numbers as DependentExcessCoverageTest for direct
 * comparability: today is 2027-01-15 in the Apr 2026 - Mar 2027 cycle,
 * deductions from Apr 2026 => 10 months rendered. Fund = 10 x 300 = 3,000.
 * Adjusting to 4,200 (10 x 350) is a +500 increase, the same size as the
 * dependent test's increase, by design.
 *
 * NOT YET RUN — written from reading the code.
 * Run: php artisan test --filter=AmountAdjustmentExcessCoverageTest
 */
class AmountAdjustmentExcessCoverageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2027-01-15 09:00:00'));
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

    /** A member with a generated current period: fund 3,000 (10 x 300), nothing used. */
    private function member(array $overrides = []): Member
    {
        $member = Member::factory()->create(array_merge([
            'member_type' => Member::MEMBER_TYPE_EMPLOYEE,
            'is_active' => true,
            'deduction_start_date' => '2026-04-01',
            'ghp_amount' => 3600,
            'ghp_amount_is_manual' => false,
            'email' => 'juan@example.com',
        ], $overrides));

        app(BenefitAccrualService::class)->accrue($member->fresh());

        return $member->fresh();
    }

    private function file(Member $member, float $amount, string $date = '2026-12-10', string $orNo = 'OR-1')
    {
        return $this->actingAs($this->admin())->post(route('members.reimbursements.store', $member), [
            'or_no' => $orNo,
            'or_date' => $date,
            'or_amount' => $amount,
        ]);
    }

    private function claim(Member $member, string $orNo = 'OR-1'): Reimbursement
    {
        return Reimbursement::where('member_id', $member->id)->where('or_no', $orNo)->firstOrFail();
    }

    private function period(Member $member): BenefitPeriod
    {
        return $member->benefitPeriods()->firstOrFail()->fresh();
    }

    private function adjust(Member $member, float $newAmount, string $reason = 'Approved salary-grade adjustment'): BenefitAmountAdjustment
    {
        return app(AmountAdjustmentService::class)->apply($member->fresh(), $newAmount, $reason, null, null, $this->admin());
    }

    // ---- Test 1: adjustment without excess ----

    public function test_adjustment_without_excess_increases_available_and_leaves_excess_at_zero(): void
    {
        $member = $this->member();
        $this->file($member, 2000); // within 3,000 -> no excess

        $adjustment = $this->adjust($member, 4200); // +500

        $claim = $this->claim($member);
        $period = $this->period($member);

        $this->assertSame(0.0, (float) $claim->excess_amount);
        $this->assertSame(0.0, (float) $claim->excess_covered_amount);
        $this->assertSame(500.0, (float) $adjustment->increase_amount);
        $this->assertSame(0.0, (float) $adjustment->excess_covered_amount);
        $this->assertSame(500.0, (float) $adjustment->available_increase_amount);
        $this->assertSame(2000.0, (float) $period->ghp_used);
        $this->assertSame(1500.0, (float) $period->ghp_available); // 3,500 - 2,000
    }

    // ---- Test 2: adjustment smaller than excess ----

    public function test_adjustment_smaller_than_excess_only_partly_covers_it(): void
    {
        $member = $this->member();
        $this->file($member, 3750); // excess 750

        $adjustment = $this->adjust($member, 3960); // +300 (10 x 30/mo)

        $claim = $this->claim($member);

        $this->assertSame(300.0, (float) $adjustment->increase_amount);
        $this->assertSame(750.0, (float) $adjustment->outstanding_excess_before);
        $this->assertSame(300.0, (float) $adjustment->excess_covered_amount);
        $this->assertSame(450.0, (float) $adjustment->excess_remaining_after);
        $this->assertSame(0.0, (float) $adjustment->available_increase_amount);

        $this->assertSame(300.0, (float) $claim->excess_covered_amount);
        $this->assertSame(450.0, (float) $claim->excess_amount);
        $this->assertSame(750.0, (float) $claim->original_excess_amount);

        // No ₱300 added to Available GHP — it was absorbed by the excess.
        $this->assertSame(0.0, (float) $this->period($member)->ghp_available);
    }

    // ---- Test 3: adjustment exactly equals excess ----

    public function test_adjustment_exactly_equal_to_excess_zeroes_it_out_with_nothing_left_over(): void
    {
        $member = $this->member();
        $this->file($member, 3500); // excess 500

        $adjustment = $this->adjust($member, 4200); // +500, exactly matches

        $claim = $this->claim($member);

        $this->assertSame(500.0, (float) $adjustment->increase_amount);
        $this->assertSame(500.0, (float) $adjustment->excess_covered_amount);
        $this->assertSame(0.0, (float) $adjustment->excess_remaining_after);
        $this->assertSame(0.0, (float) $adjustment->available_increase_amount);
        $this->assertSame(0.0, (float) $claim->excess_amount);
        $this->assertFalse($claim->hasExcess());
    }

    // ---- Test 4: adjustment greater than excess ----

    public function test_adjustment_greater_than_excess_covers_it_fully_and_the_rest_goes_to_available(): void
    {
        $member = $this->member();
        $this->file($member, 3200); // excess 200

        $adjustment = $this->adjust($member, 4200); // +500

        $claim = $this->claim($member);

        $this->assertSame(500.0, (float) $adjustment->increase_amount);
        $this->assertSame(200.0, (float) $adjustment->excess_covered_amount);
        $this->assertSame(0.0, (float) $adjustment->excess_remaining_after);
        $this->assertSame(300.0, (float) $adjustment->available_increase_amount);
        $this->assertSame(0.0, (float) $claim->excess_amount);
        $this->assertSame(300.0, (float) $this->period($member)->ghp_available);
    }

    // ---- Test 5: revert restores the excess exactly ----

    public function test_reverting_the_adjustment_restores_the_excess_and_removes_the_available_increase(): void
    {
        $member = $this->member();
        $this->file($member, 3750); // excess 750

        $this->adjust($member, 4200); // +500 -> covered 500, remaining 250

        $claim = $this->claim($member);
        $this->assertSame(250.0, (float) $claim->excess_amount);
        $this->assertSame(500.0, (float) $claim->excess_covered_amount);

        $revert = app(AmountAdjustmentService::class)->revertToAutomatic($member->fresh(), $this->admin());

        $claim->refresh();
        $period = $this->period($member);
        $member->refresh();

        $this->assertInstanceOf(BenefitAmountAdjustment::class, $revert);
        $this->assertSame(BenefitAmountAdjustment::KIND_REVERT, $revert->kind);
        $this->assertSame(500.0, (float) $revert->excess_covered_amount); // amount restored

        $this->assertSame(750.0, (float) $claim->excess_amount); // back to the original, not left at 250
        $this->assertSame(0.0, (float) $claim->excess_covered_amount);
        $this->assertSame(750.0, (float) $claim->original_excess_amount); // untouched, as always

        $this->assertFalse($member->ghp_amount_is_manual);
        $this->assertSame(3600.0, (float) $member->ghp_amount); // no eligible dependent -> base rate
        $this->assertSame(0.0, (float) $period->ghp_available); // the +500 available effect is gone too

        // The original adjustment row is marked reverted.
        $original = BenefitAmountAdjustment::where('member_id', $member->id)
            ->where('kind', BenefitAmountAdjustment::KIND_ADJUSTMENT)
            ->firstOrFail();
        $this->assertTrue($original->fresh()->isReverted());

        // The ledger row itself is marked reversed, never deleted.
        $coverage = ExcessCoverage::where('reimbursement_id', $claim->id)->firstOrFail();
        $this->assertNotNull($coverage->reversed_at);
        $this->assertSame(500.0, (float) $coverage->amount);
    }

    public function test_the_original_reimbursement_amount_is_never_changed_by_adjust_or_revert(): void
    {
        $member = $this->member();
        $this->file($member, 3750); // or_amount 3,750 throughout

        $this->adjust($member, 4200);
        $this->assertSame(3750.0, (float) $this->claim($member)->or_amount);

        app(AmountAdjustmentService::class)->revertToAutomatic($member->fresh(), $this->admin());
        $this->assertSame(3750.0, (float) $this->claim($member)->or_amount);
    }

    // ---- Test 6: revert after another transaction leaves it untouched ----

    public function test_reverting_does_not_disturb_a_claim_filed_after_the_adjustment(): void
    {
        $member = $this->member();
        $this->file($member, 3750, orNo: 'OR-1'); // excess 750

        $this->adjust($member, 4200); // covers 500, remaining 250, available 0

        // A second, unrelated claim filed AFTER the adjustment — no available
        // balance left (0), so file it directly to avoid the balance gate.
        $member->reimbursements()->create([
            'or_no' => 'OR-2', 'or_date' => '2026-12-20', 'or_amount' => 100,
            'excess_amount' => 100, 'original_excess_amount' => 100,
        ]);

        app(AmountAdjustmentService::class)->revertToAutomatic($member->fresh(), $this->admin());

        // OR-1 is restored to its original excess...
        $this->assertSame(750.0, (float) $this->claim($member, 'OR-1')->excess_amount);
        // ...and OR-2, which the adjustment never touched, is completely unaffected.
        $second = $this->claim($member, 'OR-2');
        $this->assertSame(100.0, (float) $second->excess_amount);
        $this->assertSame(0.0, (float) $second->excess_covered_amount);
        $this->assertSame(100.0, (float) $second->or_amount);
    }

    // ---- Test 7: sequential adjustments are each tracked separately, and "revert" undoes the whole chain ----

    public function test_two_sequential_adjustments_each_own_their_coverage_and_both_are_reverted_together(): void
    {
        $member = $this->member();
        $this->file($member, 3750); // excess 750

        $first = $this->adjust($member, 3960);  // +300 -> covers 300, remaining 450
        $this->assertSame(300.0, (float) $first->excess_covered_amount);

        $second = $this->adjust($member, 4200); // moves 3,960 -> 4,200: +240 (10 x 24/mo) -> covers remaining 450? only 240 available
        // fund at 3,960 was 3,300 (10 x 330); at 4,200 it's 3,500 -> increase is 200.
        $this->assertSame(200.0, (float) $second->increase_amount);
        $this->assertSame(200.0, (float) $second->excess_covered_amount);

        $claim = $this->claim($member);
        $this->assertSame(500.0, (float) $claim->excess_covered_amount); // 300 + 200
        $this->assertSame(250.0, (float) $claim->excess_amount);         // 750 - 500

        // Each adjustment owns its own ledger rows.
        $this->assertSame(300.0, (float) ExcessCoverage::where('benefit_amount_adjustment_id', $first->id)->sum('amount'));
        $this->assertSame(200.0, (float) ExcessCoverage::where('benefit_amount_adjustment_id', $second->id)->sum('amount'));

        // "Revert to automatic" undoes the WHOLE chain at once (single-override architecture — task.md §2.29).
        app(AmountAdjustmentService::class)->revertToAutomatic($member->fresh(), $this->admin());

        $this->assertSame(750.0, (float) $this->claim($member)->excess_amount);
        $this->assertTrue($first->fresh()->isReverted());
        $this->assertTrue($second->fresh()->isReverted());
    }

    // ---- Test 8: refresh / reopen never applies an adjustment's coverage twice ----

    public function test_refreshing_or_reopening_never_applies_the_coverage_twice(): void
    {
        $member = $this->member();
        $this->file($member, 3750);

        $this->adjust($member, 4200);
        $this->assertSame(500.0, (float) $this->claim($member)->excess_covered_amount);

        // Re-running the underlying refresh directly (same as a page view would trigger indirectly).
        app(BenefitAccrualService::class)->refreshCurrentPeriodIfAmountChanged($member->fresh());
        app(BenefitAccrualService::class)->refreshCurrentPeriodIfAmountChanged($member->fresh());

        $admin = $this->admin();
        $this->actingAs($admin)->get(route('members.show', $member))->assertOk();
        $this->actingAs($admin)->get(route('members.show', $member))->assertOk();

        $this->assertSame(500.0, (float) $this->claim($member)->excess_covered_amount);
        $this->assertSame(250.0, (float) $this->claim($member)->excess_amount);
    }

    // ---- Test 9: inactive member ----

    public function test_adjusting_an_inactive_member_is_rejected_by_the_backend(): void
    {
        $member = $this->member(['is_active' => false]);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('members.amount-adjustments.store', $member), [
            'new_amount' => 5000,
            'reason' => 'Should be blocked',
        ])->assertRedirect();

        $this->assertSame(3600.0, (float) $member->fresh()->ghp_amount);
        $this->assertFalse($member->fresh()->ghp_amount_is_manual);
        $this->assertSame(0, BenefitAmountAdjustment::where('member_id', $member->id)->count());

        $this->actingAs($admin)
            ->post(route('members.amount-adjustments.revert-to-automatic', $member))
            ->assertRedirect();
    }

    // ---- Test 10: unauthorized / direct backend bypass ----

    public function test_a_non_admin_cannot_adjust_or_revert(): void
    {
        $member = $this->member();
        $staff = User::factory()->create(['role' => 'user', 'is_active' => true]);

        $this->actingAs($staff)->post(route('members.amount-adjustments.store', $member), [
            'new_amount' => 5000,
            'reason' => 'Attempted bypass',
        ])->assertForbidden();

        $this->actingAs($staff)
            ->post(route('members.amount-adjustments.revert-to-automatic', $member))
            ->assertForbidden();

        $this->assertSame(3600.0, (float) $member->fresh()->ghp_amount);
    }

    // ---- "Already automatic" guard, unchanged from before this feature ----

    public function test_reverting_when_already_automatic_does_nothing(): void
    {
        $member = $this->member();

        $result = app(AmountAdjustmentService::class)->revertToAutomatic($member->fresh(), $this->admin());

        $this->assertIsString($result);
        $this->assertSame(0, BenefitAmountAdjustment::where('member_id', $member->id)->count());
    }

    // ---- A decrease never covers excess ----

    public function test_a_decrease_never_covers_excess(): void
    {
        $member = $this->member(['ghp_amount' => 4200, 'ghp_amount_is_manual' => true]);
        app(BenefitAccrualService::class)->accrue($member->fresh()); // fund at 4,200: 3,500
        $this->file($member, 3700); // excess 200

        $adjustment = $this->adjust($member, 3600); // a decrease

        $this->assertLessThan(0, (float) $adjustment->increase_amount);
        $this->assertSame(0.0, (float) $adjustment->excess_covered_amount);
        $this->assertSame(200.0, (float) $this->claim($member)->excess_amount); // untouched
    }
}
