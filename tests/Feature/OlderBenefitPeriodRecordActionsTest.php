<?php

namespace Tests\Feature;

use App\Http\Controllers\BenefitPeriodClaimController;
use App\Models\BenefitPeriod;
use App\Models\Member;
use App\Models\Reimbursement;
use App\Models\User;
use App\Services\BenefitAccrualService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Once a NEWER Benefit Period record exists for the same member and coverage
 * period, the OLDER record(s) offer neither Claim nor Revert (task.md §2.37).
 *
 * Decided from the saved records (record_no within the same member + from_date +
 * to_date), never in JavaScript, and enforced on the server for direct requests too.
 * Nothing about the older record changes: its status, figures and reimbursements
 * stay exactly as they were.
 *
 * Fixture: today is 2027-01-15 in the Apr 2026 - Mar 2027 cycle, fund 3,000.
 *
 * NOT YET RUN — run `php artisan test --filter=OlderBenefitPeriodRecordActionsTest`.
 */
class OlderBenefitPeriodRecordActionsTest extends TestCase
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

    private function member(): Member
    {
        $member = Member::factory()->create([
            'member_type' => Member::MEMBER_TYPE_EMPLOYEE,
            'is_active' => true,
            'deduction_start_date' => '2026-04-01',
            'ghp_amount' => 3600,
            'ghp_amount_is_manual' => false,
            'email' => 'older'.(++$this->memberSeq).'@example.com',
        ]);

        app(BenefitAccrualService::class)->accrue($member->fresh());

        return $member->fresh();
    }

    private function file(Member $member, float $amount, string $orNo)
    {
        return $this->actingAs($this->admin())->post(route('members.reimbursements.store', $member), [
            'or_no' => $orNo,
            'or_date' => '2026-12-10',
            'or_amount' => $amount,
            'submission_token' => 'tok-'.(++$this->tokenSeq).'-'.$orNo,
        ]);
    }

    /** The member's records for the CURRENT cycle, in record order. */
    private function records(Member $member)
    {
        return BenefitPeriod::where('member_id', $member->id)
            ->whereDate('from_date', '2026-04-01')
            ->orderBy('record_no')->orderBy('id')->get();
    }

    private function claim(User $by, Member $member, BenefitPeriod $record)
    {
        return $this->actingAs($by)->post(route('members.benefit-periods.claim', [$member, $record]));
    }

    private function revert(User $by, Member $member, BenefitPeriod $record)
    {
        return $this->actingAs($by)->post(route('members.benefit-periods.revert', [$member, $record]));
    }

    private function page(User $by, Member $member): string
    {
        return $this->actingAs($by)->get(route('members.show', $member))->assertOk()->getContent();
    }

    /** The HTML of ONE record's row in the Benefit periods table. */
    private function row(string $html, BenefitPeriod $record): string
    {
        $this->assertSame(1, preg_match('/<tr data-benefit-record-id="'.$record->id.'"[^>]*>(.*?)<\/tr>/s', $html, $m), "Row for record {$record->id} not found");

        return $m[1];
    }

    private function hasLiveButton(string $row, string $action): bool
    {
        return preg_match('/<button[^>]*data-record-action="'.$action.'"(?![^>]*\bdisabled\b)[^>]*>/s', $row) === 1;
    }

    private function hasDisabledButton(string $row, string $action): bool
    {
        return preg_match('/<button[^>]*data-record-action="'.$action.'"[^>]*\bdisabled\b[^>]*>/s', $row) === 1;
    }

    /** Record #1 claimed (500 + 300), then one more filing => record #2 (Unclaimed). */
    private function memberWithTwoRecords(): array
    {
        $member = $this->member();
        $admin = $this->admin();
        $this->file($member, 500, 'OR-1');
        $this->file($member, 300, 'OR-2');
        $this->claim($admin, $member, $this->records($member)->first());
        $this->file($member, 200, 'OR-3');

        $records = $this->records($member);
        $this->assertCount(2, $records);

        return [$member, $records[0], $records[1], $admin];
    }

    // ---- 1. One claimed record, no newer record: the existing rules apply ----

    public function test_a_single_claimed_record_keeps_its_existing_actions(): void
    {
        $member = $this->member();
        $admin = $this->admin();
        $this->file($member, 500, 'OR-1');
        $record = $this->records($member)->first();
        $this->claim($admin, $member, $record);

        $row = $this->row($this->page($admin, $member), $record);

        $this->assertStringNotContainsString('data-record-actions="none"', $row);
        $this->assertTrue($this->hasDisabledButton($row, 'claim'));        // already Claimed
        $this->assertTrue($this->hasLiveButton($row, 'revert'));           // can be reverted: it is the latest record
    }

    public function test_an_unclaimed_latest_record_keeps_claim_and_a_disabled_revert(): void
    {
        $member = $this->member();
        $admin = $this->admin();
        $this->file($member, 500, 'OR-1');

        $row = $this->row($this->page($admin, $member), $this->records($member)->first());

        $this->assertTrue($this->hasLiveButton($row, 'claim'));
        $this->assertTrue($this->hasDisabledButton($row, 'revert'));
    }

    // ---- 2. A second record exists: the older one shows no Claim and no Revert ----

    public function test_the_older_record_shows_no_buttons_once_a_newer_record_exists(): void
    {
        [$member, $older, $newer, $admin] = $this->memberWithTwoRecords();

        $html = $this->page($admin, $member);
        $olderRow = $this->row($html, $older);

        $this->assertStringContainsString('data-record-actions="none"', $olderRow);
        $this->assertStringNotContainsString('data-record-action=', $olderRow);          // no Claim, no Revert
        $this->assertStringNotContainsString('openRevertBenefitPeriod(', $olderRow);     // no handler left behind
        $this->assertStringNotContainsString('<form', $olderRow);                         // no claim form either
        $this->assertStringContainsString('data-record-status="claimed"', $html);         // its status is still shown
        $this->assertStringContainsString('Claimed', $olderRow);
    }

    // ---- 3. The new record follows the normal rules ----

    public function test_the_new_record_follows_its_own_status_rules(): void
    {
        [$member, $older, $newer, $admin] = $this->memberWithTwoRecords();

        // Unclaimed: Claim live, Revert disabled.
        $row = $this->row($this->page($admin, $member), $newer);
        $this->assertTrue($this->hasLiveButton($row, 'claim'));
        $this->assertTrue($this->hasDisabledButton($row, 'revert'));

        // Once claimed: Claim disabled, Revert live — and the older record still shows nothing.
        $this->claim($admin, $member, $newer);
        $html = $this->page($admin, $member);
        $row = $this->row($html, $newer);
        $this->assertTrue($this->hasDisabledButton($row, 'claim'));
        $this->assertTrue($this->hasLiveButton($row, 'revert'));
        $this->assertStringContainsString('data-record-actions="none"', $this->row($html, $older));
    }

    public function test_with_three_records_only_the_newest_has_buttons(): void
    {
        $member = $this->member();
        $admin = $this->admin();
        foreach (['OR-1', 'OR-2'] as $orNo) {
            $this->file($member, 100, $orNo);
            $this->claim($admin, $member, $this->records($member)->last());
        }
        $this->file($member, 100, 'OR-3');

        $html = $this->page($admin, $member);

        $this->assertCount(3, $this->records($member));
        $this->assertSame(2, substr_count($html, 'data-record-actions="none"'));
        $this->assertSame(1, substr_count($html, 'data-record-action="claim"'));
        $this->assertSame(1, substr_count($html, 'data-record-action="revert"'));
    }

    // ---- 4 + 5. Other members and other coverage periods are unaffected ----

    public function test_another_members_record_is_unaffected(): void
    {
        [$member, , , $admin] = $this->memberWithTwoRecords();
        $other = $this->member();
        $this->file($other, 500, 'OR-9');
        $otherRecord = $this->records($other)->first();
        $this->claim($admin, $other, $otherRecord);

        $row = $this->row($this->page($admin, $other), $otherRecord);

        $this->assertStringNotContainsString('data-record-actions="none"', $row);
        $this->assertTrue($this->hasLiveButton($row, 'revert'));
        // ...and its backend actions still work.
        $this->revert($admin, $other, $otherRecord)->assertSessionHas('status', fn ($s) => str_contains($s, 'reverted'));
        $this->assertTrue($otherRecord->fresh()->isReverted());
    }

    public function test_a_record_of_another_coverage_period_is_unaffected(): void
    {
        $member = $this->member();                                  // current cycle: record #1 (Apr 2026 - Mar 2027)
        $admin = $this->admin();

        // A PREVIOUS cycle's single, claimed record (Apr 2025 - Mar 2026) with one reimbursement.
        Carbon::setTestNow(Carbon::parse('2025-06-15 09:00:00'));
        $previous = app(BenefitAccrualService::class)->accrue($member->fresh());
        Carbon::setTestNow(Carbon::parse('2027-01-15 09:00:00'));
        $member->reimbursements()->create(['or_no' => 'OR-OLD', 'or_date' => '2025-06-10', 'or_amount' => 400, 'benefit_period_id' => $previous->id]);
        $previous->markClaimed($admin);

        // The CURRENT cycle now gets a second record.
        $this->file($member, 500, 'OR-1');
        $this->claim($admin, $member, $this->records($member)->first());
        $this->file($member, 200, 'OR-2');
        $this->assertCount(2, $this->records($member));

        $html = $this->page($admin, $member);
        $previousRow = $this->row($html, $previous->fresh());

        // Its own cycle has only this one record, so it keeps its actions.
        $this->assertStringNotContainsString('data-record-actions="none"', $previousRow);
        $this->assertTrue($this->hasLiveButton($previousRow, 'revert'));
        // ...while the older record of the CURRENT cycle has none.
        $this->assertStringContainsString('data-record-actions="none"', $this->row($html, $this->records($member)->first()));

        $this->revert($admin, $member, $previous)->assertSessionHas('status', fn ($s) => str_contains($s, 'reverted'));
        $this->assertTrue($previous->fresh()->isReverted());
    }

    // ---- 6. Persisted state: the same on every load ----

    public function test_the_visibility_is_the_same_after_refreshing_and_reopening(): void
    {
        [$member, $older, $newer, $admin] = $this->memberWithTwoRecords();

        $first = $this->page($admin, $member);
        $second = $this->page($admin, $member);
        $third = $this->page($this->admin(), $member);        // a different admin session

        foreach ([$first, $second, $third] as $html) {
            $this->assertStringContainsString('data-record-actions="none"', $this->row($html, $older));
            $this->assertStringNotContainsString('data-record-action=', $this->row($html, $older));
            $this->assertTrue($this->hasLiveButton($this->row($html, $newer), 'claim'));
        }
    }

    // ---- 7. Direct requests cannot bypass it ----

    public function test_a_direct_claim_or_revert_of_the_older_record_is_refused_with_the_reason(): void
    {
        [$member, $older, $newer, $admin] = $this->memberWithTwoRecords();

        $this->revert($admin, $member, $older)
            ->assertRedirect(route('members.show', $member))
            ->assertSessionHas('status', fn ($s) => str_contains($s, BenefitPeriodClaimController::NOT_LATEST_RECORD));

        $this->claim($admin, $member, $older)
            ->assertSessionHas('status', fn ($s) => str_contains($s, BenefitPeriodClaimController::NOT_LATEST_RECORD));

        $older->refresh();
        $this->assertTrue($older->isClaimed());              // unchanged: still Claimed, not reverted
        $this->assertNull($older->reverted_at);
        $this->assertStringContainsString('newer Benefit Period record', BenefitPeriodClaimController::NOT_LATEST_RECORD);
    }

    public function test_the_older_record_stays_closed_even_after_the_newer_one_is_reverted(): void
    {
        [$member, $older, $newer, $admin] = $this->memberWithTwoRecords();
        $this->claim($admin, $member, $newer);
        $this->revert($admin, $member, $newer);              // the newest record is allowed to revert

        $this->assertTrue($newer->fresh()->isReverted());

        // A reverted newer record still EXISTS, so the older one must not reopen next to it.
        $this->revert($admin, $member, $older)
            ->assertSessionHas('status', fn ($s) => str_contains($s, BenefitPeriodClaimController::NOT_LATEST_RECORD));
        $this->assertTrue($older->fresh()->isClaimed());
    }

    public function test_an_older_unclaimed_record_cannot_be_claimed_either(): void
    {
        $member = $this->member();
        $admin = $this->admin();
        $this->file($member, 500, 'OR-1');
        $older = $this->records($member)->first();

        // A newer record appears for the same member and period (record #2).
        BenefitPeriod::create([
            'member_id' => $member->id,
            'from_date' => $older->from_date->toDateString(),
            'to_date' => $older->to_date->toDateString(),
            'record_no' => 2,
            'coverage_year' => $older->coverage_year,
            'ghp_amount' => 3600, 'ghp_used' => 0, 'ghp_available' => 2500,
            'member_type' => $member->member_type,
        ]);

        $this->claim($admin, $member, $older)
            ->assertSessionHas('status', fn ($s) => str_contains($s, BenefitPeriodClaimController::NOT_LATEST_RECORD));

        $this->assertFalse($older->fresh()->isClaimed());
    }

    public function test_the_existing_authorization_rules_are_unchanged(): void
    {
        [$member, $older, $newer] = $this->memberWithTwoRecords();
        $staff = User::factory()->create(['role' => 'user', 'is_active' => true]);

        $this->claim($staff, $member, $newer)->assertForbidden();
        $this->revert($staff, $member, $older)->assertForbidden();

        $other = $this->member();
        $this->claim($this->admin(), $other, $older)->assertNotFound();       // another member's record via the wrong URL
    }

    // ---- 8. Nothing about the older record, the balances or the history changes ----

    public function test_the_older_record_balances_and_history_are_unchanged_by_all_of_this(): void
    {
        $member = $this->member();
        $admin = $this->admin();
        $this->file($member, 500, 'OR-1');
        $this->file($member, 300, 'OR-2');
        $older = $this->records($member)->first();
        $this->claim($admin, $member, $older);

        $snapshot = fn () => [
            $older->fresh()->only(['record_no', 'from_date', 'to_date', 'ghp_amount', 'ghp_used', 'ghp_available', 'claimed_at', 'claimed_by', 'reverted_at', 'reverted_by']),
            Reimbursement::where('benefit_period_id', $older->id)->orderBy('id')->get(['id', 'or_no', 'or_amount', 'excess_amount', 'available_ghp'])->toArray(),
        ];
        $before = $snapshot();

        $this->file($member, 200, 'OR-3');                    // creates the newer record
        $this->page($admin, $member);                         // viewing changes nothing
        $this->revert($admin, $member, $older);               // refused
        $this->claim($admin, $member, $older);                // refused

        $this->assertEquals($before, $snapshot());
        $this->assertSame(800.0, (float) $older->fresh()->ghp_used);
        $this->assertSame(2, Reimbursement::where('benefit_period_id', $older->id)->count());
        $this->assertCount(2, $this->records($member));        // and no extra record was created by any of it
    }
}
