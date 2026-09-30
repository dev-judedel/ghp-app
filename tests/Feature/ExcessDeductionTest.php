<?php

namespace Tests\Feature;

use App\Mail\ReimbursementReceiptMail;
use App\Models\BenefitPeriod;
use App\Models\ExcessDeduction;
use App\Models\Member;
use App\Models\Reimbursement;
use App\Models\User;
use App\Services\BenefitAccrualService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * AUTOMATIC excess GHP (replaces the old manual "Record Excess Deduction"
 * button/modal, which is gone):
 *
 *     excess GHP = reimbursement amount - Available GHP   (only when > 0)
 *
 * Calculated by ReimbursementController on the server when a reimbursement is
 * filed (or its amount edited) and stored on the reimbursement itself
 * (reimbursements.available_ghp / excess_amount). Tracking and reporting only:
 * it must never change GHP usage, Available GHP, the GHP amount, the monthly
 * GHP or the required amount.
 *
 * The member below has Available GHP = ₱3,000: a full ₱3,600 cycle (₱300 a
 * month, whole cycle elapsed) with one earlier ₱600 claim already used.
 *
 * NOT YET RUN — written from reading the code.
 * Run: php artisan test --filter=ExcessDeductionTest
 */
class ExcessDeductionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Inside the member's Apr 2026 – Mar 2027 cycle with all 12 months elapsed.
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

    /** A member whose Available GHP is exactly ₱3,000 (3,600 fund - one ₱600 claim already used). */
    private function memberWith3000Available(array $overrides = []): Member
    {
        $member = Member::factory()->create(array_merge([
            'member_type' => Member::MEMBER_TYPE_EMPLOYEE,
            'is_active' => true,
            'deduction_start_date' => '2026-04-01',
            'ghp_amount' => 3600,
            'ghp_amount_is_manual' => false,
            'email' => 'juan@example.com',
        ], $overrides));

        $member->reimbursements()->create(['or_no' => 'OR-PRIOR', 'or_date' => '2026-05-05', 'or_amount' => 600]);
        app(BenefitAccrualService::class)->accrue($member->fresh());

        return $member;
    }

    private function file(Member $member, float $amount, string $date = '2026-06-10', string $orNo = 'OR-NEW')
    {
        return $this->actingAs($this->admin())->post(route('members.reimbursements.store', $member), [
            'or_no' => $orNo,
            'or_date' => $date,
            'or_amount' => $amount,
        ]);
    }

    private function claim(Member $member, string $orNo = 'OR-NEW'): Reimbursement
    {
        return Reimbursement::where('member_id', $member->id)->where('or_no', $orNo)->firstOrFail();
    }

    // ---- The manual feature is gone ----

    public function test_the_manual_record_excess_deduction_routes_no_longer_exist(): void
    {
        $this->assertFalse(Route::has('members.benefit-periods.excess-deductions.store'));
        $this->assertFalse(Route::has('members.benefit-periods.excess-deductions.destroy'));
    }

    public function test_the_record_excess_deduction_button_and_modal_are_not_rendered(): void
    {
        $member = $this->memberWith3000Available();
        $this->file($member, 5000);
        $period = $member->benefitPeriods()->firstOrFail();
        $admin = $this->admin();

        foreach ([
            route('members.show', $member),
            route('members.benefit-periods.reimbursements', [$member, $period]),
        ] as $url) {
            $this->actingAs($admin)->get($url)->assertOk()
                ->assertDontSee('Record excess deduction')
                ->assertDontSee('Record Excess Deduction')
                ->assertDontSee('excessDeductionModal');
        }
    }

    // ---- Test 1: within Available GHP -> no excess ----

    public function test_a_claim_within_the_available_ghp_records_no_excess_and_is_not_red(): void
    {
        $member = $this->memberWith3000Available();

        $this->file($member, 2000)->assertSessionHasNoErrors();

        $claim = $this->claim($member);
        $this->assertSame(2000.0, (float) $claim->or_amount);
        $this->assertSame(3000.0, (float) $claim->available_ghp);
        $this->assertSame(0.0, (float) $claim->excess_amount);
        $this->assertFalse($claim->hasExcess());

        $html = $this->actingAs($this->admin())->get(route('members.show', $member))->assertOk()->getContent();
        $this->assertSame(0, substr_count($html, 'data-excess="1"'));
    }

    public function test_a_claim_exactly_equal_to_the_available_ghp_has_no_excess(): void
    {
        $member = $this->memberWith3000Available();

        $this->file($member, 3000);

        $this->assertSame(0.0, (float) $this->claim($member)->excess_amount);
    }

    // ---- Test 2: above Available GHP -> excess recorded automatically, in red ----

    public function test_a_claim_above_the_available_ghp_is_filed_and_the_excess_is_saved_automatically(): void
    {
        $member = $this->memberWith3000Available();

        $this->file($member, 5000)->assertSessionHasNoErrors();

        // The full ₱5,000 is filed; the excess is ONLY the part above Available GHP.
        $this->assertDatabaseHas('reimbursements', [
            'member_id' => $member->id,
            'or_no' => 'OR-NEW',
            'or_amount' => 5000,
            'available_ghp' => 3000,
            'excess_amount' => 2000,
        ]);
        $this->assertTrue($this->claim($member)->hasExcess());
    }

    public function test_the_excess_is_computed_by_the_server_and_a_submitted_value_is_ignored(): void
    {
        $member = $this->memberWith3000Available();

        $this->actingAs($this->admin())->post(route('members.reimbursements.store', $member), [
            'or_no' => 'OR-NEW',
            'or_date' => '2026-06-10',
            'or_amount' => 5000,
            'excess_amount' => 1,
            'available_ghp' => 99999,
        ])->assertSessionHasNoErrors();

        $claim = $this->claim($member);
        $this->assertSame(2000.0, (float) $claim->excess_amount);
        $this->assertSame(3000.0, (float) $claim->available_ghp);
    }

    public function test_only_the_claim_with_an_excess_is_marked_red_on_the_member_and_records_pages(): void
    {
        $member = $this->memberWith3000Available();
        $this->file($member, 1000, '2026-06-10', 'OR-SMALL');   // within 3,000
        $this->file($member, 5000, '2026-07-10', 'OR-BIG');     // above what is left

        $period = $member->benefitPeriods()->firstOrFail();
        $admin = $this->admin();

        // Two active claims are marked? No: exactly the one that had an excess.
        $this->assertGreaterThan(0, (float) $this->claim($member, 'OR-BIG')->excess_amount);
        $this->assertSame(0.0, (float) $this->claim($member, 'OR-SMALL')->excess_amount);

        $member_html = $this->actingAs($admin)->get(route('members.show', $member))->assertOk()->getContent();
        $records_html = $this->actingAs($admin)->get(route('members.benefit-periods.reimbursements', [$member, $period]))->assertOk()->getContent();

        $this->assertSame(1, substr_count($member_html, 'data-excess="1"'));
        $this->assertSame(1, substr_count($records_html, 'data-excess="1"'));
        $this->assertStringContainsString('excess-badge', $records_html);
    }

    // ---- Test 3: no Available GHP -> filing is prevented ----

    public function test_a_member_with_no_available_ghp_cannot_file_and_nothing_is_recorded(): void
    {
        $member = Member::factory()->create([
            'member_type' => Member::MEMBER_TYPE_EMPLOYEE,
            'is_active' => true,
            'deduction_start_date' => '2027-04-01',   // first deduction after this cycle -> Available 0
            'ghp_amount' => 3600,
            'ghp_amount_is_manual' => false,
        ]);

        $this->file($member, 5000)->assertSessionHasErrors('or_amount');

        $this->assertSame(0, Reimbursement::where('member_id', $member->id)->count());
        $this->assertSame(0, ExcessDeduction::count());
    }

    // ---- Test 7: GHP usage / requirements are NOT affected by the excess ----

    public function test_recording_an_excess_does_not_change_the_original_ghp_requirements(): void
    {
        $member = $this->memberWith3000Available();
        $accrual = app(BenefitAccrualService::class);
        $before = $accrual->requiredAmountForCycle($member->fresh(['dependents']));

        $this->file($member, 5000);

        $member->refresh();
        $after = $accrual->requiredAmountForCycle($member->fresh(['dependents']));

        $this->assertSame(3600.0, (float) $member->ghp_amount);
        $this->assertFalse($member->ghp_amount_is_manual);
        $this->assertSame($before['ghp_amount'], $after['ghp_amount']);
        $this->assertSame(300.0, $after['monthly_rate']);
        $this->assertSame($before['required_amount'], $after['required_amount']);
        $this->assertSame($before['applicable_months'], $after['applicable_months']);
        $this->assertSame(0, $member->amountAdjustments()->count());
        $this->assertSame(0, ExcessDeduction::count());   // not a separate record, not a separate reimbursement
        $this->assertSame(2, Reimbursement::where('member_id', $member->id)->count());
    }

    public function test_the_excess_is_never_added_to_ghp_usage_or_subtracted_from_available_ghp(): void
    {
        $member = $this->memberWith3000Available();
        $accrual = app(BenefitAccrualService::class);

        $this->file($member, 5000);

        $period = $member->benefitPeriods()->firstOrFail();

        // Existing rule (unchanged): "used" is what the fund can cover, so 600 + 5,000
        // claimed -> used stops at the 3,600 fund and Available floors at 0.
        // The ₱2,000 excess is NOT added on top (it would be 5,600 if it were).
        $this->assertSame(3600.0, (float) $period->ghp_used);
        $this->assertSame(0.0, (float) $period->ghp_available);

        // Changing the stored excess by hand and re-accruing changes nothing:
        // the calculation never reads it.
        $before = $accrual->calculate($member->fresh());
        Reimbursement::where('member_id', $member->id)->update(['excess_amount' => 9999]);
        $after = $accrual->calculate($member->fresh());

        $this->assertSame($before['used'], $after['used']);
        $this->assertSame($before['available'], $after['available']);
        $this->assertSame($before['ghp_amount'], $after['ghp_amount']);
        $this->assertSame($before['accrued'], $after['accrued']);
    }

    public function test_a_claim_within_the_balance_uses_exactly_its_own_amount(): void
    {
        $member = $this->memberWith3000Available();

        $this->file($member, 2000);

        $period = $member->benefitPeriods()->firstOrFail();
        $this->assertSame(2600.0, (float) $period->ghp_used);        // 600 + 2,000, no excess involved
        $this->assertSame(1000.0, (float) $period->ghp_available);
    }

    // ---- Test 8: editing / status changes never duplicate or corrupt the excess ----

    public function test_editing_the_amount_recalculates_the_excess_on_the_same_record(): void
    {
        $member = $this->memberWith3000Available();
        $this->file($member, 5000);
        $claim = $this->claim($member);
        $admin = $this->admin();

        // Down to 4,000 -> excess 1,000 (measured against the same 3,000).
        $this->actingAs($admin)->put(route('members.reimbursements.update', [$member, $claim]), [
            'or_no' => 'OR-NEW', 'or_date' => '2026-06-10', 'or_amount' => 4000,
        ])->assertSessionHasNoErrors();

        $claim->refresh();
        $this->assertSame(4000.0, (float) $claim->or_amount);
        $this->assertSame(3000.0, (float) $claim->available_ghp);
        $this->assertSame(1000.0, (float) $claim->excess_amount);

        // Down to 2,500 -> now within Available GHP -> excess back to 0.
        $this->actingAs($admin)->put(route('members.reimbursements.update', [$member, $claim]), [
            'or_no' => 'OR-NEW', 'or_date' => '2026-06-10', 'or_amount' => 2500,
        ]);
        $this->assertSame(0.0, (float) $claim->fresh()->excess_amount);

        // Back up to 6,000 -> excess 3,000.
        $this->actingAs($admin)->put(route('members.reimbursements.update', [$member, $claim]), [
            'or_no' => 'OR-NEW', 'or_date' => '2026-06-10', 'or_amount' => 6000,
        ]);
        $this->assertSame(3000.0, (float) $claim->fresh()->excess_amount);

        // Still exactly the prior claim + this one; no extra reimbursement or excess row.
        $this->assertSame(2, Reimbursement::where('member_id', $member->id)->count());
        $this->assertSame(0, ExcessDeduction::count());
    }

    public function test_saving_an_edit_without_changing_the_amount_keeps_the_same_excess(): void
    {
        $member = $this->memberWith3000Available();
        $this->file($member, 5000);
        $claim = $this->claim($member);

        foreach (range(1, 3) as $ignored) {
            $this->actingAs($this->admin())->put(route('members.reimbursements.update', [$member, $claim]), [
                'or_no' => 'OR-NEW', 'or_date' => '2026-06-10', 'or_amount' => 5000, 'hospital_name' => 'City Hospital',
            ])->assertSessionHasNoErrors();
        }

        $claim->refresh();
        $this->assertSame(2000.0, (float) $claim->excess_amount);
        $this->assertSame(2, Reimbursement::where('member_id', $member->id)->count());
    }

    public function test_voiding_and_unvoiding_keep_the_stored_excess_and_never_duplicate_it(): void
    {
        $member = $this->memberWith3000Available();
        $this->file($member, 5000);
        $claim = $this->claim($member);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('members.reimbursements.void', [$member, $claim]), ['reason' => 'Filed in error']);
        $claim->refresh();

        // Stored figure untouched, but a voided claim is no longer flagged red or totalled.
        $this->assertSame(2000.0, (float) $claim->excess_amount);
        $this->assertFalse($claim->hasExcess());

        $this->actingAs($admin)->post(route('members.reimbursements.unvoid', [$member, $claim]));
        $claim->refresh();

        $this->assertSame(2000.0, (float) $claim->excess_amount);
        $this->assertTrue($claim->hasExcess());
        $this->assertSame(2, Reimbursement::where('member_id', $member->id)->count());
        $this->assertSame(0, ExcessDeduction::count());
    }

    // ---- Historical manually recorded excess deductions are preserved ----

    public function test_older_manually_recorded_excess_deductions_are_kept_and_shown_read_only(): void
    {
        $member = $this->memberWith3000Available();
        $period = $member->benefitPeriods()->firstOrFail();

        ExcessDeduction::create([
            'member_id' => $member->id,
            'benefit_period_id' => $period->id,
            'deduction_month' => '2026-05-01',
            'required_amount' => 300,
            'actual_deduction' => 500,
            'excess_amount' => 200,
        ]);

        $this->file($member, 2000);   // filing / recalculating must not touch the old record

        $this->assertSame(1, ExcessDeduction::count());
        $this->assertSame(200.0, (float) ExcessDeduction::first()->excess_amount);

        $this->actingAs($this->admin())
            ->get(route('members.benefit-periods.reimbursements', [$member, $period]))
            ->assertOk()
            ->assertSee('Previously recorded excess deductions');
    }

    // ---- Test 4: downloads ----

    public function test_the_csv_export_has_an_excess_deduction_column_with_the_actual_amounts(): void
    {
        $member = $this->memberWith3000Available();
        $this->file($member, 2000, '2026-06-10', 'OR-SMALL');
        $this->file($member, 5000, '2026-07-10', 'OR-BIG');

        $response = $this->actingAs($this->admin())->get(route('reports.reimbursements.csv', [
            'from' => '2026-04-01',
            'to' => '2027-03-31',
        ]))->assertOk();

        $csv = $response->streamedContent();

        $this->assertStringContainsString('Available GHP', $csv);
        $this->assertStringContainsString('Excess Deduction', $csv);

        $rows = array_map('str_getcsv', array_filter(explode("\n", trim($csv))));
        $header = array_shift($rows);
        $excessIndex = array_search('Excess Deduction', $header, true);
        $orNoIndex = array_search('OR No', $header, true);
        $availableIndex = array_search('Available GHP', $header, true);
        $this->assertNotFalse($excessIndex);

        $byOrNo = [];
        foreach ($rows as $row) {
            $byOrNo[$row[$orNoIndex]] = $row;
        }

        // Prior claim + small claim: 0.00. The big claim: the calculated excess, not its whole amount.
        $this->assertSame(0.0, (float) $byOrNo['OR-PRIOR'][$excessIndex]);
        $this->assertSame(0.0, (float) $byOrNo['OR-SMALL'][$excessIndex]);
        $this->assertGreaterThan(0.0, (float) $byOrNo['OR-BIG'][$excessIndex]);
        $this->assertLessThan(5000.0, (float) $byOrNo['OR-BIG'][$excessIndex]);
        // ...and it matches the database.
        $this->assertEqualsWithDelta((float) $this->claim($member, 'OR-BIG')->excess_amount, (float) $byOrNo['OR-BIG'][$excessIndex], 0.001);
        $this->assertEqualsWithDelta((float) $this->claim($member, 'OR-BIG')->available_ghp, (float) $byOrNo['OR-BIG'][$availableIndex], 0.001);
    }

    public function test_the_pdf_reimbursement_report_still_generates(): void
    {
        $member = $this->memberWith3000Available();
        $this->file($member, 5000);

        $this->actingAs($this->admin())->get(route('reports.reimbursements', [
            'from' => '2026-04-01',
            'to' => '2027-03-31',
        ]))->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    // ---- Test 5: print / receipt ----

    private function renderReceipt(Member $member, BenefitPeriod $period): string
    {
        $reimbursements = $period->reimbursements()->orderBy('or_date')->get();

        return view('reports.pdf.reimbursement-receipt', [
            'member' => $member,
            'benefitPeriod' => $period,
            'reimbursements' => $reimbursements,
            'total' => $reimbursements->where('is_voided', false)->sum('or_amount'),
            'voidedCount' => $reimbursements->where('is_voided', true)->count(),
            'excessTotal' => (float) $reimbursements->where('is_voided', false)->sum('excess_amount'),
            'excessDeductions' => collect(),
            'historicExcessTotal' => 0.0,
            'generatedAt' => now(),
            'generatedBy' => $this->admin(),
        ])->render();
    }

    public function test_the_receipt_shows_the_excess_column_and_marks_only_the_excess_row_red(): void
    {
        $member = $this->memberWith3000Available();
        $this->file($member, 2000, '2026-06-10', 'OR-SMALL');
        $this->file($member, 5000, '2026-07-10', 'OR-BIG');
        $period = $member->benefitPeriods()->firstOrFail();

        $html = $this->renderReceipt($member, $period);

        $this->assertStringContainsString('Excess Deduction', $html);
        $this->assertStringContainsString('Available GHP', $html);
        $this->assertStringContainsString('#C62828', $html);                       // fixed red hex, prints red
        $this->assertSame(1, substr_count($html, 'class=" excess"'));              // only the claim with an excess
        $this->assertStringContainsString('₱'.number_format((float) $this->claim($member, 'OR-BIG')->excess_amount, 2), str_replace('&#8369;', '₱', $html));
    }

    public function test_a_receipt_without_any_excess_has_no_red_rows_and_shows_zero(): void
    {
        $member = $this->memberWith3000Available();
        $this->file($member, 2000);
        $period = $member->benefitPeriods()->firstOrFail();

        $html = $this->renderReceipt($member, $period);

        $this->assertStringNotContainsString('class=" excess"', $html);
        $this->assertStringContainsString('Excess Deduction', $html);
        $this->assertStringContainsString('₱0.00', str_replace('&#8369;', '₱', $html));
    }

    public function test_the_receipt_pdf_endpoint_generates_with_an_excess(): void
    {
        $member = $this->memberWith3000Available();
        $this->file($member, 5000);
        $period = $member->benefitPeriods()->firstOrFail();
        $admin = $this->admin();

        $url = route('members.benefit-periods.reimbursements.pdf', [$member, $period]);

        $this->actingAs($admin)->get($url)->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->actingAs($admin)->get($url.'?download=1')->assertOk();
    }

    // ---- Test 6: email ----

    public function test_the_emailed_receipt_includes_the_excess_deduction_in_red(): void
    {
        Mail::fake();
        $member = $this->memberWith3000Available();
        $this->file($member, 5000);
        $period = $member->benefitPeriods()->firstOrFail();
        $expectedExcess = (float) $this->claim($member)->excess_amount;

        $this->actingAs($this->admin())
            ->post(route('members.benefit-periods.reimbursements.send', [$member, $period]))
            ->assertRedirect();

        $html = null;

        Mail::assertSent(ReimbursementReceiptMail::class, function (ReimbursementReceiptMail $mail) use ($expectedExcess, &$html) {
            $html = $mail->render();

            return $mail->hasTo('juan@example.com')
                && abs($mail->excessTotal - $expectedExcess) < 0.001
                && $mail->reimbursements->isNotEmpty()
                && count($mail->attachments()) === 1;
        });

        $this->assertStringContainsString('Excess Deduction', $html);
        $this->assertStringContainsString(number_format($expectedExcess, 2), $html);
        $this->assertStringContainsString('#C62828', $html);
    }

    public function test_the_emailed_receipt_shows_zero_and_no_red_when_there_is_no_excess(): void
    {
        Mail::fake();
        $member = $this->memberWith3000Available();
        $this->file($member, 2000);
        $period = $member->benefitPeriods()->firstOrFail();

        $this->actingAs($this->admin())->post(route('members.benefit-periods.reimbursements.send', [$member, $period]));

        $html = null;

        Mail::assertSent(ReimbursementReceiptMail::class, function (ReimbursementReceiptMail $mail) use (&$html) {
            $html = $mail->render();

            return abs($mail->excessTotal) < 0.001;
        });

        $this->assertStringContainsString('Excess Deduction', $html);
        $this->assertStringNotContainsString('color: #C62828', $html);
    }

    // ---- Editing measures against the RECORDED Available GHP ----

    public function test_editing_a_claim_keeps_its_recorded_available_ghp_even_after_later_claims(): void
    {
        $member = $this->memberWith3000Available();
        $admin = $this->admin();

        $this->file($member, 1000, '2026-06-10', 'OR-A');   // recorded Available GHP: 3,000
        $this->file($member, 1500, '2026-07-10', 'OR-B');   // recorded Available GHP: 2,000

        $this->assertSame(3000.0, (float) $this->claim($member, 'OR-A')->available_ghp);
        $this->assertSame(2000.0, (float) $this->claim($member, 'OR-B')->available_ghp);

        // Raise claim A to 4,000. Measured against the balance recorded when it
        // was filed (3,000), the excess is 1,000 — the later claim B must not
        // change what A was measured against, and the recorded figure stays.
        $this->actingAs($admin)->put(route('members.reimbursements.update', [$member, $this->claim($member, 'OR-A')]), [
            'or_no' => 'OR-A', 'or_date' => '2026-06-10', 'or_amount' => 4000,
        ])->assertSessionHasNoErrors();

        $a = $this->claim($member, 'OR-A');
        $this->assertSame(4000.0, (float) $a->or_amount);
        $this->assertSame(3000.0, (float) $a->available_ghp);
        $this->assertSame(1000.0, (float) $a->excess_amount);

        // The other claim is untouched.
        $b = $this->claim($member, 'OR-B');
        $this->assertSame(2000.0, (float) $b->available_ghp);
        $this->assertSame(0.0, (float) $b->excess_amount);
    }

    // ---- Downloads: the Member Data Record printout ----

    public function test_the_member_data_record_lists_available_ghp_and_excess_deduction_with_only_the_excess_row_red(): void
    {
        $member = $this->memberWith3000Available();
        $this->file($member, 1000, '2026-06-10', 'OR-SMALL');
        $this->file($member, 5000, '2026-07-10', 'OR-BIG');

        $member->load([
            'division', 'department', 'dependents',
            'benefitPeriods' => fn ($q) => $q->orderByDesc('from_date'),
            'reimbursements' => fn ($q) => $q->orderByDesc('or_date')->limit(10),
        ]);

        $html = view('reports.pdf.mdr', [
            'member' => $member,
            'currentBenefitPeriod' => $member->benefitPeriods->first(),
            'generatedAt' => now(),
        ])->render();

        $this->assertStringContainsString('Available GHP', $html);
        $this->assertStringContainsString('Excess Deduction', $html);
        $this->assertSame(1, substr_count($html, 'class="excess"'));

        $this->actingAs($this->admin())->get(route('members.mdr', $member))
            ->assertOk()->assertHeader('content-type', 'application/pdf');
    }
}
