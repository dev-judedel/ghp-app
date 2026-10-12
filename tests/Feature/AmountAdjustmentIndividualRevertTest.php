<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureMemberIsActive;
use App\Models\BenefitAmountAdjustment;
use App\Models\ExcessCoverage;
use App\Models\Member;
use App\Models\Reimbursement;
use App\Models\User;
use App\Services\AmountAdjustmentService;
use App\Services\BenefitAccrualService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Reverting ONE GHP amount adjustment (task.md §2.31) — as opposed to
 * "Revert to automatic", which undoes the whole chain (covered in
 * AmountAdjustmentExcessCoverageTest / AmountAdjustmentFollowUpTest).
 *
 * Only the member's most recent ACTIVE adjustment can be reverted on its own:
 * its own ledger rows go back to their claims, the amount returns to what it
 * was before it, and nothing belonging to an earlier adjustment, another
 * source of cover, or a later transaction is touched.
 *
 * Same fixture as the other adjustment tests: today is 2027-01-15 in the
 * Apr 2026 - Mar 2027 cycle, deductions from Apr 2026 => 10 months rendered.
 *   fund at 3,600 = 10 x 300 = 3,000
 *   fund at 3,960 = 10 x 330 = 3,300   (+300)
 *   fund at 4,200 = 10 x 350 = 3,500   (+200 more, +500 in total)
 *
 * NOT YET RUN — written from reading the code.
 * Run: php artisan test --filter=AmountAdjustmentIndividualRevertTest
 */
class AmountAdjustmentIndividualRevertTest extends TestCase
{
    use RefreshDatabase;

    private bool $failReimbursementUpdates = false;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2027-01-15 09:00:00'));

        // Inert unless a test flips the flag (used by the rollback test).
        Reimbursement::updating(function () {
            if ($this->failReimbursementUpdates) {
                throw new RuntimeException('simulated failure while restoring a claim');
            }
        });
    }

    protected function tearDown(): void
    {
        $this->failReimbursementUpdates = false;
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'is_active' => true]);
    }

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

    private function file(Member $member, float $amount, string $orNo = 'OR-1')
    {
        return $this->actingAs($this->admin())->post(route('members.reimbursements.store', $member), [
            'or_no' => $orNo,
            'or_date' => '2026-12-10',
            'or_amount' => $amount,
        ]);
    }

    private function claim(Member $member, string $orNo = 'OR-1'): Reimbursement
    {
        return Reimbursement::where('member_id', $member->id)->where('or_no', $orNo)->firstOrFail();
    }

    private function adjust(Member $member, float $newAmount): BenefitAmountAdjustment
    {
        return app(AmountAdjustmentService::class)->apply($member->fresh(), $newAmount, 'Approved adjustment', null, null, $this->admin());
    }

    private function revert(Member $member, BenefitAmountAdjustment $adjustment): BenefitAmountAdjustment|string
    {
        return app(AmountAdjustmentService::class)->revertAdjustment($member->fresh(), $adjustment, $this->admin());
    }

    /** Member + claim: 3,750 against a fund of 3,000 => excess 750; then +300 then +200 covers 500. */
    private function twoAdjustments(): array
    {
        $member = $this->member();
        $this->file($member, 3750);                     // excess 750

        $first = $this->adjust($member, 3960);          // +300 -> covers 300, 450 left
        $second = $this->adjust($member, 4200);         // +200 -> covers 200, 250 left

        return [$member, $first, $second];
    }

    // ---- Test 7: two adjustments, revert only #2, #1 stays intact ----

    public function test_reverting_the_latest_of_two_adjustments_reverses_only_its_own_effect(): void
    {
        [$member, $first, $second] = $this->twoAdjustments();

        $claim = $this->claim($member);
        $this->assertSame(250.0, (float) $claim->excess_amount);
        $this->assertSame(500.0, (float) $claim->excess_covered_amount);

        $revert = $this->revert($member, $second);

        $this->assertInstanceOf(BenefitAmountAdjustment::class, $revert);
        $this->assertSame(BenefitAmountAdjustment::KIND_REVERT, $revert->kind);
        $this->assertSame(200.0, (float) $revert->excess_covered_amount);   // amount RESTORED on a revert row

        $claim->refresh();
        // #2's 200 went back; #1's 300 is still covered. NOT back to the original 750.
        $this->assertSame(450.0, (float) $claim->excess_amount);
        $this->assertSame(300.0, (float) $claim->excess_covered_amount);
        $this->assertSame(750.0, (float) $claim->original_excess_amount);   // never overwritten
        $this->assertSame(3750.0, (float) $claim->or_amount);               // never changed

        // The member is back on adjustment #1's amount, still a manual override.
        $member->refresh();
        $this->assertSame(3960.0, (float) $member->ghp_amount);
        $this->assertTrue($member->ghp_amount_is_manual);

        // #1 untouched (still active, its ledger rows not reversed); #2 reverted.
        $this->assertFalse($first->fresh()->isReverted());
        $this->assertTrue($second->fresh()->isReverted());
        $this->assertNull(ExcessCoverage::where('benefit_amount_adjustment_id', $first->id)->firstOrFail()->reversed_at);
        $this->assertNotNull(ExcessCoverage::where('benefit_amount_adjustment_id', $second->id)->firstOrFail()->reversed_at);

        // The period follows: fund 3,300, claim 3,750 => nothing available; used capped at the fund.
        $period = $member->benefitPeriods()->firstOrFail()->fresh();
        $this->assertSame(3960.0, (float) $period->ghp_amount);
        $this->assertSame(0.0, (float) $period->ghp_available);
    }

    public function test_the_earlier_adjustment_can_then_be_reverted_and_the_member_returns_to_the_original_state(): void
    {
        [$member, $first, $second] = $this->twoAdjustments();

        $this->revert($member, $second);
        $this->revert($member, $first);

        $claim = $this->claim($member)->refresh();
        $member->refresh();

        $this->assertSame(750.0, (float) $claim->excess_amount);
        $this->assertSame(0.0, (float) $claim->excess_covered_amount);
        $this->assertFalse($member->ghp_amount_is_manual);   // override released: nothing active remains
        $this->assertSame(3600.0, (float) $member->ghp_amount);
    }

    public function test_an_earlier_adjustment_cannot_be_reverted_underneath_a_later_one(): void
    {
        [$member, $first, $second] = $this->twoAdjustments();

        $before = [
            $this->claim($member)->only(['excess_amount', 'excess_covered_amount']),
            $member->fresh()->only(['ghp_amount', 'ghp_amount_is_manual']),
            ExcessCoverage::whereNull('reversed_at')->count(),
            BenefitAmountAdjustment::count(),
        ];

        $result = $this->revert($member, $first);

        $this->assertIsString($result);
        $this->assertStringContainsString('most recent active', $result);

        $this->assertEquals($before, [
            $this->claim($member)->only(['excess_amount', 'excess_covered_amount']),
            $member->fresh()->only(['ghp_amount', 'ghp_amount_is_manual']),
            ExcessCoverage::whereNull('reversed_at')->count(),
            BenefitAmountAdjustment::count(),
        ]);
        $this->assertFalse($first->fresh()->isReverted());
        $this->assertFalse($second->fresh()->isReverted());
    }

    // ---- Test 5: a single adjustment reverts to the exact pre-adjustment state ----

    public function test_reverting_the_only_adjustment_restores_the_original_excess_and_releases_the_override(): void
    {
        $member = $this->member();
        $this->file($member, 3750);                     // excess 750
        $adjustment = $this->adjust($member, 4200);     // +500 -> covers 500, 250 left

        $this->revert($member, $adjustment);

        $claim = $this->claim($member)->refresh();
        $member->refresh();

        $this->assertSame(750.0, (float) $claim->excess_amount);
        $this->assertSame(0.0, (float) $claim->excess_covered_amount);
        $this->assertSame(3750.0, (float) $claim->or_amount);
        $this->assertFalse($member->ghp_amount_is_manual);
        $this->assertSame(3600.0, (float) $member->ghp_amount);
        $this->assertTrue($adjustment->fresh()->isReverted());
        $this->assertNotNull(ExcessCoverage::where('benefit_amount_adjustment_id', $adjustment->id)->firstOrFail()->reversed_at);
    }

    public function test_reverting_an_adjustment_that_had_no_excess_to_cover_removes_its_available_increase(): void
    {
        $member = $this->member();
        $this->file($member, 2000);                     // within the 3,000 fund: no excess
        $adjustment = $this->adjust($member, 4200);     // +500 -> all of it to Available

        $period = $member->benefitPeriods()->firstOrFail();
        $this->assertSame(1500.0, (float) $period->fresh()->ghp_available);   // 3,500 - 2,000

        $revert = $this->revert($member, $adjustment);

        $this->assertNull($revert->excess_covered_amount);                    // nothing to give back to a claim
        $this->assertSame(1000.0, (float) $period->fresh()->ghp_available);   // 3,000 - 2,000: the +500 is gone
        $this->assertSame(2000.0, (float) $period->fresh()->ghp_used);        // Used GHP untouched throughout
        $this->assertSame(0.0, (float) $this->claim($member)->excess_amount);
        $this->assertSame(2000.0, (float) $this->claim($member)->or_amount);
    }

    public function test_reverting_when_the_adjustment_exceeded_the_excess_removes_the_leftover_available_and_restores_the_excess(): void
    {
        $member = $this->member();
        $this->file($member, 3200);                     // excess 200
        $adjustment = $this->adjust($member, 4200);     // +500 -> covers 200, 300 to Available

        $this->assertSame(300.0, (float) $member->benefitPeriods()->firstOrFail()->fresh()->ghp_available);

        $this->revert($member, $adjustment);

        $this->assertSame(200.0, (float) $this->claim($member)->refresh()->excess_amount);   // restored
        $this->assertSame(0.0, (float) $member->benefitPeriods()->firstOrFail()->fresh()->ghp_available);   // 300 removed
    }

    // ---- Test 6: other transactions survive the revert ----

    public function test_reverting_one_adjustment_leaves_a_later_claim_and_an_earlier_adjustments_cover_alone(): void
    {
        [$member, $first, $second] = $this->twoAdjustments();

        // A claim filed AFTER both adjustments, with its own excess.
        $member->reimbursements()->create([
            'or_no' => 'OR-2', 'or_date' => '2026-12-20', 'or_amount' => 100,
            'excess_amount' => 100, 'original_excess_amount' => 100,
        ]);

        $this->revert($member, $second);

        $later = $this->claim($member, 'OR-2')->refresh();
        $this->assertSame(100.0, (float) $later->excess_amount);
        $this->assertSame(0.0, (float) $later->excess_covered_amount);
        $this->assertSame(100.0, (float) $later->or_amount);

        // OR-1: only #2's 200 came back.
        $this->assertSame(450.0, (float) $this->claim($member)->refresh()->excess_amount);
        $this->assertSame(300.0, (float) $this->claim($member)->excess_covered_amount);
    }

    public function test_reverting_gives_back_only_its_own_share_when_another_source_also_covered_part_of_the_claim(): void
    {
        $member = $this->member();
        $this->file($member, 3750);                                                   // original excess 750
        $this->claim($member)->update(['excess_covered_amount' => 100, 'excess_amount' => 650]);   // e.g. a dependent covered 100
        $adjustment = $this->adjust($member, 4200);                                   // +500 covers 500 of the remaining 650

        $this->revert($member, $adjustment);

        $claim = $this->claim($member)->refresh();
        $this->assertSame(650.0, (float) $claim->excess_amount);          // NOT 750
        $this->assertSame(100.0, (float) $claim->excess_covered_amount);
    }

    // ---- Never twice ----

    public function test_a_second_revert_of_the_same_adjustment_is_refused_and_restores_nothing_more(): void
    {
        [$member, , $second] = $this->twoAdjustments();

        $this->revert($member, $second);
        $excessAfterFirst = (float) $this->claim($member)->refresh()->excess_amount;
        $rows = BenefitAmountAdjustment::count();

        $again = $this->revert($member, $second->fresh());

        $this->assertIsString($again);
        $this->assertStringContainsString('already been reverted', $again);
        $this->assertSame($excessAfterFirst, (float) $this->claim($member)->refresh()->excess_amount);
        $this->assertSame($rows, BenefitAmountAdjustment::count());   // no extra history row
    }

    public function test_a_revert_history_row_is_not_itself_revertible(): void
    {
        [$member, , $second] = $this->twoAdjustments();
        $revertRow = $this->revert($member, $second);

        $result = $this->revert($member, $revertRow);

        $this->assertIsString($result);
        $this->assertStringContainsString('Only a GHP amount adjustment', $result);
    }

    // ---- Interplay with "Revert to automatic" ----

    public function test_revert_to_automatic_after_an_individual_revert_restores_only_what_is_still_covered(): void
    {
        [$member, , $second] = $this->twoAdjustments();

        $this->revert($member, $second);                                 // #2's 200 already restored
        app(AmountAdjustmentService::class)->revertToAutomatic($member->fresh(), $this->admin());

        $claim = $this->claim($member)->refresh();
        // 750 original: #2's 200 restored once, #1's 300 restored once — nothing restored twice.
        $this->assertSame(750.0, (float) $claim->excess_amount);
        $this->assertSame(0.0, (float) $claim->excess_covered_amount);
    }

    // ---- Test 17: transaction ----

    public function test_a_failure_while_reverting_rolls_everything_back(): void
    {
        [$member, , $second] = $this->twoAdjustments();

        $before = [
            $this->claim($member)->only(['excess_amount', 'excess_covered_amount']),
            $member->fresh()->only(['ghp_amount', 'ghp_amount_is_manual']),
            ExcessCoverage::whereNull('reversed_at')->count(),
            BenefitAmountAdjustment::count(),
        ];

        $this->failReimbursementUpdates = true;

        try {
            $this->revert($member, $second);
            $this->fail('The simulated failure should have propagated.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('simulated failure', $e->getMessage());
        }

        $this->failReimbursementUpdates = false;

        $this->assertEquals($before, [
            $this->claim($member)->only(['excess_amount', 'excess_covered_amount']),
            $member->fresh()->only(['ghp_amount', 'ghp_amount_is_manual']),
            ExcessCoverage::whereNull('reversed_at')->count(),
            BenefitAmountAdjustment::count(),
        ]);
        $this->assertFalse($second->fresh()->isReverted());
    }

    // ---- Test 9 / 10: inactive member, unauthorized, direct requests ----

    public function test_the_service_refuses_to_revert_an_adjustment_of_an_inactive_member(): void
    {
        [$member, , $second] = $this->twoAdjustments();
        $member->update(['is_active' => false, 'resignation_date' => '2027-01-10']);
        $before = $this->claim($member)->only(['excess_amount', 'excess_covered_amount']);

        try {
            $this->revert($member, $second);
            $this->fail('Reverting an inactive member should have been rejected.');
        } catch (RuntimeException $e) {
            $this->assertSame(EnsureMemberIsActive::MESSAGE, $e->getMessage());
        }

        $this->assertEquals($before, $this->claim($member)->only(['excess_amount', 'excess_covered_amount']));
        $this->assertFalse($second->fresh()->isReverted());
    }

    public function test_a_direct_request_for_an_inactive_member_is_rejected_before_the_controller(): void
    {
        [$member, , $second] = $this->twoAdjustments();
        $member->update(['is_active' => false, 'resignation_date' => '2027-01-10']);

        $this->actingAs($this->admin())
            ->post(route('members.amount-adjustments.revert', [$member, $second]))
            ->assertRedirect(route('members.show', $member))
            ->assertSessionHas('status', EnsureMemberIsActive::MESSAGE);

        $this->assertFalse($second->fresh()->isReverted());
        $this->assertSame(250.0, (float) $this->claim($member)->excess_amount);
    }

    public function test_a_non_admin_and_a_guest_cannot_revert_an_adjustment(): void
    {
        [$member, , $second] = $this->twoAdjustments();
        $staff = User::factory()->create(['role' => 'user', 'is_active' => true]);

        $this->actingAs($staff)->post(route('members.amount-adjustments.revert', [$member, $second]))->assertForbidden();
        $this->assertFalse($second->fresh()->isReverted());

        auth()->logout();
        $this->post(route('members.amount-adjustments.revert', [$member, $second]))->assertRedirect(route('login'));
    }

    public function test_an_adjustment_belonging_to_another_member_is_a_404(): void
    {
        [$member] = $this->twoAdjustments();
        $other = $this->member(['email' => 'other@example.com']);
        $otherAdjustment = $this->adjust($other, 4200);

        $this->actingAs($this->admin())
            ->post(route('members.amount-adjustments.revert', [$member, $otherAdjustment]))
            ->assertNotFound();

        $this->assertFalse($otherAdjustment->fresh()->isReverted());
    }

    // ---- Through the real route, once ----

    public function test_the_route_reverts_the_latest_adjustment_and_a_repeat_changes_nothing(): void
    {
        [$member, , $second] = $this->twoAdjustments();
        $admin = $this->admin();
        $url = route('members.amount-adjustments.revert', [$member, $second]);

        $this->actingAs($admin)->post($url)
            ->assertRedirect(route('members.show', $member))
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'reverted') && str_contains($s, '200.00'));

        $this->actingAs($admin)->post($url)
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'already been reverted'));

        $this->assertSame(450.0, (float) $this->claim($member)->refresh()->excess_amount);   // restored once, not twice
        $this->assertSame(1, ExcessCoverage::whereNotNull('reversed_at')->count());
    }

    // ---- Page: a live button on the latest active adjustment only ----

    public function test_only_the_latest_active_adjustment_row_gets_a_live_revert_button(): void
    {
        [$member, $first, $second] = $this->twoAdjustments();
        $admin = $this->admin();

        $html = $this->actingAs($admin)->get(route('members.show', $member))->assertOk()->getContent();

        // Exactly one live (enabled) Revert, and it points at #2; #1's is disabled with the reason.
        $this->assertSame(1, preg_match_all('/<button[^>]*data-adjustment-action="revert"(?![^>]*\bdisabled\b)/', $html));
        $this->assertSame(1, preg_match_all('/<button[^>]*data-adjustment-action="revert"[^>]*\bdisabled\b/', $html));
        $this->assertStringContainsString(route('members.amount-adjustments.revert', [$member, $second]), $html);
        $this->assertStringNotContainsString(route('members.amount-adjustments.revert', [$member, $first]), $html);

        // After reverting #2, #1 becomes the latest active one.
        $this->revert($member, $second);

        $html = $this->actingAs($admin)->get(route('members.show', $member))->assertOk()->getContent();
        $this->assertStringContainsString(route('members.amount-adjustments.revert', [$member, $first]), $html);
        $this->assertSame(1, preg_match_all('/<button[^>]*data-adjustment-action="revert"(?![^>]*\bdisabled\b)/', $html));
    }

    public function test_a_deactivated_members_page_shows_the_revert_button_disabled(): void
    {
        [$member] = $this->twoAdjustments();
        $member->update(['is_active' => false, 'resignation_date' => '2027-01-10']);

        $html = $this->actingAs($this->admin())->get(route('members.show', $member))->assertOk()->getContent();

        $this->assertSame(0, preg_match_all('/<button[^>]*data-adjustment-action="revert"(?![^>]*\bdisabled\b)/', $html));
        $this->assertStringContainsString('data-adjustment-action="revert"', $html);
    }

    public function test_a_non_admin_sees_no_action_column_on_the_adjustment_history(): void
    {
        [$member] = $this->twoAdjustments();
        $staff = User::factory()->create(['role' => 'user', 'is_active' => true]);

        $html = $this->actingAs($staff)->get(route('members.show', $member))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-adjustment-action=', $html);
    }
}
