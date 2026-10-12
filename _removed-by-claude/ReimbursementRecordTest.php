<?php

namespace Tests\Feature;

use App\Http\Controllers\ReimbursementClaimController;
use App\Http\Controllers\ReimbursementController;
use App\Http\Middleware\EnsureMemberIsActive;
use App\Models\BenefitPeriod;
use App\Models\Member;
use App\Models\Reimbursement;
use App\Models\User;
use App\Services\AmountAdjustmentService;
use App\Services\BenefitAccrualService;
use App\Services\DependentEligibilityService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Multiple reimbursement RECORDS per coverage year, each with its own
 * Filed / Claimed / Reverted lifecycle (task.md §2.32).
 *
 * A coverage cycle keeps ONE benefit_periods row (the GHP fund). Every filing is
 * its own record under it — filing again after a record is Claimed never reuses
 * that record. A Claimed record is locked: no edit, no void, and no later GHP
 * increase (Adjust GHP amount, an eligible dependent) ever covers its excess.
 *
 * Fixture: today is 2027-01-15 in the Apr 2026 - Mar 2027 cycle, deductions from
 * Apr 2026 => 10 months rendered, fund 10 x 300 = 3,000 (an increase to 4,200 is +500).
 *
 * NOT YET RUN — run `php artisan migrate`, then
 * `php artisan test --filter=ReimbursementRecordTest`.
 */
class ReimbursementRecordTest extends TestCase
{
    use RefreshDatabase;

    private int $tokenSeq = 0;

    private int $memberSeq = 0;

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

    /** A member with a generated current period: fund 3,000, nothing used. */
    private function member(): Member
    {
        $member = Member::factory()->create([
            'member_type' => Member::MEMBER_TYPE_EMPLOYEE,
            'is_active' => true,
            'deduction_start_date' => '2026-04-01',
            'ghp_amount' => 3600,
            'ghp_amount_is_manual' => false,
            // members.email is UNIQUE, and one test needs two members.
            'email' => 'juan'.(++$this->memberSeq).'@example.com',
        ]);

        app(BenefitAccrualService::class)->accrue($member->fresh());

        return $member->fresh();
    }

    /** Files through the real endpoint, like the page does (with its own one-time token). */
    private function file(Member $member, float $amount, string $orNo, ?string $token = null)
    {
        return $this->actingAs($this->admin())->post(route('members.reimbursements.store', $member), [
            'or_no' => $orNo,
            'or_date' => '2026-12-10',
            'or_amount' => $amount,
            'submission_token' => $token ?? 'tok-'.(++$this->tokenSeq).'-'.$orNo,
        ]);
    }

    private function record(Member $member, string $orNo): Reimbursement
    {
        return Reimbursement::where('member_id', $member->id)->where('or_no', $orNo)->firstOrFail()->fresh();
    }

    private function claim(User $by, Member $member, Reimbursement $record)
    {
        return $this->actingAs($by)->post(route('members.reimbursements.claim', [$member, $record]));
    }

    private function revert(User $by, Member $member, Reimbursement $record)
    {
        return $this->actingAs($by)->post(route('members.reimbursements.revert', [$member, $record]));
    }

    private function adjustTo(Member $member, float $amount)
    {
        return app(AmountAdjustmentService::class)->apply($member->fresh(), $amount, 'Approved adjustment', null, null, $this->admin());
    }

    /** Spouse becomes immediately eligible: 300 -> 350 a month => fund +500. */
    private function addEligibleDependent(Member $member, string $name = 'Maria Santos'): void
    {
        $service = app(DependentEligibilityService::class);
        $dependent = $service->addDependent($member->fresh(), ['name' => $name, 'relation' => 'Spouse', 'birthdate' => '1990-01-01']);
        $service->grantImmediate($member->fresh(), $dependent, $this->admin());
    }

    /** A claim created directly with a given excess (filing a second over-balance claim is blocked by the positive-balance rule). */
    private function recordWithExcess(Member $member, string $orNo, string $date, float $excess): Reimbursement
    {
        return $member->reimbursements()->create([
            'or_no' => $orNo, 'or_date' => $date, 'or_amount' => 1000,
            'excess_amount' => $excess, 'original_excess_amount' => $excess,
        ]);
    }

    // ---- Test 1: first reimbursement, then Claim ----

    public function test_a_filed_record_can_be_claimed_and_becomes_locked(): void
    {
        $member = $this->member();
        $admin = $this->admin();
        $this->file($member, 1000, 'OR-1')->assertSessionHasNoErrors();

        $record = $this->record($member, 'OR-1');
        $this->assertSame('Filed', $record->recordStatus());
        $this->assertFalse($record->isLocked());

        $this->claim($admin, $member, $record)->assertSessionHas('status', fn ($s) => str_contains($s, 'marked as Claimed'));

        $record->refresh();
        $this->assertSame('Claimed', $record->recordStatus());
        $this->assertTrue($record->isLocked());
        $this->assertSame($admin->id, $record->claimed_by);
        $this->assertSame(1000.0, (float) $record->or_amount);          // amount never changes
    }

    // ---- Tests 2 + 3: another filing after a Claim is a NEW record in the SAME coverage year ----

    public function test_filing_again_after_a_claim_creates_a_new_record_in_the_same_coverage_year(): void
    {
        $member = $this->member();
        $this->file($member, 1000, 'OR-1');
        $first = $this->record($member, 'OR-1');
        $this->claim($this->admin(), $member, $first);

        // Available GHP is still 2,000 (3,000 - 1,000): the member may file again.
        $this->file($member, 800, 'OR-2')->assertSessionHasNoErrors();

        $second = $this->record($member, 'OR-2');
        $this->assertNotSame($first->id, $second->id);                  // a NEW record, not the claimed one reused
        $this->assertSame(800.0, (float) $second->or_amount);
        $this->assertSame('Filed', $second->recordStatus());

        // Record #1 is exactly as it was.
        $first->refresh();
        $this->assertSame('Claimed', $first->recordStatus());
        $this->assertSame(1000.0, (float) $first->or_amount);

        // Same coverage year, same single fund row — not treated as a duplicate, and no second period created.
        $this->assertSame($first->benefit_period_id, $second->benefit_period_id);
        $this->assertSame(1, BenefitPeriod::where('member_id', $member->id)->count());
        $this->assertSame(1, $first->recordNumber());
        $this->assertSame(2, $second->recordNumber());

        // A third one as well, while Available GHP remains.
        $this->file($member, 500, 'OR-3')->assertSessionHasNoErrors();
        $this->assertSame(3, $this->record($member, 'OR-3')->recordNumber());
    }

    public function test_the_history_lists_every_record_separately_with_its_own_status_and_actions(): void
    {
        $member = $this->member();
        $admin = $this->admin();
        $this->file($member, 1000, 'OR-1');
        $this->file($member, 800, 'OR-2');
        $this->file($member, 500, 'OR-3');
        $this->claim($admin, $member, $this->record($member, 'OR-1'));
        $this->claim($admin, $member, $this->record($member, 'OR-2'));

        $html = $this->actingAs($admin)->get(route('members.show', $member))->assertOk()->getContent();

        $this->assertSame(3, substr_count($html, 'data-record-id="'));
        $this->assertSame(2, substr_count($html, 'data-record-status="claimed"'));
        $this->assertSame(1, substr_count($html, 'data-record-status="unclaimed"'));
        $this->assertSame(3, substr_count($html, 'data-record-action="claim"'));
        $this->assertSame(3, substr_count($html, 'data-record-action="revert"'));
        // Claim is live only on the Filed record; Revert is live only on the two Claimed ones.
        $this->assertSame(1, preg_match_all('/<button[^>]*data-record-action="claim"(?![^>]*\bdisabled\b)[^>]*>/s', $html));
        $this->assertSame(2, preg_match_all('/<button[^>]*data-record-action="revert"(?![^>]*\bdisabled\b)[^>]*>/s', $html));
    }

    // ---- §2.33: Coverage year history -> Benefit periods shows one row PER RECORD ----

    public function test_each_record_has_its_own_used_and_available_and_the_entitlement_is_never_duplicated(): void
    {
        $member = $this->member(); // fund 3,000 for the cycle
        $admin = $this->admin();

        $this->file($member, 1000, 'OR-1');
        $first = $this->record($member, 'OR-1');
        $this->claim($admin, $member, $first);
        $firstUsed = $this->record($member, 'OR-1')->recordUsed();
        $firstAvailable = $this->record($member, 'OR-1')->recordAvailableAfter();

        $this->file($member, 800, 'OR-2');
        $this->file($member, 500, 'OR-3');

        $r1 = $this->record($member, 'OR-1');
        $r2 = $this->record($member, 'OR-2');
        $r3 = $this->record($member, 'OR-3');

        // Each record drew only its own amount, from what was left after the previous one.
        $this->assertSame(1000.0, $r1->recordUsed());
        $this->assertSame(2000.0, $r1->recordAvailableAfter());
        $this->assertSame(800.0, $r2->recordUsed());
        $this->assertSame(1200.0, $r2->recordAvailableAfter());
        $this->assertSame(500.0, $r3->recordUsed());
        $this->assertSame(700.0, $r3->recordAvailableAfter());

        // The Claimed record's own figures did not move when later records were filed.
        $this->assertSame($firstUsed, $r1->recordUsed());
        $this->assertSame($firstAvailable, $r1->recordAvailableAfter());

        // No extra entitlement: ONE fund row for the cycle, same GHP amount, and the
        // records' Used adds up to that single fund's Used (never more).
        $this->assertSame(1, $member->benefitPeriods()->count());
        app(BenefitAccrualService::class)->accrue($member->fresh());
        $period = $member->benefitPeriods()->firstOrFail()->fresh();
        $this->assertSame(3600.0, (float) $period->ghp_amount);
        $this->assertSame(2300.0, $r1->recordUsed() + $r2->recordUsed() + $r3->recordUsed());
        $this->assertSame(2300.0, (float) $period->ghp_used);
        $this->assertSame($r3->recordAvailableAfter(), (float) $period->ghp_available);
    }

    // The earlier "lower Reimbursement records table" tests were removed (task.md §2.34): that table
    // is gone from the Coverage Year History panel; the main Benefit periods table is the record
    // table now. See test_the_lower_records_table_is_gone_and_the_main_table_has_one_row_per_record().

    // ---- Test 4: Available GHP rule is unchanged ----

    public function test_a_new_record_still_needs_a_positive_available_ghp(): void
    {
        $member = $this->member();
        $this->file($member, 3000, 'OR-1');                                    // uses the whole fund
        $this->claim($this->admin(), $member, $this->record($member, 'OR-1'));

        $this->file($member, 100, 'OR-2')->assertSessionHasErrors('or_amount');

        $this->assertSame(0, Reimbursement::where('member_id', $member->id)->where('or_no', 'OR-2')->count());
    }

    public function test_a_claimed_record_alone_never_blocks_filing_and_a_bigger_amount_still_files_with_excess(): void
    {
        $member = $this->member();
        $this->file($member, 2500, 'OR-1');                                    // Available 500 left
        $this->claim($this->admin(), $member, $this->record($member, 'OR-1'));

        $this->file($member, 1000, 'OR-2')->assertSessionHasNoErrors();         // amount may exceed Available

        $second = $this->record($member, 'OR-2');
        $this->assertSame(1000.0, (float) $second->or_amount);                  // never reduced
        $this->assertSame(500.0, (float) $second->available_ghp);
        $this->assertSame(500.0, (float) $second->excess_amount);               // its OWN excess
        $this->assertSame(0.0, (float) $this->record($member, 'OR-1')->excess_amount);
    }

    // ---- Tests 5 + 6: a Claimed record's excess is never covered ----

    public function test_a_gHP_adjustment_does_not_cover_the_excess_of_a_claimed_record(): void
    {
        $member = $this->member();
        $this->file($member, 3750, 'OR-1');                                    // excess 750
        $this->claim($this->admin(), $member, $this->record($member, 'OR-1'));

        $adjustment = $this->adjustTo($member, 4200);                          // fund +500

        $record = $this->record($member, 'OR-1');
        $this->assertSame(750.0, (float) $record->excess_amount);
        $this->assertSame(0.0, (float) $record->excess_covered_amount);
        $this->assertSame(0.0, (float) $adjustment->excess_covered_amount);
        $this->assertSame(0, $adjustment->coverages()->count());
    }

    public function test_a_dependent_increase_does_not_cover_the_excess_of_a_claimed_record(): void
    {
        $member = $this->member();
        $this->file($member, 3750, 'OR-1');
        $this->claim($this->admin(), $member, $this->record($member, 'OR-1'));

        $this->addEligibleDependent($member);                                  // fund +500

        $record = $this->record($member, 'OR-1');
        $this->assertSame(750.0, (float) $record->excess_amount);
        $this->assertSame(0.0, (float) $record->excess_covered_amount);
        $this->assertSame(4200.0, (float) $member->fresh()->ghp_amount);       // the increase itself still happened
    }

    // ---- Test 7: Revert unlocks, only for FUTURE increases ----

    public function test_a_reverted_record_is_unlocked_and_future_increases_may_cover_it(): void
    {
        $member = $this->member();
        $admin = $this->admin();
        $this->file($member, 3750, 'OR-1');
        $this->claim($admin, $member, $this->record($member, 'OR-1'));
        $this->adjustTo($member, 4200);                                        // while Claimed: nothing covered
        $this->assertSame(750.0, (float) $this->record($member, 'OR-1')->excess_amount);

        $this->revert($admin, $member, $this->record($member, 'OR-1'))
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'reverted'));

        $record = $this->record($member, 'OR-1');
        $this->assertSame('Reverted', $record->recordStatus());
        $this->assertFalse($record->isLocked());
        $this->assertNotNull($record->reverted_at);
        $this->assertSame($admin->id, $record->reverted_by);
        // NOT covered retroactively just because it was reverted.
        $this->assertSame(750.0, (float) $record->excess_amount);
        $this->assertSame(0.0, (float) $record->excess_covered_amount);

        // A later increase (4,200 -> 4,800 = +500 more) can now reach it.
        $this->adjustTo($member, 4800);
        $record = $this->record($member, 'OR-1');
        $this->assertSame(500.0, (float) $record->excess_covered_amount);
        $this->assertSame(250.0, (float) $record->excess_amount);
        $this->assertSame(3750.0, (float) $record->or_amount);

        // It can be edited again, and claimed again (a fresh claim clears the revert marker).
        $this->claim($admin, $member, $record)->assertSessionHas('status', fn ($s) => str_contains($s, 'marked as Claimed'));
        $this->assertNull($this->record($member, 'OR-1')->reverted_at);
    }

    public function test_revert_is_record_specific_and_cannot_be_repeated(): void
    {
        $member = $this->member();
        $admin = $this->admin();
        $this->file($member, 500, 'OR-1');
        $this->file($member, 500, 'OR-2');
        $this->file($member, 500, 'OR-3');
        foreach (['OR-1', 'OR-2'] as $orNo) {
            $this->claim($admin, $member, $this->record($member, $orNo));
        }

        $this->revert($admin, $member, $this->record($member, 'OR-1'));

        $this->assertSame('Reverted', $this->record($member, 'OR-1')->recordStatus());
        $this->assertSame('Claimed', $this->record($member, 'OR-2')->recordStatus());   // untouched
        $this->assertSame('Filed', $this->record($member, 'OR-3')->recordStatus());     // untouched

        // A second revert of the same record is refused.
        $this->revert($admin, $member, $this->record($member, 'OR-1'))
            ->assertSessionHas('status', fn ($s) => str_contains($s, ReimbursementClaimController::ALREADY_REVERTED));

        // Nothing to revert on a Filed record.
        $this->revert($admin, $member, $this->record($member, 'OR-3'))
            ->assertSessionHas('status', fn ($s) => str_contains($s, ReimbursementClaimController::NOTHING_TO_REVERT));
    }

    // ---- Test 8: several records, only the eligible one receives the increase ----

    public function test_only_the_unclaimed_record_receives_a_gHP_increase_when_older_ones_are_claimed(): void
    {
        $member = $this->member();
        $admin = $this->admin();
        $r1 = $this->recordWithExcess($member, 'OR-1', '2026-06-01', 500);
        $r2 = $this->recordWithExcess($member, 'OR-2', '2026-07-01', 100);
        $r3 = $this->recordWithExcess($member, 'OR-3', '2026-08-01', 200);
        $r1->markClaimed($admin);
        $r2->markClaimed($admin);

        $this->adjustTo($member, 4200);                                        // +500

        $r1->refresh(); $r2->refresh(); $r3->refresh();
        $this->assertSame(500.0, (float) $r1->excess_amount);                  // locked
        $this->assertSame(100.0, (float) $r2->excess_amount);                  // locked
        $this->assertSame(0.0, (float) $r1->excess_covered_amount);
        $this->assertSame(0.0, (float) $r2->excess_covered_amount);
        $this->assertSame(200.0, (float) $r3->excess_covered_amount);          // the only eligible record
        $this->assertSame(0.0, (float) $r3->excess_amount);
    }

    // ---- Test 9: dependents ----

    public function test_a_dependent_increase_skips_claimed_records_and_a_second_dependent_adds_nothing(): void
    {
        $member = $this->member();
        $admin = $this->admin();
        $claimed = $this->recordWithExcess($member, 'OR-1', '2026-06-01', 300);
        $open = $this->recordWithExcess($member, 'OR-2', '2026-07-01', 300);
        $claimed->markClaimed($admin);

        $this->addEligibleDependent($member, 'Maria Santos');                  // +500
        $this->addEligibleDependent($member, 'Pedro Santos');                  // flat rate: no further increase

        $claimed->refresh();
        $open->refresh();
        $this->assertSame(300.0, (float) $claimed->excess_amount);
        $this->assertSame(0.0, (float) $claimed->excess_covered_amount);
        $this->assertSame(300.0, (float) $open->excess_covered_amount);
        $this->assertSame(0.0, (float) $open->excess_amount);
    }

    // ---- Test 10: duplicate submission ----

    public function test_submitting_the_same_filing_repeatedly_creates_one_record(): void
    {
        $member = $this->member();

        $this->file($member, 1000, 'OR-1', 'same-token')->assertSessionHas('status', fn ($s) => str_contains($s, 'recorded'));
        $this->file($member, 1000, 'OR-1', 'same-token')->assertSessionHas('status', ReimbursementController::DUPLICATE_MESSAGE);
        $this->file($member, 1000, 'OR-1', 'same-token')->assertSessionHas('status', ReimbursementController::DUPLICATE_MESSAGE);

        $this->assertSame(1, Reimbursement::where('member_id', $member->id)->count());
    }

    public function test_a_repeat_is_ignored_even_when_the_first_filing_used_up_the_balance(): void
    {
        $member = $this->member();

        $this->file($member, 3000, 'OR-1', 'tok-x')->assertSessionHasNoErrors();
        // Balance is now 0, but this is a repeat of the same filing, not a new one.
        $this->file($member, 3000, 'OR-1', 'tok-x')
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', ReimbursementController::DUPLICATE_MESSAGE);

        $this->assertSame(1, Reimbursement::where('member_id', $member->id)->count());
    }

    public function test_two_different_filings_are_both_recorded(): void
    {
        $member = $this->member();

        $this->file($member, 500, 'OR-1', 'tok-a');
        $this->file($member, 500, 'OR-2', 'tok-b');

        $this->assertSame(2, Reimbursement::where('member_id', $member->id)->count());
    }

    // ---- Test 11: backend bypass ----

    public function test_a_claimed_record_cannot_be_edited_or_voided_even_by_a_direct_request(): void
    {
        $member = $this->member();
        $admin = $this->admin();
        $this->file($member, 1000, 'OR-1');
        $record = $this->record($member, 'OR-1');
        $this->claim($admin, $member, $record);

        $this->actingAs($admin)->put(route('members.reimbursements.update', [$member, $record]), [
            'or_no' => 'OR-1', 'or_date' => '2026-12-10', 'or_amount' => 2000,
        ])->assertSessionHas('status', ReimbursementController::LOCKED_MESSAGE);

        $this->actingAs($admin)->post(route('members.reimbursements.void', [$member, $record]), ['reason' => 'oops'])
            ->assertSessionHas('status', ReimbursementController::LOCKED_MESSAGE);

        $record->refresh();
        $this->assertSame(1000.0, (float) $record->or_amount);
        $this->assertFalse((bool) $record->is_voided);

        // After a Revert it can be edited again.
        $this->revert($admin, $member, $record);
        $this->actingAs($admin)->put(route('members.reimbursements.update', [$member, $record]), [
            'or_no' => 'OR-1', 'or_date' => '2026-12-10', 'or_amount' => 1200,
        ])->assertSessionHasNoErrors();
        $this->assertSame(1200.0, (float) $record->fresh()->or_amount);
    }

    public function test_claim_and_revert_are_enforced_on_the_server(): void
    {
        $member = $this->member();
        $other = $this->member();
        $admin = $this->admin();
        $staff = User::factory()->create(['role' => 'user', 'is_active' => true]);
        $this->file($member, 500, 'OR-1');
        $record = $this->record($member, 'OR-1');
        $this->file($other, 500, 'OR-9');
        $foreign = $this->record($other, 'OR-9');

        // Not an administrator.
        $this->claim($staff, $member, $record)->assertForbidden();
        $this->revert($staff, $member, $record)->assertForbidden();

        // Another member's record through this member's URL.
        $this->claim($admin, $member, $foreign)->assertNotFound();
        $this->revert($admin, $member, $foreign)->assertNotFound();

        // A second Claim does nothing.
        $this->claim($admin, $member, $record);
        $claimedAt = $record->fresh()->claimed_at;
        $this->claim($admin, $member, $record)->assertSessionHas('status', fn ($s) => str_contains($s, 'already Claimed'));
        $this->assertTrue($record->fresh()->claimed_at->equalTo($claimedAt));

        // A voided record can be neither claimed nor reverted.
        $voided = $this->recordWithExcess($member, 'OR-V', '2026-05-01', 0);
        $voided->void('filed in error', $admin);
        $this->claim($admin, $member, $voided)->assertSessionHas('status', ReimbursementClaimController::VOIDED);
        $this->assertFalse($voided->fresh()->isClaimed());
    }

    public function test_a_deactivated_members_records_cannot_be_claimed_reverted_or_filed(): void
    {
        $member = $this->member();
        $admin = $this->admin();
        $this->file($member, 500, 'OR-1');
        $record = $this->record($member, 'OR-1');
        $member->update(['is_active' => false, 'resignation_date' => '2027-01-10']);

        $this->claim($admin, $member, $record)->assertSessionHas('status', EnsureMemberIsActive::MESSAGE);
        $this->assertFalse($record->fresh()->isClaimed());

        $this->file($member, 100, 'OR-2')->assertSessionHas('status', EnsureMemberIsActive::MESSAGE);
        $this->assertSame(1, Reimbursement::where('member_id', $member->id)->count());
    }

    // ---- Test 12: failure while filing rolls everything back ----

    public function test_a_failure_while_filing_leaves_nothing_behind(): void
    {
        $member = $this->member();
        $periodBefore = $member->benefitPeriods()->firstOrFail()->only(['ghp_amount', 'ghp_used', 'ghp_available']);

        $this->partialMock(BenefitAccrualService::class, function ($mock) {
            $mock->shouldReceive('availableBalanceFor')->andThrow(new \RuntimeException('simulated failure while filing'));
        });

        $this->withoutExceptionHandling();

        try {
            $this->file($member, 1000, 'OR-1');
            $this->fail('The simulated failure should have propagated.');
        } catch (\RuntimeException $e) {
            $this->assertSame('simulated failure while filing', $e->getMessage());
        }

        $this->assertSame(0, Reimbursement::where('member_id', $member->id)->count());          // no partial reimbursement
        $this->assertSame(1, BenefitPeriod::where('member_id', $member->id)->count());          // no orphan period
        $this->assertEquals($periodBefore, $member->benefitPeriods()->firstOrFail()->fresh()->only(['ghp_amount', 'ghp_used', 'ghp_available']));
    }

    // ---- Spec 2026-10: the Benefit Periods table IS the record table ----

    public function test_the_lower_records_table_is_gone_and_the_main_table_has_one_row_per_record(): void
    {
        $member = $this->member();
        $admin = $this->admin();
        $this->file($member, 900, 'OR-1');
        $this->file($member, 200, 'OR-2');
        $this->file($member, 100, 'OR-3');

        $html = $this->actingAs($admin)->get(route('members.show', $member))->assertOk()->getContent();

        // Redundant lower table removed from the Coverage Year History panel.
        $this->assertStringNotContainsString('data-records-table', $html);
        $this->assertStringNotContainsString('Reimbursement records</h3>', $html);
        $this->assertStringNotContainsString('Reimbursement records (scrollable)', $html);

        // The main table: same columns as before, Status then Action after Available...
        $this->assertMatchesRegularExpression('/<th class="num">Available<\/th><th>Status<\/th>\s*<th>Action<\/th>/', $html);

        // ...with every record on its own row, and the same period repeated on each.
        $this->assertSame(3, substr_count($html, 'data-record-id="'));
        $this->assertSame(3, substr_count($html, 'Apr 2026 &ndash; Mar 2027'));
        $this->assertSame(3, substr_count($html, 'data-record-status="unclaimed"'));
        $this->assertSame(0, substr_count($html, 'data-period-row='));      // a period that has records is not ALSO shown as a fund row

        // The separate Reimbursements card (receipts, edit, void) is untouched.
        $this->assertStringContainsString('File reimbursement', $html);
    }

    public function test_each_row_has_its_own_used_and_available_and_an_earlier_row_never_changes(): void
    {
        $member = $this->member();                      // fund 3,000
        $admin = $this->admin();

        $this->file($member, 900, 'OR-1');
        $first = $this->record($member, 'OR-1');
        $this->claim($admin, $member, $first);

        $this->assertSame(900.0, $first->fresh()->recordUsed());
        $this->assertSame(2100.0, $first->fresh()->recordAvailableAfter());

        $this->file($member, 200, 'OR-2');              // a new, separate row; the earlier one stays Claimed
        $second = $this->record($member, 'OR-2');

        $this->assertSame(200.0, $second->recordUsed());
        $this->assertSame(1900.0, $second->recordAvailableAfter());       // continues from the balance the first left, not a fresh 3,000
        $this->assertSame('Filed', $second->recordStatus());              // shown as Unclaimed

        // The first row's own figures and status are exactly as they were.
        $first->refresh();
        $this->assertSame('Claimed', $first->recordStatus());
        $this->assertSame(900.0, $first->recordUsed());
        $this->assertSame(2100.0, $first->recordAvailableAfter());
        $this->assertSame(900.0, (float) $first->or_amount);

        $html = $this->actingAs($admin)->get(route('members.show', $member))->assertOk()->getContent();
        foreach (['₱900.00', '₱2,100.00', '₱200.00', '₱1,900.00'] as $figure) {
            $this->assertStringContainsString($figure, $html);
        }
        $this->assertSame(1, substr_count($html, 'data-record-status="claimed"'));
        $this->assertSame(1, substr_count($html, 'data-record-status="unclaimed"'));
        $this->assertSame(1, BenefitPeriod::where('member_id', $member->id)->count());   // no second period row, no new coverage year
    }

    public function test_claiming_one_of_two_records_in_the_same_period_changes_only_that_record(): void
    {
        $member = $this->member();
        $admin = $this->admin();
        $this->file($member, 500, 'OR-1');
        $this->file($member, 500, 'OR-2');
        $one = $this->record($member, 'OR-1');
        $two = $this->record($member, 'OR-2');

        $this->assertSame($one->benefit_period_id, $two->benefit_period_id);   // same member, same period
        $this->claim($admin, $member, $two);

        $this->assertSame('Filed', $one->fresh()->recordStatus());
        $this->assertSame('Claimed', $two->fresh()->recordStatus());

        $this->revert($admin, $member, $two);

        $this->assertSame('Filed', $one->fresh()->recordStatus());
        $this->assertSame('Reverted', $two->fresh()->recordStatus());
    }

    public function test_statuses_and_amounts_survive_a_refresh(): void
    {
        $member = $this->member();
        $admin = $this->admin();
        $this->file($member, 700, 'OR-1');
        $this->file($member, 300, 'OR-2');
        $this->claim($admin, $member, $this->record($member, 'OR-1'));

        $first = $this->actingAs($admin)->get(route('members.show', $member))->getContent();
        $second = $this->actingAs($admin)->get(route('members.show', $member))->getContent();

        foreach ([$first, $second] as $html) {
            $this->assertSame(1, substr_count($html, 'data-record-status="claimed"'));
            $this->assertSame(1, substr_count($html, 'data-record-status="unclaimed"'));
            $this->assertStringContainsString('₱700.00', $html);
            $this->assertStringContainsString('₱300.00', $html);
        }

        $this->assertSame(2, Reimbursement::where('member_id', $member->id)->count());   // reading never creates anything
    }

    public function test_a_period_with_no_reimbursement_is_one_fund_row_with_nothing_to_claim(): void
    {
        $member = $this->member();

        $html = $this->actingAs($this->admin())->get(route('members.show', $member))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'data-period-row='));
        $this->assertSame(0, substr_count($html, 'data-record-id="'));
        $this->assertSame(0, substr_count($html, 'data-record-action="claim"'));
        $this->assertStringContainsString('No reimbursement', $html);
    }

    // ---- Revert re-enables BOTH mechanisms, once ----

    public function test_after_a_revert_a_dependent_increase_covers_the_record_again_exactly_once(): void
    {
        $member = $this->member();
        $admin = $this->admin();
        $this->file($member, 3750, 'OR-1');                                    // excess 750
        $this->claim($admin, $member, $this->record($member, 'OR-1'));
        $this->revert($admin, $member, $this->record($member, 'OR-1'));

        $this->addEligibleDependent($member);                                  // +500

        $record = $this->record($member, 'OR-1');
        $this->assertSame(500.0, (float) $record->excess_covered_amount);
        $this->assertSame(250.0, (float) $record->excess_amount);

        // Re-running the same refresh must not apply it a second time.
        $member = $member->fresh();
        $member->load('dependents');
        app(BenefitAccrualService::class)->refreshCurrentPeriodIfAmountChanged($member);

        $record = $this->record($member, 'OR-1');
        $this->assertSame(500.0, (float) $record->excess_covered_amount);
        $this->assertSame(250.0, (float) $record->excess_amount);
        $this->assertSame(750.0, (float) $record->original_excess_amount);     // original always preserved
        $this->assertSame(3750.0, (float) $record->or_amount);
    }

    public function test_a_claim_freezes_a_record_so_an_adjustment_revert_cannot_rewrite_it(): void
    {
        $member = $this->member();
        $admin = $this->admin();
        $this->file($member, 3750, 'OR-1');                                    // excess 750
        $adjustment = $this->adjustTo($member, 4200);                          // +500 covers 500 of it WHILE it is still unclaimed
        $this->assertSame(500.0, (float) $this->record($member, 'OR-1')->excess_covered_amount);

        $this->claim($admin, $member, $this->record($member, 'OR-1'));         // now frozen

        // Reverting the adjustment would hand 500 of excess back to a Claimed record: refused, nothing changes.
        $result = app(AmountAdjustmentService::class)->revertAdjustment($member->fresh(), $adjustment, $admin);
        $this->assertIsString($result);
        $this->assertStringContainsString('Claimed', $result);

        $result = app(AmountAdjustmentService::class)->revertToAutomatic($member->fresh(), $admin);
        $this->assertIsString($result);

        $record = $this->record($member, 'OR-1');
        $this->assertSame(500.0, (float) $record->excess_covered_amount);
        $this->assertSame(250.0, (float) $record->excess_amount);
        $this->assertSame(4200.0, (float) $member->fresh()->ghp_amount);       // the amount did not move either
        $this->assertSame(1, \App\Models\ExcessCoverage::whereNull('reversed_at')->count());

        // Once that record's claim is reverted, the adjustment can be reverted and restores it exactly.
        $this->revert($admin, $member, $record);
        $ok = app(AmountAdjustmentService::class)->revertAdjustment($member->fresh(), $adjustment->fresh(), $admin);
        $this->assertNotIsString($ok);
        $this->assertSame(750.0, (float) $this->record($member, 'OR-1')->excess_amount);
        $this->assertSame(0.0, (float) $this->record($member, 'OR-1')->excess_covered_amount);
    }

    // ---- Existing data keeps working ----

    public function test_a_reimbursement_with_no_claim_columns_set_is_a_normal_filed_record(): void
    {
        $member = $this->member();
        $legacy = $this->recordWithExcess($member, 'OR-OLD', '2026-06-01', 0);

        $this->assertSame('Filed', $legacy->fresh()->recordStatus());
        $this->assertFalse($legacy->fresh()->isLocked());
        $this->actingAs($this->admin())->get(route('members.show', $member))->assertOk();
    }

    // ---- Removing the lower table must not remove any reimbursement feature ----

    public function test_other_reimbursement_features_are_untouched_by_the_cleanup(): void
    {
        $member = $this->member();
        $admin = $this->admin();
        $this->file($member, 1000, 'OR-1');
        $this->file($member, 800, 'OR-2');
        $period = $member->benefitPeriods()->firstOrFail();

        // The separate Reimbursements card (file / edit / void) is still on the page, with every row.
        $html = $this->actingAs($admin)->get(route('members.show', $member))->assertOk()->getContent();
        $this->assertStringContainsString('File reimbursement', $html);
        $this->assertStringContainsString('OR-1', $html);
        $this->assertStringContainsString('OR-2', $html);
        $this->assertStringContainsString('openEditReimbursement(', $html);

        // The period drill-down page still lists each record, and the receipt PDF still renders.
        $this->actingAs($admin)
            ->get(route('members.benefit-periods.reimbursements', [$member, $period]))
            ->assertOk()
            ->assertSee('OR-1')
            ->assertSee('OR-2');
        $this->actingAs($admin)
            ->get(route('members.benefit-periods.reimbursements.pdf', [$member, $period]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        // Nothing was removed from the database.
        $this->assertSame(2, Reimbursement::where('member_id', $member->id)->count());
    }
}
