<?php

namespace Tests\Feature;

use App\Http\Controllers\BenefitPeriodClaimController;
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
 * Several Benefit Period RECORDS per member per coverage cycle (task.md §2.35).
 *
 * One Unclaimed record collects every reimbursement filed until it is Claimed;
 * Claim locks that record only; the next reimbursement filed for the same cycle
 * opens a NEW record (never before). The entitlement is the cycle's, computed
 * once — a new record is never a new grant.
 *
 * Fixture: today is 2027-01-15 in the Apr 2026 - Mar 2027 cycle, deductions from
 * Apr 2026 => 10 months rendered, fund 10 x 300 = 3,000 (an increase to 4,200 is +500).
 *
 * NOT YET RUN — run `php artisan migrate`, then
 * `php artisan test --filter=BenefitPeriodRecordTest`.
 */
class BenefitPeriodRecordTest extends TestCase
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

    /** A member with a generated current period (record #1, empty): fund 3,000, nothing used. */
    private function member(): Member
    {
        $member = Member::factory()->create([
            'member_type' => Member::MEMBER_TYPE_EMPLOYEE,
            'is_active' => true,
            'deduction_start_date' => '2026-04-01',
            'ghp_amount' => 3600,
            'ghp_amount_is_manual' => false,
            'email' => 'juan'.(++$this->memberSeq).'@example.com',
        ]);

        app(BenefitAccrualService::class)->accrue($member->fresh());

        return $member->fresh();
    }

    private function file(Member $member, float $amount, string $orNo, ?string $token = null)
    {
        return $this->actingAs($this->admin())->post(route('members.reimbursements.store', $member), [
            'or_no' => $orNo,
            'or_date' => '2026-12-10',
            'or_amount' => $amount,
            'submission_token' => $token ?? 'tok-'.(++$this->tokenSeq).'-'.$orNo,
        ]);
    }

    private function reimbursement(Member $member, string $orNo): Reimbursement
    {
        return Reimbursement::where('member_id', $member->id)->where('or_no', $orNo)->firstOrFail()->fresh();
    }

    /** The member's Benefit Period records, in record order. */
    private function records(Member $member)
    {
        return BenefitPeriod::where('member_id', $member->id)->orderBy('record_no')->orderBy('id')->get();
    }

    private function claim(User $by, Member $member, BenefitPeriod $record)
    {
        return $this->actingAs($by)->post(route('members.benefit-periods.claim', [$member, $record]));
    }

    private function revert(User $by, Member $member, BenefitPeriod $record)
    {
        return $this->actingAs($by)->post(route('members.benefit-periods.revert', [$member, $record]));
    }

    private function adjustTo(Member $member, float $amount)
    {
        return app(AmountAdjustmentService::class)->apply($member->fresh(), $amount, 'Approved adjustment', null, null, $this->admin());
    }

    private function addEligibleDependent(Member $member, string $name = 'Maria Santos'): void
    {
        $service = app(DependentEligibilityService::class);
        $dependent = $service->addDependent($member->fresh(), ['name' => $name, 'relation' => 'Spouse', 'birthdate' => '1990-01-01']);
        $service->grantImmediate($member->fresh(), $dependent, $this->admin());
    }

    // ---- The exact workflow of the specification ----

    public function test_the_full_workflow_of_the_specification(): void
    {
        $member = $this->member();
        $admin = $this->admin();

        // Initial state: the cycle's only record is empty and Unclaimed.
        $this->assertSame(1, $this->records($member)->count());
        $this->assertSame(1, (int) $this->records($member)->first()->record_no);

        // Steps 1-3: three reimbursements, all into the SAME record, no extra row.
        $this->file($member, 500, 'OR-1')->assertSessionHasNoErrors();
        $this->file($member, 700, 'OR-2')->assertSessionHasNoErrors();
        $this->file($member, 800, 'OR-3')->assertSessionHasNoErrors();

        $records = $this->records($member);
        $this->assertSame(1, $records->count());
        $first = $records->first();
        foreach (['OR-1', 'OR-2', 'OR-3'] as $orNo) {
            $this->assertSame($first->id, $this->reimbursement($member, $orNo)->benefit_period_id);
        }
        $this->assertSame(2000.0, (float) $first->ghp_used);
        $this->assertSame(1000.0, (float) $first->ghp_available);
        $this->assertFalse($first->isClaimed());

        // Step 4: Claim record 1 — locked, keeps its three reimbursements, and NO empty record is created.
        $this->claim($admin, $member, $first)->assertSessionHas('status', fn ($s) => str_contains($s, 'marked as Claimed'));

        $first->refresh();
        $this->assertTrue($first->isClaimed());
        $this->assertSame($admin->id, $first->claimed_by);
        $this->assertSame(3, $first->reimbursements()->count());
        $this->assertSame(1, $this->records($member)->count());

        // Step 5: another reimbursement after the claim -> a NEW record for the SAME coverage period.
        $this->file($member, 600, 'OR-4')->assertSessionHasNoErrors();

        $records = $this->records($member);
        $this->assertSame(2, $records->count());
        [$one, $two] = [$records[0], $records[1]];
        $this->assertSame(2, (int) $two->record_no);
        $this->assertTrue($one->from_date->isSameDay($two->from_date));
        $this->assertTrue($one->to_date->isSameDay($two->to_date));
        $this->assertSame($two->id, $this->reimbursement($member, 'OR-4')->benefit_period_id);
        // Record 1 and its three reimbursements are exactly as they were.
        $this->assertSame($one->id, $this->reimbursement($member, 'OR-1')->benefit_period_id);
        $this->assertSame(2000.0, (float) $one->ghp_used);
        $this->assertSame(1000.0, (float) $one->ghp_available);
        $this->assertSame(3, $one->reimbursements()->count());
        // Same entitlement on both records — and record 2 holds only ITS share of the usage.
        $this->assertSame(3600.0, (float) $one->ghp_amount);
        $this->assertSame(3600.0, (float) $two->ghp_amount);
        $this->assertSame(600.0, (float) $two->ghp_used);
        $this->assertSame(400.0, (float) $two->ghp_available);

        // Step 6: another one before claiming record 2 -> joins record 2, no third record.
        $this->file($member, 400, 'OR-5')->assertSessionHasNoErrors();

        $records = $this->records($member);
        $this->assertSame(2, $records->count());
        $this->assertSame($records[1]->id, $this->reimbursement($member, 'OR-5')->benefit_period_id);
        $this->assertSame(1000.0, (float) $records[1]->ghp_used);
        $this->assertSame(0.0, (float) $records[1]->ghp_available);

        // Step 7: claim record 2 — both Claimed, each with its own history.
        $this->claim($admin, $member, $records[1]);

        $records = $this->records($member);
        $this->assertTrue($records[0]->isClaimed());
        $this->assertTrue($records[1]->isClaimed());
        $this->assertSame(3, $records[0]->reimbursements()->count());
        $this->assertSame(2, $records[1]->reimbursements()->count());

        // The member's balance is ONE figure for the cycle: 3,000 - 3,000 = 0, never reset by a record.
        $summary = BenefitPeriod::summarizeCycle($records);
        $this->assertSame(3000.0, (float) $summary->ghp_used);
        $this->assertSame(0.0, (float) $summary->ghp_available);
        $this->assertSame(3600.0, (float) $summary->ghp_amount);

        // Step 8: revert record 2 only.
        $this->revert($admin, $member, $records[1])->assertSessionHas('status', fn ($s) => str_contains($s, 'reverted'));

        $records = $this->records($member);
        $this->assertTrue($records[0]->isClaimed());                    // record 1 untouched
        $this->assertFalse($records[1]->isClaimed());
        $this->assertTrue($records[1]->isReverted());
        $this->assertSame($admin->id, $records[1]->reverted_by);
        $this->assertSame(3, $records[0]->reimbursements()->count());
        $this->assertSame(2, $records[1]->reimbursements()->count());  // history intact, nothing moved
        $this->assertSame(5, Reimbursement::where('member_id', $member->id)->count());
    }

    // ---- Entitlement and balance ----

    public function test_a_new_record_never_grants_a_new_entitlement(): void
    {
        $member = $this->member();
        $admin = $this->admin();

        $this->file($member, 1000, 'OR-1');
        $this->claim($admin, $member, $this->records($member)->first());
        $this->file($member, 800, 'OR-2');
        $this->file($member, 500, 'OR-3');

        $records = $this->records($member);
        $this->assertSame(2, $records->count());

        // Σ of the records' own Used equals the cycle's single Used; Available is the cycle's remaining balance.
        $this->assertSame(2300.0, round((float) $records->sum('ghp_used'), 2));
        $this->assertSame(700.0, (float) $records[1]->ghp_available);
        $balance = app(BenefitAccrualService::class)->availableBalanceFor($member->fresh(), Carbon::parse('2026-12-10'));
        $this->assertSame(700.0, (float) $balance['available']);
        $this->assertSame(2300.0, (float) $balance['used']);
    }

    public function test_a_claimed_record_alone_never_blocks_filing_and_an_amount_above_available_keeps_its_own_excess(): void
    {
        $member = $this->member();
        $this->file($member, 2500, 'OR-1');                                    // Available 500 left
        $this->claim($this->admin(), $member, $this->records($member)->first());

        $this->file($member, 1000, 'OR-2')->assertSessionHasNoErrors();

        $second = $this->reimbursement($member, 'OR-2');
        $this->assertSame(1000.0, (float) $second->or_amount);
        $this->assertSame(500.0, (float) $second->available_ghp);
        $this->assertSame(500.0, (float) $second->excess_amount);
        $this->assertSame(0.0, (float) $this->reimbursement($member, 'OR-1')->excess_amount);
        $this->assertSame(2, $this->records($member)->count());
    }

    public function test_no_available_ghp_means_no_new_record(): void
    {
        $member = $this->member();
        $this->file($member, 3000, 'OR-1');                                    // whole fund
        $this->claim($this->admin(), $member, $this->records($member)->first());

        $this->file($member, 100, 'OR-2')->assertSessionHasErrors('or_amount');

        $this->assertSame(0, Reimbursement::where('member_id', $member->id)->where('or_no', 'OR-2')->count());
        $this->assertSame(1, $this->records($member)->count());                // and no empty record was created
    }

    // ---- Claim / Revert rules ----

    public function test_an_empty_record_cannot_be_claimed(): void
    {
        $member = $this->member();
        $record = $this->records($member)->first();

        $this->claim($this->admin(), $member, $record)
            ->assertSessionHas('status', fn ($s) => str_contains($s, BenefitPeriodClaimController::NOTHING_TO_CLAIM));

        $this->assertFalse($record->fresh()->isClaimed());
    }

    public function test_only_the_latest_record_of_a_cycle_can_be_reverted(): void
    {
        $member = $this->member();
        $admin = $this->admin();
        $this->file($member, 500, 'OR-1');
        $this->claim($admin, $member, $this->records($member)->first());
        $this->file($member, 500, 'OR-2');
        $this->claim($admin, $member, $this->records($member)->last());

        $this->revert($admin, $member, $this->records($member)->first())
            ->assertSessionHas('status', fn ($s) => str_contains($s, BenefitPeriodClaimController::LATER_RECORD_EXISTS));

        $records = $this->records($member);
        $this->assertTrue($records[0]->isClaimed());
        $this->assertTrue($records[1]->isClaimed());
        $this->assertFalse($records[0]->isReverted());
    }

    public function test_a_reverted_record_is_the_open_record_again_and_receives_the_next_filing(): void
    {
        $member = $this->member();
        $admin = $this->admin();
        $this->file($member, 500, 'OR-1');
        $this->claim($admin, $member, $this->records($member)->first());
        $this->file($member, 500, 'OR-2');
        $this->claim($admin, $member, $this->records($member)->last());
        $this->revert($admin, $member, $this->records($member)->last());

        // The reverted record accepts reimbursements again; the claimed one stays locked; no conflicting third record.
        $this->file($member, 100, 'OR-3')->assertSessionHasNoErrors();

        $records = $this->records($member);
        $this->assertSame(2, $records->count());
        $this->assertSame($records[1]->id, $this->reimbursement($member, 'OR-3')->benefit_period_id);
        $this->assertSame(1, $records[0]->reimbursements()->count());
        $this->assertSame(2, $records[1]->reimbursements()->count());

        // A second revert of an already-open record is refused.
        $this->revert($admin, $member, $records[1])
            ->assertSessionHas('status', fn ($s) => str_contains($s, BenefitPeriodClaimController::ALREADY_REVERTED));
    }

    public function test_claim_and_revert_are_enforced_on_the_server(): void
    {
        $member = $this->member();
        $other = $this->member();
        $admin = $this->admin();
        $staff = User::factory()->create(['role' => 'user', 'is_active' => true]);
        $this->file($member, 500, 'OR-1');
        $record = $this->records($member)->first();
        $this->file($other, 500, 'OR-9');
        $foreign = $this->records($other)->first();

        $this->claim($staff, $member, $record)->assertForbidden();
        $this->revert($staff, $member, $record)->assertForbidden();

        $this->claim($admin, $member, $foreign)->assertNotFound();
        $this->revert($admin, $member, $foreign)->assertNotFound();

        // A second Claim changes nothing.
        $this->claim($admin, $member, $record);
        $claimedAt = $record->fresh()->claimed_at;
        $this->claim($admin, $member, $record)->assertSessionHas('status', fn ($s) => str_contains($s, 'already Claimed'));
        $this->assertTrue($record->fresh()->claimed_at->equalTo($claimedAt));

        // Reverting a record that was never claimed.
        $this->revert($admin, $member, $this->records($other)->first())->assertNotFound();
    }

    public function test_a_deactivated_members_records_cannot_be_claimed_or_filed(): void
    {
        $member = $this->member();
        $admin = $this->admin();
        $this->file($member, 500, 'OR-1');
        $record = $this->records($member)->first();
        $member->update(['is_active' => false, 'resignation_date' => '2027-01-10']);

        $this->claim($admin, $member, $record)->assertSessionHas('status', EnsureMemberIsActive::MESSAGE);
        $this->assertFalse($record->fresh()->isClaimed());

        $this->file($member, 100, 'OR-2')->assertSessionHas('status', EnsureMemberIsActive::MESSAGE);
        $this->assertSame(1, Reimbursement::where('member_id', $member->id)->count());
    }

    // ---- The claim lock cannot be bypassed ----

    public function test_a_reimbursement_of_a_claimed_record_cannot_be_edited_or_voided(): void
    {
        $member = $this->member();
        $admin = $this->admin();
        $this->file($member, 1000, 'OR-1');
        $reimbursement = $this->reimbursement($member, 'OR-1');
        $this->claim($admin, $member, $this->records($member)->first());

        $this->actingAs($admin)->put(route('members.reimbursements.update', [$member, $reimbursement]), [
            'or_no' => 'OR-1', 'or_date' => '2026-12-10', 'or_amount' => 2000,
        ])->assertSessionHas('status', ReimbursementController::LOCKED_MESSAGE);

        $this->actingAs($admin)->post(route('members.reimbursements.void', [$member, $reimbursement]), ['reason' => 'oops'])
            ->assertSessionHas('status', ReimbursementController::LOCKED_MESSAGE);

        $reimbursement->refresh();
        $this->assertSame(1000.0, (float) $reimbursement->or_amount);
        $this->assertFalse((bool) $reimbursement->is_voided);

        // After a Revert of its record it can be edited again.
        $this->revert($admin, $member, $this->records($member)->first());
        $this->actingAs($admin)->put(route('members.reimbursements.update', [$member, $reimbursement]), [
            'or_no' => 'OR-1', 'or_date' => '2026-12-10', 'or_amount' => 1200,
        ])->assertSessionHasNoErrors();
        $this->assertSame(1200.0, (float) $reimbursement->fresh()->or_amount);
    }

    public function test_submitting_the_same_filing_repeatedly_creates_one_reimbursement_and_one_record(): void
    {
        $member = $this->member();

        $this->file($member, 1000, 'OR-1', 'same-token')->assertSessionHas('status', fn ($s) => str_contains($s, 'recorded'));
        $this->file($member, 1000, 'OR-1', 'same-token')->assertSessionHas('status', ReimbursementController::DUPLICATE_MESSAGE);

        $this->assertSame(1, Reimbursement::where('member_id', $member->id)->count());
        $this->assertSame(1, $this->records($member)->count());
    }

    // ---- GHP increases: only the open record's excess is reachable ----

    public function test_a_gHP_adjustment_does_not_cover_a_claimed_records_excess_and_the_increase_is_measured_correctly(): void
    {
        $member = $this->member();
        $this->file($member, 3750, 'OR-1');                                    // excess 750
        $this->claim($this->admin(), $member, $this->records($member)->first());

        $first = $this->adjustTo($member, 4200);                               // 10 x (350 - 300) = +500
        $this->assertSame(500.0, (float) $first->increase_amount);
        $this->assertSame(0.0, (float) $first->excess_covered_amount);

        // Measured from the amount in force BEFORE this adjustment (4,200), not the claimed record's stale 3,600:
        // 10 x (400 - 350) = +500, never +1,000.
        $second = $this->adjustTo($member, 4800);
        $this->assertSame(500.0, (float) $second->increase_amount);

        $reimbursement = $this->reimbursement($member, 'OR-1');
        $this->assertSame(750.0, (float) $reimbursement->excess_amount);
        $this->assertSame(0.0, (float) $reimbursement->excess_covered_amount);
    }

    public function test_a_dependent_increase_leaves_a_claimed_records_excess_alone(): void
    {
        $member = $this->member();
        $this->file($member, 3750, 'OR-1');
        $this->claim($this->admin(), $member, $this->records($member)->first());

        $this->addEligibleDependent($member);

        $reimbursement = $this->reimbursement($member, 'OR-1');
        $this->assertSame(750.0, (float) $reimbursement->excess_amount);
        $this->assertSame(0.0, (float) $reimbursement->excess_covered_amount);
        $this->assertSame(4200.0, (float) $member->fresh()->ghp_amount);
    }

    public function test_a_gHP_increase_covers_the_excess_of_the_open_record_only(): void
    {
        $member = $this->member();
        $admin = $this->admin();
        $this->file($member, 2900, 'OR-1');                                    // Available 100 left, no excess
        $this->claim($admin, $member, $this->records($member)->first());
        $this->file($member, 600, 'OR-2');                                     // available 100 -> excess 500, in record 2

        $this->adjustTo($member, 4200);                                        // +500

        $this->assertSame(0.0, (float) $this->reimbursement($member, 'OR-1')->excess_covered_amount);
        $second = $this->reimbursement($member, 'OR-2');
        $this->assertSame(500.0, (float) $second->excess_covered_amount);
        $this->assertSame(0.0, (float) $second->excess_amount);
    }

    public function test_reverting_an_adjustment_that_covered_a_claimed_record_is_refused(): void
    {
        $member = $this->member();
        $admin = $this->admin();
        $this->file($member, 3750, 'OR-1');
        $adjustment = $this->adjustTo($member, 4200);                          // covers 500 while still unclaimed
        $this->claim($admin, $member, $this->records($member)->first());

        $result = app(AmountAdjustmentService::class)->revertAdjustment($member->fresh(), $adjustment, $admin);

        $this->assertIsString($result);
        $this->assertStringContainsString('Claimed', $result);
        $this->assertSame(500.0, (float) $this->reimbursement($member, 'OR-1')->excess_covered_amount);
    }

    // ---- Display and reports ----

    public function test_the_member_page_lists_one_row_per_record_with_its_reimbursements_grouped(): void
    {
        $member = $this->member();
        $admin = $this->admin();
        $this->file($member, 500, 'OR-1');
        $this->file($member, 700, 'OR-2');
        $this->claim($admin, $member, $this->records($member)->first());
        $this->file($member, 600, 'OR-3');

        $html = $this->actingAs($admin)->get(route('members.show', $member))->assertOk()->getContent();

        $this->assertSame(2, substr_count($html, 'data-benefit-record-id="'));
        $this->assertSame(1, substr_count($html, 'data-record-status="claimed"'));
        $this->assertSame(1, substr_count($html, 'data-record-status="unclaimed"'));
        $this->assertSame(2, substr_count($html, 'Apr 2026 &ndash; Mar 2027'));
        $this->assertSame(2, substr_count($html, 'data-record-reimbursements="'));
        $this->assertSame(3, substr_count($html, 'data-reimbursement-id="'));
        // Only the NEWEST record of the cycle renders buttons (task.md §2.37): record 1 is Claimed but a
        // newer record exists, so it shows none; record 2 has a live Claim and a disabled Revert.
        $this->assertSame(1, substr_count($html, 'data-record-actions="none"'));
        $this->assertSame(1, substr_count($html, 'data-record-action="claim"'));
        $this->assertSame(1, substr_count($html, 'data-record-action="revert"'));
        $this->assertSame(1, preg_match_all('/<button[^>]*data-record-action="claim"(?![^>]*\bdisabled\b)[^>]*>/s', $html));
        $this->assertSame(0, preg_match_all('/<button[^>]*data-record-action="revert"(?![^>]*\bdisabled\b)[^>]*>/s', $html));
    }

    public function test_the_reimbursement_card_still_shows_the_complete_history(): void
    {
        $member = $this->member();
        $admin = $this->admin();
        foreach (['OR-1' => 500, 'OR-2' => 700, 'OR-3' => 800] as $orNo => $amount) {
            $this->file($member, $amount, $orNo);
        }
        $this->claim($admin, $member, $this->records($member)->first());
        $this->file($member, 600, 'OR-4');
        $this->file($member, 400, 'OR-5');

        $html = $this->actingAs($admin)->get(route('members.show', $member))->assertOk()->getContent();

        foreach (['OR-1', 'OR-2', 'OR-3', 'OR-4', 'OR-5'] as $orNo) {
            $this->assertStringContainsString($orNo, $html);
        }
        $this->assertSame(5, Reimbursement::where('member_id', $member->id)->count());
    }

    public function test_the_annual_report_counts_a_cycle_once_however_many_records_it_has(): void
    {
        $member = $this->member();
        $this->file($member, 1000, 'OR-1');
        $this->claim($this->admin(), $member, $this->records($member)->first());
        $this->file($member, 800, 'OR-2');

        $response = $this->actingAs($this->admin())->get(route('reports.annual-ghp.csv', ['year' => 2026]))->assertOk();
        $csv = $response->streamedContent();

        $lines = array_values(array_filter(explode("\n", $csv), fn ($line) => str_contains($line, $member->code)));
        $this->assertCount(1, $lines);                                          // one row for the member, not one per record
        $this->assertStringContainsString('3600.00', $lines[0]);                // entitlement once
        $this->assertStringContainsString('1800.00', $lines[0]);                // Used = both records' shares
        $this->assertStringContainsString('1200.00', $lines[0]);                // Available = what is left of the cycle
    }

    public function test_the_member_data_record_and_receipts_still_work_with_several_records(): void
    {
        $member = $this->member();
        $admin = $this->admin();
        $this->file($member, 1000, 'OR-1');
        $this->claim($admin, $member, $this->records($member)->first());
        $this->file($member, 800, 'OR-2');

        $this->actingAs($admin)->get(route('members.mdr', $member))->assertOk();

        foreach ($this->records($member) as $record) {
            $this->actingAs($admin)->get(route('members.benefit-periods.reimbursements', [$member, $record]))->assertOk();
            $this->actingAs($admin)
                ->get(route('members.benefit-periods.reimbursements.pdf', [$member, $record]))
                ->assertOk()
                ->assertHeader('content-type', 'application/pdf');
        }
    }

    // ---- Existing data and generation ----

    public function test_generation_still_creates_exactly_one_record_per_cycle(): void
    {
        $member = $this->member();                                              // already generated (record #1)

        $this->actingAs($this->admin())->post(route('members.generate-benefit-period', $member))
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'already generated'));

        $this->assertSame(1, $this->records($member)->count());
    }

    public function test_the_unique_key_still_refuses_a_duplicate_record(): void
    {
        $member = $this->member();
        $record = $this->records($member)->first();

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        BenefitPeriod::create([
            'member_id' => $member->id,
            'from_date' => $record->from_date->toDateString(),
            'to_date' => $record->to_date->toDateString(),
            'record_no' => $record->record_no,
            'ghp_amount' => 3600, 'ghp_used' => 0, 'ghp_available' => 3000,
            'member_type' => $member->member_type,
        ]);
    }
}
