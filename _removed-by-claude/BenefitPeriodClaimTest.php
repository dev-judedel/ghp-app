<?php

namespace Tests\Feature;

use App\Http\Controllers\BenefitPeriodClaimController;
use App\Http\Middleware\EnsureMemberIsActive;
use App\Models\BenefitPeriod;
use App\Models\ExcessCoverage;
use App\Models\Member;
use App\Models\User;
use App\Services\BenefitAccrualService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Coverage Year History -> Benefit periods: Status (Claimed / Unclaimed) and
 * the Claim | Revert actions.
 *
 * Lifecycle under test: Unclaimed --Claim--> Claimed --Revert--> Unclaimed
 * (marked Reverted). Revert is a recorded, confirmed, once-per-claim action —
 * NOT the old Cancel (which only blanked the claim columns): it stamps who /
 * when on the period (reverted_at / reverted_by), writes one explicit audit
 * entry, never deletes the period and never touches any GHP figure,
 * reimbursement, excess coverage or other benefit period.
 *
 * NOT YET RUN — run `php artisan migrate`, then
 * `php artisan test --filter=BenefitPeriodClaimTest`.
 */
class BenefitPeriodClaimTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-06-15 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Activity::flushEventListeners();

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

    private function period(Member $member): BenefitPeriod
    {
        return app(BenefitAccrualService::class)->accrue($member->fresh());
    }

    private function claim(User $by, Member $member, BenefitPeriod $period)
    {
        return $this->actingAs($by)->post(route('members.benefit-periods.claim', [$member, $period]));
    }

    private function revert(User $by, Member $member, BenefitPeriod $period)
    {
        return $this->actingAs($by)->post(route('members.benefit-periods.revert', [$member, $period]));
    }

    private function page(User $by, Member $member): string
    {
        return $this->actingAs($by)->get(route('members.show', $member))->assertOk()->getContent();
    }

    // ---- Columns: Status right after Available, Action right after Status ----

    public function test_status_and_action_columns_come_after_available_in_that_order(): void
    {
        $member = $this->member();
        $this->period($member);

        $html = $this->page($this->admin(), $member);

        $this->assertMatchesRegularExpression('/<th class="num">Available<\/th><th>Status<\/th>\s*<th>Action<\/th>/', $html);
    }

    // ---- The action area is Claim | Revert, and Cancel is gone everywhere ----

    public function test_a_new_period_is_unclaimed_with_claim_enabled_and_revert_disabled(): void
    {
        $member = $this->member();
        $period = $this->period($member);

        $this->assertNull($period->fresh()->claimed_at);

        $html = $this->page($this->admin(), $member);

        $this->assertStringContainsString('data-period-status="unclaimed"', $html);
        $this->assertStringNotContainsString('data-period-status="claimed"', $html);
        $this->assertStringNotContainsString('data-period-status="reverted"', $html);

        // Claim | Revert, in that order; Claim live, Revert disabled with the reason.
        $this->assertMatchesRegularExpression('/data-period-action="claim"(?![^>]*\bdisabled\b)/', $html);
        $this->assertMatchesRegularExpression('/<button[^>]*data-period-action="revert"[^>]*\bdisabled\b/', $html);
        $this->assertStringContainsString('title="'.BenefitPeriodClaimController::NOTHING_TO_REVERT.'"', $html);
        $this->assertLessThan(strpos($html, 'data-period-action="revert"'), strpos($html, 'data-period-action="claim"'));
    }

    public function test_there_is_no_cancel_action_left_for_benefit_periods(): void
    {
        $member = $this->member();
        $period = $this->period($member);
        $admin = $this->admin();

        $this->claim($admin, $member, $period);
        $html = $this->page($admin, $member);

        $this->assertStringNotContainsString('data-period-action="cancel"', $html);
        $this->assertStringNotContainsString('cancel-claim', $html);
        $this->assertFalse(Route::has('members.benefit-periods.cancel-claim'));

        // The old URL no longer maps to anything.
        $status = $this->actingAs($admin)
            ->post("/members/{$member->id}/benefit-periods/{$period->id}/cancel-claim")
            ->getStatusCode();
        $this->assertContains($status, [404, 405]);
        $this->assertTrue($period->fresh()->isClaimed());
    }

    // ---- Claim -> Claimed: Claim disables, Revert becomes available behind a modal ----

    public function test_claiming_marks_it_claimed_and_offers_revert(): void
    {
        $member = $this->member();
        $period = $this->period($member);
        $admin = $this->admin();

        $this->claim($admin, $member, $period)
            ->assertRedirect(route('members.show', $member))
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'marked as Claimed'));

        $period->refresh();
        $this->assertTrue($period->isClaimed());
        $this->assertSame($admin->id, $period->claimed_by);

        $html = $this->page($admin, $member);
        $this->assertStringContainsString('data-period-status="claimed"', $html);
        $this->assertStringNotContainsString('data-period-status="unclaimed"', $html);
        $this->assertMatchesRegularExpression('/<button[^>]*data-period-action="claim"[^>]*\bdisabled\b/', $html);
        // Revert is live (no disabled attribute) and opens the confirmation modal rather than submitting.
        $this->assertMatchesRegularExpression('/<button[^>]*data-period-action="revert"(?![^>]*\bdisabled\b)[^>]*onclick="openRevertBenefitPeriod\(/s', $html);
    }

    public function test_the_revert_confirmation_modal_is_rendered_with_back_and_confirm_buttons(): void
    {
        $member = $this->member();
        $period = $this->period($member);
        $admin = $this->admin();
        $this->claim($admin, $member, $period);

        $html = $this->page($admin, $member);

        $this->assertStringContainsString('id="revertBenefitPeriodModal"', $html);
        $this->assertStringContainsString('Revert Benefit Period?', $html);
        $this->assertStringContainsString('Confirm Revert', $html);
        $this->assertMatchesRegularExpression('/onclick="revertBenefitPeriodModal\.close\(\)">Back<\/button>/', $html);

        // The row's button carries the details the modal shows; nothing is submitted by it.
        $this->assertStringContainsString($member->code, $html);
        $this->assertStringContainsString('Revert Benefit Period?', $html);
        $this->assertSame(0, substr_count($html, 'action="'.route('members.benefit-periods.revert', [$member, $period]).'"'));
    }

    // ---- Claim works only once: a repeat request changes nothing ----

    public function test_a_second_claim_request_is_rejected_and_changes_nothing(): void
    {
        $member = $this->member();
        $period = $this->period($member);
        $first = $this->admin();
        $second = $this->admin();

        $this->claim($first, $member, $period);
        $claimedAt = $period->fresh()->claimed_at;

        Carbon::setTestNow('2026-06-16 09:00:00');

        $this->claim($second, $member, $period)
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'already Claimed'));

        $period->refresh();
        $this->assertTrue($period->claimed_at->equalTo($claimedAt));   // not re-stamped
        $this->assertSame($first->id, $period->claimed_by);            // not taken over
    }

    // ---- Revert: Claimed -> Unclaimed (Reverted), recorded, never deleted ----

    public function test_reverting_a_claimed_period_returns_it_to_unclaimed_and_records_who_and_when(): void
    {
        $member = $this->member();
        $period = $this->period($member);
        $admin = $this->admin();
        $this->claim($admin, $member, $period);

        Carbon::setTestNow('2026-06-20 14:30:00');

        $this->revert($admin, $member, $period)
            ->assertRedirect(route('members.show', $member))
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'reverted') && str_contains($s, 'Unclaimed again'));

        $period->refresh();
        $this->assertFalse($period->isClaimed());
        $this->assertNull($period->claimed_by);
        $this->assertTrue($period->isReverted());
        $this->assertSame($admin->id, $period->reverted_by);
        $this->assertTrue($period->reverted_at->equalTo(Carbon::parse('2026-06-20 14:30:00')));

        // The period still exists in the history — Revert never deletes it.
        $this->assertSame(1, BenefitPeriod::where('member_id', $member->id)->count());

        $html = $this->page($admin, $member);
        $this->assertStringContainsString('data-period-status="unclaimed"', $html);
        $this->assertStringContainsString('data-period-status="reverted"', $html);
        // Claim is offered again; Revert is disabled and says why.
        $this->assertMatchesRegularExpression('/data-period-action="claim"(?![^>]*\bdisabled\b)/', $html);
        $this->assertMatchesRegularExpression('/<button[^>]*data-period-action="revert"[^>]*\bdisabled\b/', $html);
        $this->assertStringContainsString('title="'.BenefitPeriodClaimController::ALREADY_REVERTED.'"', $html);
    }

    public function test_a_second_revert_is_rejected_and_changes_nothing(): void
    {
        $member = $this->member();
        $period = $this->period($member);
        $admin = $this->admin();
        $this->claim($admin, $member, $period);
        $this->revert($admin, $member, $period);

        $revertedAt = $period->fresh()->reverted_at;
        $logsBefore = Activity::where('subject_type', BenefitPeriod::class)->where('subject_id', $period->id)->count();

        Carbon::setTestNow('2026-06-25 08:00:00');

        $this->revert($this->admin(), $member, $period)
            ->assertSessionHas('status', fn ($s) => str_contains($s, BenefitPeriodClaimController::ALREADY_REVERTED));

        $period->refresh();
        $this->assertTrue($period->reverted_at->equalTo($revertedAt));   // not re-stamped
        $this->assertFalse($period->isClaimed());
        $this->assertSame($logsBefore, Activity::where('subject_type', BenefitPeriod::class)->where('subject_id', $period->id)->count());

        // Reopening / refreshing the history page never repeats anything either.
        $this->page($admin, $member);
        $this->page($admin, $member);
        $this->assertTrue($period->fresh()->reverted_at->equalTo($revertedAt));
    }

    public function test_reverting_a_period_that_was_never_claimed_is_refused(): void
    {
        $member = $this->member();
        $period = $this->period($member);

        $this->revert($this->admin(), $member, $period)
            ->assertSessionHas('status', fn ($s) => str_contains($s, BenefitPeriodClaimController::NOTHING_TO_REVERT));

        $period->refresh();
        $this->assertFalse($period->isClaimed());
        $this->assertNull($period->reverted_at);   // a never-claimed period is not "reverted"
        $this->assertSame(1, BenefitPeriod::where('member_id', $member->id)->count());
    }

    public function test_a_reverted_period_can_be_claimed_again_and_that_new_claim_can_be_reverted(): void
    {
        $member = $this->member();
        $period = $this->period($member);
        $admin = $this->admin();

        $this->claim($admin, $member, $period);
        $this->revert($admin, $member, $period);
        $this->assertTrue($period->fresh()->isReverted());

        // Existing behavior kept: a period can be claimed again after it was undone...
        $this->claim($admin, $member, $period)->assertSessionHas('status', fn ($s) => str_contains($s, 'marked as Claimed'));
        $period->refresh();
        $this->assertTrue($period->isClaimed());
        $this->assertNull($period->reverted_at);     // a fresh claim clears the earlier marker
        $this->assertNull($period->reverted_by);

        // ...and that NEW claim is revertible once.
        $this->revert($admin, $member, $period)->assertSessionHas('status', fn ($s) => str_contains($s, 'Unclaimed again'));
        $this->assertTrue($period->fresh()->isReverted());
    }

    // ---- Only the targeted period is affected ----

    public function test_reverting_one_period_leaves_the_members_other_periods_untouched(): void
    {
        $member = $this->member();
        $current = $this->period($member);

        Carbon::setTestNow('2025-06-15 10:00:00');
        $older = $this->period($member);                // the previous cycle's period
        Carbon::setTestNow('2026-06-15 10:00:00');

        $admin = $this->admin();
        $this->claim($admin, $member, $current);
        $this->claim($admin, $member, $older);

        $olderBefore = $older->fresh()->only(['claimed_at', 'claimed_by', 'reverted_at', 'ghp_amount', 'ghp_used', 'ghp_available', 'from_date', 'to_date']);

        $this->revert($admin, $member, $current);

        $this->assertTrue($current->fresh()->isReverted());
        $this->assertEquals($olderBefore, $older->fresh()->only(['claimed_at', 'claimed_by', 'reverted_at', 'ghp_amount', 'ghp_used', 'ghp_available', 'from_date', 'to_date']));
        $this->assertTrue($older->fresh()->isClaimed());
        $this->assertSame(2, BenefitPeriod::where('member_id', $member->id)->count());
    }

    public function test_another_members_period_is_never_affected_and_cannot_be_acted_on(): void
    {
        $member = $this->member();
        $other = $this->member();
        $otherPeriod = $this->period($other);
        $admin = $this->admin();
        $this->claim($admin, $other, $otherPeriod);

        // A period id from another member's page is a 404 for both actions.
        $this->claim($admin, $member, $otherPeriod)->assertNotFound();
        $this->revert($admin, $member, $otherPeriod)->assertNotFound();

        $this->assertTrue($otherPeriod->fresh()->isClaimed());
        $this->assertNull($otherPeriod->fresh()->reverted_at);
    }

    // ---- It reverses a STATUS only: no GHP figure, reimbursement, excess or adjustment changes ----

    public function test_claim_and_revert_never_change_any_ghp_reimbursement_or_excess_figure(): void
    {
        $member = $this->member();
        // A claim that exceeds the balance (so it carries excess) and part of that excess already covered.
        $reimbursement = $member->reimbursements()->create([
            'or_no' => 'OR-1', 'or_date' => '2026-06-10', 'or_amount' => 900,
            'available_ghp' => 300, 'original_excess_amount' => 600, 'excess_covered_amount' => 150, 'excess_amount' => 450,
        ]);
        $period = $this->period($member);
        $admin = $this->admin();

        $figures = fn () => [
            $period->fresh()->only(['ghp_amount', 'ghp_used', 'ghp_available', 'from_date', 'to_date', 'coverage_year']),
            $member->fresh()->only(['ghp_amount', 'ghp_amount_is_manual', 'is_active']),
            $reimbursement->fresh()->only(['or_no', 'or_date', 'or_amount', 'available_ghp', 'original_excess_amount', 'excess_covered_amount', 'excess_amount', 'is_voided']),
            $member->reimbursements()->count(),
            ExcessCoverage::count(),
            $member->amountAdjustments()->count(),
            $member->dependents()->count(),
        ];
        $before = $figures();

        $this->claim($admin, $member, $period);
        $this->assertEquals($before, $figures());

        $this->revert($admin, $member, $period);
        $this->assertEquals($before, $figures());
    }

    public function test_recalculating_the_period_does_not_reset_its_claimed_or_reverted_status(): void
    {
        $member = $this->member();
        $period = $this->period($member);
        $admin = $this->admin();
        $this->claim($admin, $member, $period);

        // Same recompute the daily job / reimbursement filing / amount adjustment performs.
        app(BenefitAccrualService::class)->accrue($member->fresh());
        $this->assertTrue($period->fresh()->isClaimed());

        $this->revert($admin, $member, $period);
        app(BenefitAccrualService::class)->accrue($member->fresh());
        $this->assertTrue($period->fresh()->isReverted());
    }

    // ---- Audit trail ----

    public function test_a_revert_writes_exactly_one_explicit_audit_entry(): void
    {
        $member = $this->member();
        $period = $this->period($member);
        $admin = $this->admin();
        $this->claim($admin, $member, $period);

        $before = Activity::where('subject_type', BenefitPeriod::class)->where('subject_id', $period->id)->count();

        $this->revert($admin, $member, $period);

        $entries = Activity::where('subject_type', BenefitPeriod::class)->where('subject_id', $period->id)->get();
        $this->assertSame($before + 1, $entries->count());   // one entry — the model's own dirty-field logging is suppressed

        $entry = $entries->sortByDesc('id')->first();
        $this->assertSame('benefit_period', $entry->log_name);
        $this->assertSame($admin->id, $entry->causer_id);
        $this->assertSame('Revert Benefit Period', $entry->properties['action']);
        $this->assertSame($member->code, $entry->properties['member_code']);
        $this->assertSame(2026, $entry->properties['coverage_year']);
        $this->assertSame('Claimed', $entry->properties['previous_status']);
        $this->assertSame('Unclaimed (Reverted)', $entry->properties['new_status']);
        $this->assertStringContainsString('reverted', $entry->description);
    }

    // ---- Transaction safety ----

    public function test_a_failure_during_revert_rolls_everything_back(): void
    {
        $member = $this->member();
        $period = $this->period($member);
        $admin = $this->admin();
        $this->claim($admin, $member, $period);
        $claimedAt = $period->fresh()->claimed_at;

        // The status columns are written first, then the audit entry; make the audit write blow up.
        Activity::creating(function () {
            throw new \RuntimeException('simulated failure while writing the audit entry');
        });

        $this->revert($admin, $member, $period)->assertStatus(500);

        Activity::flushEventListeners();

        $period->refresh();
        $this->assertTrue($period->isClaimed());                    // still Claimed
        $this->assertTrue($period->claimed_at->equalTo($claimedAt));
        $this->assertNull($period->reverted_at);                    // no partial revert left behind
        $this->assertNull($period->reverted_by);
    }

    // ---- Permissions ----

    public function test_a_non_admin_cannot_claim_or_revert_and_sees_status_but_no_action_column(): void
    {
        $member = $this->member();
        $period = $this->period($member);
        $staff = User::factory()->create(['role' => 'user', 'is_active' => true]);

        $this->claim($staff, $member, $period)->assertForbidden();
        $this->revert($staff, $member, $period)->assertForbidden();
        $this->assertFalse($period->fresh()->isClaimed());

        $html = $this->page($staff, $member);
        $this->assertStringContainsString('data-period-status="unclaimed"', $html);
        $this->assertStringNotContainsString('data-period-action=', $html);
        $this->assertStringNotContainsString('id="revertBenefitPeriodModal"', $html);
    }

    public function test_a_non_admin_cannot_revert_a_claimed_period_by_direct_request(): void
    {
        $member = $this->member();
        $period = $this->period($member);
        $this->claim($this->admin(), $member, $period);
        $staff = User::factory()->create(['role' => 'user', 'is_active' => true]);

        $this->revert($staff, $member, $period)->assertForbidden();

        $this->assertTrue($period->fresh()->isClaimed());
        $this->assertNull($period->fresh()->reverted_at);
    }

    public function test_a_guest_cannot_reach_either_endpoint(): void
    {
        $member = $this->member();
        $period = $this->period($member);

        $this->post(route('members.benefit-periods.claim', [$member, $period]))->assertRedirect(route('login'));
        $this->post(route('members.benefit-periods.revert', [$member, $period]))->assertRedirect(route('login'));
    }

    // ---- A deactivated member is read-only (task.md §2.23) ----

    public function test_a_deactivated_members_periods_cannot_be_claimed_or_reverted(): void
    {
        $member = $this->member();
        $claimed = $this->period($member);
        $claimed->forceFill(['claimed_at' => now(), 'claimed_by' => $this->admin()->id])->save();
        $member->update(['is_active' => false, 'resignation_date' => '2026-06-01']);
        $admin = $this->admin();

        // Revert of a claimed period: rejected before the controller runs.
        $this->revert($admin, $member, $claimed)
            ->assertRedirect(route('members.show', $member))
            ->assertSessionHas('status', EnsureMemberIsActive::MESSAGE);
        $this->assertTrue($claimed->fresh()->isClaimed());
        $this->assertNull($claimed->fresh()->reverted_at);

        // Claim of an unclaimed one: rejected too.
        $claimed->forceFill(['claimed_at' => null, 'claimed_by' => null])->save();
        $this->claim($admin, $member, $claimed)->assertSessionHas('status', EnsureMemberIsActive::MESSAGE);
        $this->assertFalse($claimed->fresh()->isClaimed());

        // UI: both buttons disabled with the notice, and no modal exists to open.
        $html = $this->page($admin, $member);
        $this->assertMatchesRegularExpression('/<button[^>]*data-period-action="claim"[^>]*\bdisabled\b/', $html);
        $this->assertMatchesRegularExpression('/<button[^>]*data-period-action="revert"[^>]*\bdisabled\b/', $html);
        $this->assertStringNotContainsString('id="revertBenefitPeriodModal"', $html);
    }

    // ---- Configurable coverage year (nothing hardcoded to April–March) ----

    public function test_revert_works_for_a_configured_calendar_year_cycle(): void
    {
        $member = $this->member([
            'apply_date' => '2026-01-01',
            'coverage_year' => 2026,
            'coverage_end_date' => '2026-12-31',
            'start_date' => '2026-01-01',
            'deduction_start_date' => '2026-02-01',
        ]);
        $period = $this->period($member);
        $admin = $this->admin();

        // The stored period is the configured Jan–Dec one, not Apr–Mar.
        $this->assertSame('2026-01-01', $period->from_date->toDateString());
        $this->assertSame('2026-12-31', $period->to_date->toDateString());

        $this->claim($admin, $member, $period);

        // The modal data comes from the stored period dates.
        $html = $this->page($admin, $member);
        $this->assertStringContainsString('January 01, 2026', $html);
        $this->assertStringContainsString('December 31, 2026', $html);

        $this->revert($admin, $member, $period)->assertSessionHas('status', fn ($s) => str_contains($s, 'Unclaimed again'));

        $period->refresh();
        $this->assertTrue($period->isReverted());
        $this->assertSame('2026-01-01', $period->from_date->toDateString());   // dates untouched
        $this->assertSame('2026-12-31', $period->to_date->toDateString());
        $this->assertSame(2026, $period->coverage_year);
    }

    public function test_revert_works_for_the_standard_april_to_march_cycle_too(): void
    {
        $member = $this->member(['deduction_start_date' => '2026-04-01']);
        $period = $this->period($member);
        $admin = $this->admin();
        $this->claim($admin, $member, $period);

        $this->revert($admin, $member, $period);

        $this->assertTrue($period->fresh()->isReverted());
        $this->assertSame('2026-04-01', $period->fresh()->from_date->toDateString());
        $this->assertSame('2027-03-31', $period->fresh()->to_date->toDateString());
    }
}
