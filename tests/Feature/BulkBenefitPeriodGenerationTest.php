<?php

namespace Tests\Feature;

use App\Models\BenefitPeriod;
use App\Models\Member;
use App\Models\User;
use App\Services\BenefitAccrualService;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bulk "Generate this year's benefit period" is ALL OR NOTHING (task.md §2.27):
 * if even one selected member already has the period for their current cycle
 * (or is not eligible), the whole batch is rejected and ZERO rows are created.
 *
 * NOT YET RUN — run `php artisan test --filter=BulkBenefitPeriodGenerationTest`.
 */
class BulkBenefitPeriodGenerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Inside the standard Employee cycle Apr 1, 2026 – Mar 31, 2027.
        Carbon::setTestNow('2026-06-15 10:00:00');
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
        return Member::factory()->create($overrides + [
            'member_type' => Member::MEMBER_TYPE_EMPLOYEE,
            'is_active' => true,
            'ghp_amount' => 3600,
            'ghp_amount_is_manual' => false,
            'start_date' => '2026-05-01',
            'deduction_start_date' => '2026-06-01',
        ]);
    }

    /** Gives $member this cycle's period the same way the Generate button does. */
    private function generated(Member $member): BenefitPeriod
    {
        return app(BenefitAccrualService::class)->accrue($member->fresh());
    }

    private function bulkGenerate(User $admin, array $ids)
    {
        return $this->actingAs($admin)->post(route('members.bulk-action'), [
            'member_ids' => $ids,
            'bulk_action' => 'generate_benefit_period',
        ]);
    }

    private function periodCount(Member ...$members): int
    {
        return BenefitPeriod::whereIn('member_id', collect($members)->pluck('id'))->count();
    }

    // ---- Test 1: nobody has a period -> everyone is generated ----

    public function test_all_members_without_a_period_are_generated(): void
    {
        [$a, $b, $c] = [$this->member(), $this->member(), $this->member()];

        $this->bulkGenerate($this->admin(), [$a->id, $b->id, $c->id])
            ->assertRedirect()
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'Successfully') && str_contains($s, '3 selected member'))
            ->assertSessionMissing('bulk_generation_report');

        foreach ([$a, $b, $c] as $member) {
            $period = $member->benefitPeriods()->firstOrFail();
            $this->assertSame('2026-04-01', $period->from_date->toDateString());
            $this->assertSame('2027-03-31', $period->to_date->toDateString());
        }
    }

    // ---- Test 2: one existing -> the WHOLE batch is rejected ----

    public function test_one_member_with_an_existing_period_stops_the_entire_batch(): void
    {
        [$a, $b, $c] = [$this->member(), $this->member(), $this->member()];
        $existing = $this->generated($b);
        $before = $existing->fresh()->only(['id', 'from_date', 'to_date', 'ghp_amount', 'ghp_used', 'ghp_available', 'updated_at']);

        $this->bulkGenerate($this->admin(), [$a->id, $b->id, $c->id])
            ->assertSessionHas('bulk_generation_report', fn ($r) => count($r['existing']) === 1
                && $r['existing'][0]['code'] === $b->code
                && $r['existing'][0]['coverage_year'] === '2026–2027'
                && $r['ineligible'] === [])
            ->assertSessionMissing('status');

        $this->assertSame(0, $a->benefitPeriods()->count());   // NOT generated
        $this->assertSame(0, $c->benefitPeriods()->count());   // NOT generated
        $this->assertSame(1, $b->benefitPeriods()->count());   // untouched, not refreshed or duplicated
        $this->assertEquals($before, $existing->fresh()->only(array_keys($before)));
    }

    // ---- Test 3: several existing -> all of them are listed ----

    public function test_every_member_that_already_has_a_period_is_listed(): void
    {
        [$a, $b, $c, $d] = [$this->member(), $this->member(), $this->member(), $this->member()];
        $this->generated($a);
        $this->generated($c);

        $this->bulkGenerate($this->admin(), [$a->id, $b->id, $c->id, $d->id])
            ->assertSessionHas('bulk_generation_report', fn ($r) => collect($r['existing'])->pluck('code')->sort()->values()->all()
                === collect([$a->code, $c->code])->sort()->values()->all());

        $this->assertSame(0, $b->benefitPeriods()->count());
        $this->assertSame(0, $d->benefitPeriods()->count());
        $this->assertSame(2, $this->periodCount($a, $b, $c, $d));   // only the two that already existed
    }

    // ---- Test 4: the same member submitted twice is processed once ----

    public function test_a_member_submitted_twice_is_only_generated_once(): void
    {
        $a = $this->member();

        $this->bulkGenerate($this->admin(), [$a->id, $a->id, $a->id])
            ->assertSessionHas('status', fn ($s) => str_contains($s, '1 selected member'));

        $this->assertSame(1, $a->benefitPeriods()->count());
    }

    // ---- Test 5: inactive / no deduction start date -> rejected, never silently skipped ----

    public function test_an_inactive_member_stops_the_batch_and_is_listed(): void
    {
        $a = $this->member();
        $inactive = $this->member(['is_active' => false]);

        $this->bulkGenerate($this->admin(), [$a->id, $inactive->id])
            ->assertSessionHas('bulk_generation_report', fn ($r) => count($r['ineligible']) === 1
                && $r['ineligible'][0]['code'] === $inactive->code
                && $r['existing'] === []);

        $this->assertSame(0, $this->periodCount($a, $inactive));
    }

    public function test_a_member_without_a_deduction_start_date_stops_the_batch(): void
    {
        $a = $this->member();
        $noStart = $this->member(['deduction_start_date' => null]);

        $this->bulkGenerate($this->admin(), [$a->id, $noStart->id])
            ->assertSessionHas('bulk_generation_report', fn ($r) => count($r['ineligible']) === 1);

        $this->assertSame(0, $this->periodCount($a, $noStart));
    }

    // ---- Test 6: a previous coverage year never blocks a new one ----

    public function test_a_period_from_a_previous_cycle_does_not_block_the_current_one(): void
    {
        $a = $this->member();
        $b = $this->member();

        Carbon::setTestNow('2025-06-15 10:00:00');          // last cycle: Apr 2025 – Mar 2026
        $this->generated($a);
        $this->assertSame('2025-04-01', $a->benefitPeriods()->firstOrFail()->from_date->toDateString());

        Carbon::setTestNow('2026-06-15 10:00:00');          // now: the new cycle

        $this->bulkGenerate($this->admin(), [$a->id, $b->id])
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'Successfully'));

        $this->assertSame(2, $a->benefitPeriods()->count());   // old + new
        $this->assertSame(1, $b->benefitPeriods()->count());
    }

    // ---- Configured coverage period is honored, not hardcoded Apr–Mar ----

    public function test_the_members_configured_coverage_cycle_decides_what_already_exists(): void
    {
        // Calendar-year cycle: Jan 1 – Dec 31, 2026.
        $custom = $this->member(['coverage_year' => 2026, 'coverage_end_date' => '2026-12-31']);
        $standard = $this->member();

        $this->bulkGenerate($this->admin(), [$custom->id, $standard->id])
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'Successfully'));

        $period = $custom->benefitPeriods()->firstOrFail();
        $this->assertSame('2026-01-01', $period->from_date->toDateString());
        $this->assertSame('2026-12-31', $period->to_date->toDateString());

        // Same batch again -> both are now "already generated", reported with their OWN cycle.
        $this->bulkGenerate($this->admin(), [$custom->id, $standard->id])
            ->assertSessionHas('bulk_generation_report', function ($r) use ($custom) {
                $byCode = collect($r['existing'])->keyBy('code');

                return $byCode->count() === 2
                    && $byCode[$custom->code]['coverage_year'] === '2026'
                    && str_contains($byCode[$custom->code]['period'], 'Jan 01, 2026');
            });

        $this->assertSame(1, $custom->benefitPeriods()->count());
        $this->assertSame(1, $standard->benefitPeriods()->count());
    }

    // ---- Test 7: a hand-built request can't bypass the rule ----

    public function test_a_direct_request_mixing_eligible_and_generated_members_is_rejected_whole(): void
    {
        [$eligible1, $eligible2, $generated] = [$this->member(), $this->member(), $this->member()];
        $this->generated($generated);

        // No pre-check call, no UI — straight at the endpoint.
        $this->actingAs($this->admin())->post(route('members.bulk-action'), [
            'bulk_action' => 'generate_benefit_period',
            'member_ids' => [(string) $eligible1->id, (string) $generated->id, (string) $eligible2->id],
        ])->assertSessionHas('bulk_generation_report');

        $this->assertSame(0, $this->periodCount($eligible1, $eligible2));
        $this->assertSame(1, $generated->benefitPeriods()->count());
    }

    public function test_invalid_member_ids_are_rejected_by_validation(): void
    {
        $a = $this->member();

        $this->actingAs($this->admin())->post(route('members.bulk-action'), [
            'bulk_action' => 'generate_benefit_period',
            'member_ids' => [$a->id, 999999],
        ])->assertSessionHasErrors('member_ids.1');

        $this->assertSame(0, $a->benefitPeriods()->count());
    }

    public function test_nothing_selected_is_rejected(): void
    {
        $this->actingAs($this->admin())->post(route('members.bulk-action'), [
            'bulk_action' => 'generate_benefit_period',
        ])->assertSessionHasErrors('member_ids');
    }

    public function test_a_non_admin_cannot_bulk_generate_or_use_the_pre_check(): void
    {
        $a = $this->member();
        $staff = User::factory()->create(['role' => 'user', 'is_active' => true]);

        $this->actingAs($staff)->post(route('members.bulk-action'), [
            'bulk_action' => 'generate_benefit_period', 'member_ids' => [$a->id],
        ])->assertForbidden();

        $this->actingAs($staff)->postJson(route('members.bulk-action.check-generate'), ['member_ids' => [$a->id]])
            ->assertForbidden();

        $this->assertSame(0, $a->benefitPeriods()->count());
    }

    // ---- Pre-check endpoint (what the button calls before any confirmation) ----

    public function test_the_pre_check_reports_blockers_and_writes_nothing(): void
    {
        [$a, $b] = [$this->member(), $this->member()];
        $this->generated($b);

        $this->actingAs($this->admin())->postJson(route('members.bulk-action.check-generate'), ['member_ids' => [$a->id, $b->id]])
            ->assertOk()
            ->assertJson(['ok' => false])
            ->assertJsonPath('existing.0.code', $b->code)
            ->assertJsonPath('existing.0.coverage_year', '2026–2027');

        $this->actingAs($this->admin())->postJson(route('members.bulk-action.check-generate'), ['member_ids' => [$a->id]])
            ->assertOk()
            ->assertJson(['ok' => true, 'existing' => [], 'ineligible' => []]);

        $this->assertSame(0, $a->benefitPeriods()->count());   // pre-check never generates
    }

    // ---- Test 9: an unexpected failure rolls the whole batch back ----

    public function test_a_failure_part_way_through_rolls_back_every_generated_period(): void
    {
        [$a, $b, $c] = [$this->member(), $this->member(), $this->member()];
        $calls = 0;

        $this->partialMock(BenefitAccrualService::class, function ($mock) use (&$calls) {
            $mock->shouldReceive('accrue')->andReturnUsing(function (Member $member) use (&$calls) {
                $calls++;

                if ($calls === 2) {
                    throw new \RuntimeException('simulated failure on the second member');
                }

                return (new BenefitAccrualService)->accrue($member);   // really inserts the first one
            });
        });

        $this->bulkGenerate($this->admin(), [$a->id, $b->id, $c->id])
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'Generation Not Proceeded') && str_contains($s, 'No benefit periods were generated'))
            ->assertSessionMissing('bulk_generation_report');

        $this->assertSame(2, $calls);                         // it did reach the failing member
        $this->assertSame(0, $this->periodCount($a, $b, $c)); // ...and the first insert was rolled back
    }

    // ---- Test 8 backstop: the database itself refuses a duplicate ----

    public function test_the_database_unique_index_refuses_a_duplicate_period(): void
    {
        $period = $this->generated($this->member());

        $this->expectException(UniqueConstraintViolationException::class);

        $period->replicate()->save();
    }

    // ---- Other bulk actions are unchanged ----

    public function test_bulk_activate_and_deactivate_still_work(): void
    {
        [$a, $b] = [$this->member(), $this->member()];

        $this->actingAs($this->admin())->post(route('members.bulk-action'), [
            'member_ids' => [$a->id, $b->id], 'bulk_action' => 'deactivate',
        ])->assertSessionHas('status', '2 member(s) deactivated.');

        $this->assertFalse($a->fresh()->is_active);

        $this->actingAs($this->admin())->post(route('members.bulk-action'), [
            'member_ids' => [$a->id, $b->id], 'bulk_action' => 'activate',
        ])->assertSessionHas('status', '2 member(s) activated.');

        $this->assertTrue($b->fresh()->is_active);
    }

    // ---- The single-member Generate button is untouched and agrees with the bulk rule ----

    public function test_the_single_button_and_the_bulk_action_agree_on_what_already_exists(): void
    {
        $a = $this->member();
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('members.generate-benefit-period', $a))
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'generated successfully'));

        // Bulk now sees it as existing...
        $this->bulkGenerate($admin, [$a->id])->assertSessionHas('bulk_generation_report');

        // ...and the single button's own duplicate rule still fires too.
        $this->actingAs($admin)->post(route('members.generate-benefit-period', $a))
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'already generated'));

        $this->assertSame(1, $a->benefitPeriods()->count());
    }
}
