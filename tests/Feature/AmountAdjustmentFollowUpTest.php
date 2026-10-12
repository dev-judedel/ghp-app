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
 * Follow-up to AmountAdjustmentExcessCoverageTest (task.md §2.29): the cases
 * that file doesn't reach — the service-level inactive guard, the double
 * submit through the real route, "never rebuild from current balances", a
 * decrease never reporting a negative "to Available", and the on-page
 * "covered by GHP adjustment" line.
 *
 * Same fixture as that file: today is 2027-01-15 in the Apr 2026 - Mar 2027
 * cycle, deductions from Apr 2026 => 10 months rendered, fund 10 x 300 = 3,000.
 *
 * NOT YET RUN — run: php artisan test --filter=AmountAdjustmentFollowUpTest
 */
class AmountAdjustmentFollowUpTest extends TestCase
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

    // ---- Inactive: the service itself refuses (not just the route middleware) ----

    public function test_the_service_rejects_adjusting_or_reverting_an_inactive_member(): void
    {
        $member = $this->member();
        $this->file($member, 3750);
        $this->adjust($member, 4200);

        $member->update(['is_active' => false, 'resignation_date' => '2027-01-10']);
        $before = [
            $member->fresh()->only(['ghp_amount', 'ghp_amount_is_manual']),
            $this->claim($member)->only(['excess_amount', 'excess_covered_amount', 'or_amount']),
            BenefitAmountAdjustment::count(),
            ExcessCoverage::whereNull('reversed_at')->count(),
        ];

        try {
            $this->adjust($member, 5000);
            $this->fail('Adjusting an inactive member should have been rejected.');
        } catch (RuntimeException $e) {
            $this->assertSame(EnsureMemberIsActive::MESSAGE, $e->getMessage());
        }

        try {
            app(AmountAdjustmentService::class)->revertToAutomatic($member->fresh(), $this->admin());
            $this->fail('Reverting an inactive member should have been rejected.');
        } catch (RuntimeException $e) {
            $this->assertSame(EnsureMemberIsActive::MESSAGE, $e->getMessage());
        }

        $this->assertEquals($before, [
            $member->fresh()->only(['ghp_amount', 'ghp_amount_is_manual']),
            $this->claim($member)->only(['excess_amount', 'excess_covered_amount', 'or_amount']),
            BenefitAmountAdjustment::count(),
            ExcessCoverage::whereNull('reversed_at')->count(),
        ]);
    }

    // ---- Same adjustment submitted twice through the real route ----

    public function test_submitting_the_same_adjustment_twice_covers_the_excess_only_once(): void
    {
        $member = $this->member();
        $this->file($member, 3750); // excess 750
        $admin = $this->admin();
        $payload = ['new_amount' => 4200, 'reason' => 'Approved adjustment'];

        $this->actingAs($admin)->post(route('members.amount-adjustments.store', $member), $payload)->assertRedirect();
        $this->actingAs($admin)->post(route('members.amount-adjustments.store', $member), $payload)->assertRedirect();

        $claim = $this->claim($member);
        $this->assertSame(500.0, (float) $claim->excess_covered_amount);   // not 1,000
        $this->assertSame(250.0, (float) $claim->excess_amount);
        $this->assertSame(1, ExcessCoverage::where('reimbursement_id', $claim->id)->count());
        $this->assertSame(500.0, (float) ExcessCoverage::where('reimbursement_id', $claim->id)->sum('amount'));
    }

    // ---- Revert restores ONLY what the adjustment covered (never "current + adjustment") ----

    public function test_revert_gives_back_only_the_adjustments_own_share_when_something_else_covered_part_of_the_claim(): void
    {
        $member = $this->member();
        $this->file($member, 3750); // original excess 750

        // Simulate an earlier, different cover (e.g. a dependent): 100 already covered.
        $this->claim($member)->update(['excess_covered_amount' => 100, 'excess_amount' => 650]);

        $this->adjust($member, 4200); // +500 -> the adjustment covers 500 of the remaining 650

        $claim = $this->claim($member);
        $this->assertSame(150.0, (float) $claim->excess_amount);
        $this->assertSame(600.0, (float) $claim->excess_covered_amount);

        app(AmountAdjustmentService::class)->revertToAutomatic($member->fresh(), $this->admin());

        $claim = $this->claim($member);
        // Back to 650 / 100 — NOT 750 / 0. The other 100 was never the adjustment's to give back.
        $this->assertSame(650.0, (float) $claim->excess_amount);
        $this->assertSame(100.0, (float) $claim->excess_covered_amount);
        $this->assertSame(750.0, (float) $claim->original_excess_amount);
    }

    // ---- Reverting twice can never restore twice ----

    public function test_a_second_revert_does_nothing(): void
    {
        $member = $this->member();
        $this->file($member, 3750);
        $this->adjust($member, 4200);

        $service = app(AmountAdjustmentService::class);
        $service->revertToAutomatic($member->fresh(), $this->admin());
        $second = $service->revertToAutomatic($member->fresh(), $this->admin());

        $this->assertIsString($second); // "already automatic"
        $this->assertSame(750.0, (float) $this->claim($member)->excess_amount);
        $this->assertSame(0.0, (float) $this->claim($member)->excess_covered_amount);
    }

    // ---- A decrease never reports a negative "to Available" ----

    public function test_a_decrease_records_zero_not_a_negative_available_increase(): void
    {
        $member = $this->member(['ghp_amount' => 4200, 'ghp_amount_is_manual' => true]);
        app(BenefitAccrualService::class)->accrue($member->fresh());
        $this->file($member, 2000);

        $adjustment = $this->adjust($member, 3600);

        $this->assertLessThan(0, (float) $adjustment->increase_amount);
        $this->assertSame(0.0, (float) $adjustment->available_increase_amount);
        $this->assertSame(0.0, (float) $adjustment->excess_covered_amount);
    }

    // ---- Page: the covered amount is shown as coming from the GHP adjustment, and goes away on revert ----

    public function test_the_member_page_shows_what_the_ghp_adjustment_covered_and_drops_it_after_revert(): void
    {
        $member = $this->member();
        $this->file($member, 3750);
        $admin = $this->admin();

        $this->adjust($member, 4200);

        $html = $this->actingAs($admin)->get(route('members.show', $member))->assertOk()->getContent();
        $this->assertStringContainsString('covered by GHP adjustment', $html);
        $this->assertStringContainsString('increase', $html);          // the history row

        app(AmountAdjustmentService::class)->revertToAutomatic($member->fresh(), $admin);

        $html = $this->actingAs($admin)->get(route('members.show', $member))->assertOk()->getContent();
        $this->assertStringNotContainsString('covered by GHP adjustment', $html);
        $this->assertStringContainsString('Reverted', $html);          // the original adjustment row
    }

    // ---- Used GHP and the reimbursement's own figures are never changed by adjust/revert ----

    public function test_used_ghp_is_not_changed_by_covering_excess(): void
    {
        $member = $this->member();
        $this->file($member, 3750);
        $usedBefore = (float) $member->benefitPeriods()->firstOrFail()->fresh()->ghp_used;

        $this->adjust($member, 4200);

        $period = $member->benefitPeriods()->firstOrFail()->fresh();
        $this->assertSame($usedBefore, (float) $period->ghp_used);        // 3,000 before and after
        $this->assertSame(3750.0, (float) $this->claim($member)->or_amount);
    }
}
