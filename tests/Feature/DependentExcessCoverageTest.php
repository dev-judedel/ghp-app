<?php

namespace Tests\Feature;

use App\Models\BenefitPeriod;
use App\Models\Member;
use App\Models\Reimbursement;
use App\Models\User;
use App\Services\BenefitAccrualService;
use App\Services\DependentEligibilityService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * A dependent that becomes eligible immediately raises the fund
 * (GHP amount / 12 x months rendered: 300 -> 350 a month). That INCREASE is
 * applied toward the outstanding excess GHP of the member's active
 * reimbursements — oldest first, never more than the increase, never more than
 * the excess — and any part not needed stays in Available GHP.
 *
 * It is NOT GHP usage: "used" stays where it was, the reimbursement keeps its
 * id and full amount, and excess_amount (the REMAINING excess) shrinks while
 * original_excess_amount / excess_covered_amount keep the history.
 *
 * Numbers: today is 2027-01-15 in the Apr 2026 - Mar 2027 cycle, deductions
 * from Apr 2026 => 10 months rendered. Fund = 10 x 300 = 3,000; with a
 * dependent 10 x 350 = 3,500, so the increase is exactly 500.
 *
 * NOT YET RUN — written from reading the code.
 * Run: php artisan test --filter=DependentExcessCoverageTest
 */
class DependentExcessCoverageTest extends TestCase
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
    private function member(): Member
    {
        $member = Member::factory()->create([
            'member_type' => Member::MEMBER_TYPE_EMPLOYEE,
            'is_active' => true,
            'deduction_start_date' => '2026-04-01',
            'ghp_amount' => 3600,
            'ghp_amount_is_manual' => false,
            'email' => 'juan@example.com',
        ]);

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

    /** Adds a spouse (pending under the normal rule) and grants Immediate Eligibility -> fund +500. */
    private function addEligibleDependent(Member $member): void
    {
        $service = app(DependentEligibilityService::class);
        $dependent = $service->addDependent($member->fresh(), ['name' => 'Maria Santos', 'relation' => 'Spouse', 'birthdate' => '1990-01-01']);
        $service->grantImmediate($member->fresh(), $dependent, $this->admin());
    }

    // ---- Test 1: the increase is applied to the excess; used unchanged ----

    public function test_the_dependent_increase_is_applied_to_the_excess_and_used_does_not_change(): void
    {
        $member = $this->member();
        $this->file($member, 3750)->assertSessionHasNoErrors();   // Available 3,000 -> excess 750

        $before = $this->claim($member);
        $this->assertSame(750.0, (float) $before->excess_amount);
        $this->assertSame(750.0, (float) $before->original_excess_amount);
        $this->assertSame(0.0, (float) $before->excess_covered_amount);
        $this->assertSame(3000.0, (float) $this->period($member)->ghp_used);
        $this->assertSame(0.0, (float) $this->period($member)->ghp_available);

        $this->addEligibleDependent($member);

        $claim = $this->claim($member);
        $period = $this->period($member);

        // Same record, same amount; only the excess figures moved.
        $this->assertSame($before->id, $claim->id);
        $this->assertSame(3750.0, (float) $claim->or_amount);
        $this->assertSame(3000.0, (float) $claim->available_ghp);          // as recorded at filing
        $this->assertSame(750.0, (float) $claim->original_excess_amount);
        $this->assertSame(500.0, (float) $claim->excess_covered_amount);
        $this->assertSame(250.0, (float) $claim->excess_amount);           // remaining
        $this->assertTrue($claim->hasExcess());                            // still red

        // Fund 3,500; Used unchanged at 3,000; Available 0; the 500 is "covered", not used.
        $this->assertSame(4200.0, (float) $period->ghp_amount);
        $this->assertSame(3000.0, (float) $period->ghp_used);
        $this->assertSame(0.0, (float) $period->ghp_available);
        $this->assertSame(1, Reimbursement::where('member_id', $member->id)->count());
    }

    // ---- Test 2: the increase fully covers the excess ----

    public function test_an_increase_that_covers_the_excess_removes_it_and_the_rest_stays_available(): void
    {
        $member = $this->member();
        $this->file($member, 3400);   // Available 3,000 -> excess 400

        $this->addEligibleDependent($member);   // fund +500

        $claim = $this->claim($member);
        $period = $this->period($member);

        $this->assertSame(400.0, (float) $claim->original_excess_amount);
        $this->assertSame(400.0, (float) $claim->excess_covered_amount);   // only what was needed
        $this->assertSame(0.0, (float) $claim->excess_amount);
        $this->assertFalse($claim->hasExcess());                           // no longer red
        $this->assertSame(3400.0, (float) $claim->or_amount);

        $this->assertSame(3000.0, (float) $period->ghp_used);              // unchanged
        $this->assertSame(100.0, (float) $period->ghp_available);          // the unused 100 of the 500 stays
    }

    // ---- Test 3: the increase is bigger than the excess ----

    public function test_only_the_amount_needed_is_applied_when_the_increase_exceeds_the_excess(): void
    {
        $member = $this->member();
        $this->file($member, 3200);   // excess 200

        $this->addEligibleDependent($member);   // increase 500

        $claim = $this->claim($member);
        $period = $this->period($member);

        $this->assertSame(200.0, (float) $claim->excess_covered_amount);
        $this->assertSame(0.0, (float) $claim->excess_amount);
        $this->assertSame(300.0, (float) $period->ghp_available);          // remaining Available GHP
        $this->assertSame(3000.0, (float) $period->ghp_used);
    }

    // ---- Test 4: nothing to cover ----

    public function test_a_member_with_no_excess_just_gets_the_higher_available_ghp(): void
    {
        $member = $this->member();

        $this->addEligibleDependent($member);

        $period = $this->period($member);
        $this->assertSame(3500.0, (float) $period->ghp_available);        // 10 x 350
        $this->assertSame(0.0, (float) $period->ghp_used);
        $this->assertSame(0, Reimbursement::where('member_id', $member->id)->count());
    }

    public function test_a_claim_within_the_available_ghp_is_left_alone_when_a_dependent_is_added(): void
    {
        $member = $this->member();
        $this->file($member, 2000);   // within 3,000 -> no excess

        $this->addEligibleDependent($member);

        $claim = $this->claim($member);
        $period = $this->period($member);

        $this->assertSame(0.0, (float) $claim->excess_amount);
        $this->assertSame(0.0, (float) $claim->excess_covered_amount);
        $this->assertSame(2000.0, (float) $period->ghp_used);
        $this->assertSame(1500.0, (float) $period->ghp_available);         // 3,500 - 2,000
    }

    public function test_a_dependent_that_is_still_pending_changes_nothing(): void
    {
        $member = $this->member();
        $this->file($member, 3750);

        $service = app(DependentEligibilityService::class);
        $service->addDependent($member->fresh(), ['name' => 'Maria Santos', 'relation' => 'Spouse', 'birthdate' => '1990-01-01']);
        $service->recalculateBenefit($member->fresh());   // normal rule: eligible next cycle

        $claim = $this->claim($member);
        $this->assertSame(0.0, (float) $claim->excess_covered_amount);
        $this->assertSame(750.0, (float) $claim->excess_amount);
        $this->assertSame(3600.0, (float) $this->period($member)->ghp_amount);
    }

    // ---- Test 5: several claims with excess -> oldest first, never more than the increase ----

    public function test_the_increase_is_applied_oldest_claim_first_without_exceeding_it(): void
    {
        $member = $this->member();
        $accrual = app(BenefitAccrualService::class);
        [$from, $to] = $accrual->coveragePeriod($member, now());

        // Two active claims carrying excess (created directly: filing a second
        // over-balance claim is blocked by the "positive Available GHP" rule).
        $b = $member->reimbursements()->create(['or_no' => 'OR-B', 'or_date' => '2026-08-01', 'or_amount' => 1000, 'excess_amount' => 500, 'original_excess_amount' => 500]);
        $a = $member->reimbursements()->create(['or_no' => 'OR-A', 'or_date' => '2026-06-01', 'or_amount' => 1000, 'excess_amount' => 300, 'original_excess_amount' => 300]);
        // A voided claim is never touched.
        $voided = $member->reimbursements()->create(['or_no' => 'OR-V', 'or_date' => '2026-05-01', 'or_amount' => 1000, 'excess_amount' => 400, 'original_excess_amount' => 400]);
        $voided->void('Filed in error', $this->admin());

        $applied = $accrual->applyFundIncreaseToExcess($member, $from, $to, 500.0);

        $this->assertSame(500.0, $applied);

        // 300 to the older claim A, the remaining 200 to B (B keeps 300).
        $a->refresh();
        $b->refresh();
        $this->assertSame(0.0, (float) $a->excess_amount);
        $this->assertSame(300.0, (float) $a->excess_covered_amount);
        $this->assertSame(300.0, (float) $b->excess_amount);
        $this->assertSame(200.0, (float) $b->excess_covered_amount);
        $this->assertSame(500.0, (float) $b->original_excess_amount);

        $voided->refresh();
        $this->assertSame(400.0, (float) $voided->excess_amount);
        $this->assertSame(0.0, (float) $voided->excess_covered_amount);
    }

    public function test_a_smaller_increase_only_partly_covers_the_first_claim(): void
    {
        $member = $this->member();
        $accrual = app(BenefitAccrualService::class);
        [$from, $to] = $accrual->coveragePeriod($member, now());

        $a = $member->reimbursements()->create(['or_no' => 'OR-A', 'or_date' => '2026-06-01', 'or_amount' => 1000, 'excess_amount' => 300, 'original_excess_amount' => 300]);
        $b = $member->reimbursements()->create(['or_no' => 'OR-B', 'or_date' => '2026-08-01', 'or_amount' => 1000, 'excess_amount' => 500, 'original_excess_amount' => 500]);

        $this->assertSame(100.0, $accrual->applyFundIncreaseToExcess($member, $from, $to, 100.0));

        $this->assertSame(200.0, (float) $a->fresh()->excess_amount);
        $this->assertSame(500.0, (float) $b->fresh()->excess_amount);
        $this->assertSame(0.0, $accrual->applyFundIncreaseToExcess($member, $from, $to, 0.0));
    }

    // ---- Test 6: the same increase is never applied twice ----

    public function test_refreshing_editing_or_recalculating_does_not_apply_the_increase_again(): void
    {
        $member = $this->member();
        $this->file($member, 3750);
        $service = app(DependentEligibilityService::class);
        $dependent = $service->addDependent($member->fresh(), ['name' => 'Maria Santos', 'relation' => 'Spouse', 'birthdate' => '1990-01-01']);
        $service->grantImmediate($member->fresh(), $dependent, $this->admin());

        $this->assertSame(500.0, (float) $this->claim($member)->excess_covered_amount);

        // Recalculate again and again, view the pages, edit the dependent.
        $service->recalculateBenefit($member->fresh());
        $service->recalculateBenefit($member->fresh());
        app(BenefitAccrualService::class)->refreshCurrentPeriodIfAmountChanged($member->fresh());

        $admin = $this->admin();
        $this->actingAs($admin)->get(route('members.show', $member))->assertOk();
        $this->actingAs($admin)->put(route('members.dependents.update', [$member, $dependent]), [
            'name' => 'Maria S. Santos', 'relation' => 'Spouse', 'birthdate' => '1990-01-01',
        ]);

        $claim = $this->claim($member);
        $this->assertSame(500.0, (float) $claim->excess_covered_amount);
        $this->assertSame(250.0, (float) $claim->excess_amount);

        // Granting it a second time is refused outright.
        $this->expectException(RuntimeException::class);
        $service->grantImmediate($member->fresh(), $dependent->fresh(), $admin);
    }

    public function test_editing_a_covered_claim_keeps_what_was_already_covered(): void
    {
        $member = $this->member();
        $this->file($member, 3750);
        $this->addEligibleDependent($member);
        $claim = $this->claim($member);

        // Raise the claim to 3,900: excess measured against the recorded 3,000 is 900;
        // the 500 already covered is kept, so 400 remains.
        $this->actingAs($this->admin())->put(route('members.reimbursements.update', [$member, $claim]), [
            'or_no' => 'OR-1', 'or_date' => '2026-12-10', 'or_amount' => 3900,
        ])->assertSessionHasNoErrors();

        $claim->refresh();
        $this->assertSame(900.0, (float) $claim->original_excess_amount);
        $this->assertSame(500.0, (float) $claim->excess_covered_amount);
        $this->assertSame(400.0, (float) $claim->excess_amount);
    }

    // ---- The engine: used vs covered ----

    public function test_the_engine_reports_the_covered_excess_separately_from_used(): void
    {
        $member = $this->member();
        $this->file($member, 3750);
        $this->addEligibleDependent($member);

        $result = app(BenefitAccrualService::class)->calculate($member->fresh());

        $this->assertSame(3500.0, $result['accrued']);
        $this->assertSame(3000.0, $result['used']);            // not 3,500
        $this->assertSame(500.0, $result['excess_covered']);
        $this->assertSame(0.0, $result['available']);
        // used + covered + available always add back up to the fund.
        $this->assertSame($result['accrued'] + $result['carry_forward'], $result['used'] + $result['excess_covered'] + $result['available']);
    }

    public function test_the_original_ghp_requirements_follow_the_normal_dependent_rule_only(): void
    {
        $member = $this->member();
        $this->file($member, 3750);
        $this->addEligibleDependent($member);

        $member->refresh();

        // Only the dependent moved the amount (3,600 -> 4,200 = 350 a month); the excess did not.
        $this->assertSame(4200.0, (float) $member->ghp_amount);
        $required = app(BenefitAccrualService::class)->requiredAmountForCycle($member->fresh(['dependents']));
        $this->assertSame(350.0, $required['monthly_rate']);
        $this->assertSame(0, $member->amountAdjustments()->count());
    }

    // ---- Test 7: table, receipt, print, downloads ----

    public function test_the_records_pages_show_original_covered_and_remaining_excess(): void
    {
        $member = $this->member();
        $this->file($member, 3750);
        $this->addEligibleDependent($member);
        $period = $this->period($member);
        $admin = $this->admin();

        $records = $this->actingAs($admin)->get(route('members.benefit-periods.reimbursements', [$member, $period]))->assertOk();
        $records->assertSee('&#8369;250.00', false)                       // remaining
            ->assertSee('original &#8369;750.00, covered &#8369;500.00', false);
        $this->assertSame(1, substr_count($records->getContent(), 'data-excess="1"'));   // still red

        $this->actingAs($admin)->get(route('members.show', $member))->assertOk()
            ->assertSee('original &#8369;750.00, covered &#8369;500.00', false);
    }

    public function test_a_fully_covered_claim_is_no_longer_red_anywhere(): void
    {
        $member = $this->member();
        $this->file($member, 3200);
        $this->addEligibleDependent($member);
        $period = $this->period($member);
        $admin = $this->actingAs($this->admin());

        $this->assertSame(0, substr_count($admin->get(route('members.benefit-periods.reimbursements', [$member, $period]))->getContent(), 'data-excess="1"'));
        $this->assertSame(0, substr_count($admin->get(route('members.show', $member))->getContent(), 'data-excess="1"'));
    }

    public function test_the_receipt_shows_original_covered_and_remaining_excess(): void
    {
        $member = $this->member();
        $this->file($member, 3750);
        $this->addEligibleDependent($member);
        $period = $this->period($member);
        $reimbursements = $period->reimbursements()->orderBy('or_date')->get();

        $html = view('reports.pdf.reimbursement-receipt', [
            'member' => $member,
            'benefitPeriod' => $period,
            'reimbursements' => $reimbursements,
            'total' => $reimbursements->sum('or_amount'),
            'voidedCount' => 0,
            'excessTotal' => (float) $reimbursements->sum('excess_amount'),
            'excessCoveredTotal' => (float) $reimbursements->sum('excess_covered_amount'),
            'excessOriginalTotal' => (float) $reimbursements->sum('excess_amount') + (float) $reimbursements->sum('excess_covered_amount'),
            'excessDeductions' => collect(),
            'historicExcessTotal' => 0.0,
            'generatedAt' => now(),
            'generatedBy' => $this->admin(),
        ])->render();

        $flat = str_replace('&#8369;', '₱', $html);

        $this->assertStringContainsString('Original Excess GHP: ₱750.00', $flat);
        $this->assertStringContainsString('₱500.00', $flat);
        $this->assertStringContainsString('Remaining Excess GHP): ₱250.00', $flat);
        $this->assertStringContainsString('#C62828', $html);
        $this->assertSame(1, substr_count($html, 'class=" excess"'));
        $this->assertStringContainsString('₱3,750.00', $flat);              // reimbursement amount preserved

        $this->actingAs($this->admin())
            ->get(route('members.benefit-periods.reimbursements.pdf', [$member, $period]))
            ->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_the_csv_export_has_original_covered_and_remaining_excess(): void
    {
        $member = $this->member();
        $this->file($member, 3750);
        $this->addEligibleDependent($member);

        $csv = $this->actingAs($this->admin())->get(route('reports.reimbursements.csv', [
            'from' => '2026-04-01', 'to' => '2027-03-31',
        ]))->assertOk()->streamedContent();

        $rows = array_map('str_getcsv', array_filter(explode("\n", trim($csv))));
        $header = array_shift($rows);
        $row = $rows[0];

        $this->assertEqualsWithDelta(3750.0, (float) $row[array_search('Amount', $header, true)], 0.001);
        $this->assertEqualsWithDelta(3000.0, (float) $row[array_search('Available GHP', $header, true)], 0.001);
        $this->assertEqualsWithDelta(750.0, (float) $row[array_search('Original Excess', $header, true)], 0.001);
        $this->assertEqualsWithDelta(500.0, (float) $row[array_search('Excess Covered', $header, true)], 0.001);
        $this->assertEqualsWithDelta(250.0, (float) $row[array_search('Excess Deduction', $header, true)], 0.001);
    }
}
