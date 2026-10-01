<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureMemberIsActive;
use App\Models\BenefitPeriod;
use App\Models\Dependent;
use App\Models\Member;
use App\Models\Reimbursement;
use App\Models\User;
use App\Services\BenefitAccrualService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A DEACTIVATED member is read-only: every action that creates, edits,
 * removes, voids or processes their records is disabled in the UI and
 * rejected on the server (EnsureMemberIsActive, alias `member.active`), while
 * all existing records stay viewable and nothing about them changes.
 *
 * NOT YET RUN — written from reading the code.
 * Run: php artisan test --filter=InactiveMemberLockTest
 */
class InactiveMemberLockTest extends TestCase
{
    use RefreshDatabase;

    private const NOTICE = EnsureMemberIsActive::MESSAGE;

    protected function setUp(): void
    {
        parent::setUp();

        // Inside the member's Apr 2026 – Mar 2027 cycle, all 12 months elapsed.
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

    /**
     * A member with a dependent, an active and a voided reimbursement and a
     * generated benefit period — optionally deactivated afterwards.
     *
     * @return array{0: Member, 1: Dependent, 2: Reimbursement, 3: Reimbursement, 4: BenefitPeriod}
     */
    private function fixture(bool $active = false, array $overrides = []): array
    {
        $member = Member::factory()->create(array_merge([
            'member_type' => Member::MEMBER_TYPE_EMPLOYEE,
            'is_active' => true,
            'deduction_start_date' => '2026-04-01',
            'ghp_amount' => 3600,
            'ghp_amount_is_manual' => false,
            'email' => 'juan@example.com',
        ], $overrides));

        $dependent = $member->dependents()->create(['name' => 'Maria Cruz', 'relation' => 'Spouse', 'birthdate' => '1990-01-01']);
        $claim = $member->reimbursements()->create(['or_no' => 'OR-ACTIVE', 'or_date' => '2026-05-10', 'or_amount' => 500]);
        $voided = $member->reimbursements()->create([
            'or_no' => 'OR-VOIDED', 'or_date' => '2026-06-10', 'or_amount' => 100,
            'is_voided' => true, 'voided_reason' => 'filed in error',
        ]);
        $period = app(BenefitAccrualService::class)->accrue($member->fresh());

        if (! $active) {
            $member->update(['is_active' => false, 'resignation_date' => '2027-01-31']);
        }

        return [$member->fresh(), $dependent, $claim, $voided, $period];
    }

    /** Everything about the member's records that deactivation / blocked requests must leave alone. */
    private function snapshot(Member $member): array
    {
        $member = $member->fresh();

        return [
            'ghp_amount' => (float) $member->ghp_amount,
            'ghp_amount_is_manual' => $member->ghp_amount_is_manual,
            'last_name' => $member->last_name,
            'deduction_start_date' => optional($member->deduction_start_date)->toDateString(),
            'dependents' => $member->dependents()->orderBy('id')->get(['id', 'name', 'relation', 'birthdate', 'eligibility_date', 'immediate_eligibility_at'])->toArray(),
            'reimbursements' => $member->reimbursements()->withTrashed()->orderBy('id')->get(['id', 'or_no', 'or_date', 'or_amount', 'is_voided', 'excess_amount', 'available_ghp', 'benefit_period_id'])->toArray(),
            'periods' => $member->benefitPeriods()->orderBy('id')->get(['id', 'from_date', 'to_date', 'ghp_amount', 'ghp_used', 'ghp_available'])->toArray(),
            'adjustments' => $member->amountAdjustments()->count(),
        ];
    }

    // ---- Scenario 3: direct requests to every protected endpoint are rejected ----

    public function test_every_modifying_endpoint_rejects_a_deactivated_member_and_changes_nothing(): void
    {
        [$member, $dependent, $claim, $voided, $period] = $this->fixture();
        $admin = $this->admin();
        $before = $this->snapshot($member);

        $requests = [
            ['put', route('members.update', $member), ['last_name' => 'Changed']],
            ['post', route('members.generate-benefit-period', $member), []],
            ['post', route('members.reimbursements.store', $member), ['or_date' => '2026-08-10', 'or_amount' => 100]],
            ['put', route('members.reimbursements.update', [$member, $claim]), ['or_date' => '2026-05-10', 'or_amount' => 999]],
            ['post', route('members.reimbursements.void', [$member, $claim]), ['reason' => 'should not work']],
            ['post', route('members.reimbursements.unvoid', [$member, $voided]), []],
            ['post', route('members.dependents.store', $member), ['name' => 'New Kid', 'relation' => 'Son', 'birthdate' => '2015-01-01']],
            ['put', route('members.dependents.update', [$member, $dependent]), ['name' => 'Renamed', 'relation' => 'Spouse', 'birthdate' => '1990-01-01']],
            ['delete', route('members.dependents.destroy', [$member, $dependent]), []],
            ['post', route('members.dependents.immediate-eligibility', [$member, $dependent]), []],
            ['post', route('members.amount-adjustments.store', $member), ['new_amount' => 5000, 'reason' => 'should not work']],
            ['post', route('members.amount-adjustments.revert-to-automatic', $member), []],
        ];

        foreach ($requests as [$method, $url, $payload]) {
            $this->actingAs($admin)->{$method}($url, $payload)
                ->assertRedirect(route('members.show', $member))
                ->assertSessionHas('status', self::NOTICE);
        }

        // Data-quality correction of one of this member's periods.
        $this->actingAs($admin)->put(route('benefit-periods.update', $period), [
            'from_date' => '2026-04-01', 'to_date' => '2027-03-31', 'ghp_amount' => 1, 'ghp_used' => 1, 'ghp_available' => 1,
        ])->assertRedirect(route('data-quality.index'))->assertSessionHas('status', self::NOTICE);

        $this->assertEquals($before, $this->snapshot($member));
    }

    public function test_a_blocked_request_answers_json_clients_with_403_and_the_same_message(): void
    {
        [$member] = $this->fixture();

        $this->actingAs($this->admin())
            ->postJson(route('members.reimbursements.store', $member), ['or_date' => '2026-08-10', 'or_amount' => 100])
            ->assertForbidden()
            ->assertJson(['message' => self::NOTICE]);
    }

    public function test_deactivation_cannot_be_bypassed_by_filing_for_a_member_with_ample_balance(): void
    {
        // Same call that succeeds for an active member (see below) — only the status differs.
        [$member] = $this->fixture();

        $this->actingAs($this->admin())->post(route('members.reimbursements.store', $member), [
            'or_date' => '2026-08-10', 'or_amount' => 100,
        ]);

        $this->assertSame(2, Reimbursement::where('member_id', $member->id)->count());   // the 2 from the fixture, no more
    }

    // ---- Scenario 1 & 8: active members are unaffected ----

    public function test_an_active_member_can_still_be_acted_on(): void
    {
        [$member] = $this->fixture(active: true);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('members.dependents.store', $member), [
            'name' => 'New Kid', 'relation' => 'Son', 'birthdate' => '2015-01-01',
        ])->assertSessionHasNoErrors();

        $this->actingAs($admin)->post(route('members.reimbursements.store', $member), [
            'or_no' => 'OR-NEW', 'or_date' => '2026-08-10', 'or_amount' => 100,
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, $member->dependents()->count());
        $this->assertSame(1, Reimbursement::where('member_id', $member->id)->where('or_no', 'OR-NEW')->count());
    }

    public function test_deactivating_one_member_does_not_restrict_another(): void
    {
        [$inactive] = $this->fixture();
        [$active] = $this->fixture(active: true, overrides: ['email' => 'other@example.com']);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('members.reimbursements.store', $inactive), ['or_date' => '2026-08-10', 'or_amount' => 100])
            ->assertSessionHas('status', self::NOTICE);

        $this->actingAs($admin)->post(route('members.reimbursements.store', $active), ['or_no' => 'OR-OK', 'or_date' => '2026-08-10', 'or_amount' => 100])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Reimbursement::where('member_id', $active->id)->where('or_no', 'OR-OK')->count());
        $this->assertSame(0, Reimbursement::where('member_id', $inactive->id)->where('or_no', 'OR-OK')->count());

        // ...and the active member's page is fully interactive.
        $this->actingAs($admin)->get(route('members.show', $active))->assertOk()
            ->assertDontSee('data-member-locked', false)
            ->assertSee('id="fileReimbursementModal"', false);
    }

    // ---- Scenario 2 & 4: the UI is disabled and no modal can open ----

    public function test_the_deactivated_members_page_shows_the_notice_and_disables_every_action(): void
    {
        [$member] = $this->fixture();

        $html = $this->actingAs($this->admin())->get(route('members.show', $member))->assertOk()
            ->assertSee(self::NOTICE)
            ->assertSee('data-member-locked', false)
            ->getContent();

        // The buttons that would open a modal / submit an action are not wired up at all...
        foreach ([
            'onclick="editMemberModal.showModal()"',
            'onclick="adjustAmountModal.showModal()"',
            'onclick="openAddDependent()"',
            'onclick="openFileReimbursement()"',
            'onclick="generateBenefitPeriodModal.showModal()"',
        ] as $handler) {
            $this->assertStringNotContainsString($handler, $html, "Found live handler {$handler}");
        }

        // ...the per-row buttons are disabled...
        $this->assertMatchesRegularExpression('/<button[^>]*\bdisabled\b[^>]*onclick="openEditDependent\(/', $html);
        $this->assertMatchesRegularExpression('/<button[^>]*\bdisabled\b[^>]*onclick="openEditReimbursement\(/', $html);
        $this->assertMatchesRegularExpression('/<button[^>]*\bdisabled\b[^>]*onclick="openVoidReimbursement\(/', $html);
        // (This member has an active claim, so Remove renders its already-blocked variant.)
        $this->assertStringContainsString('data-dependent-remove="blocked"', $html);
        $this->assertStringContainsString('title="'.self::NOTICE.'"', $html);

        // ...and none of the modals exist in the page, so nothing can be opened by script or keyboard.
        foreach (['fileReimbursementModal', 'voidReimbursementModal', 'dependentModal', 'immediateEligibilityModal', 'adjustAmountModal', 'editMemberModal', 'generateBenefitPeriodModal'] as $modal) {
            $this->assertStringNotContainsString('id="'.$modal.'"', $html, "Modal {$modal} should not be rendered");
        }
    }

    public function test_the_remove_dependent_button_is_disabled_even_when_removal_would_otherwise_be_allowed(): void
    {
        [$member, , $claim] = $this->fixture();
        $claim->void('so removal is not blocked by an active claim');   // model call, not the guarded route

        $html = $this->actingAs($this->admin())->get(route('members.show', $member))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<button[^>]*data-dependent-remove="allowed"[^>]*\bdisabled\b/', $html);
    }

    public function test_the_unvoid_and_revert_buttons_are_disabled_too(): void
    {
        [$member] = $this->fixture(overrides: ['ghp_amount' => 4200, 'ghp_amount_is_manual' => true]);

        $html = $this->actingAs($this->admin())->get(route('members.show', $member))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<button[^>]*\bdisabled\b[^>]*>Unvoid<\/button>/', $html);
        $this->assertMatchesRegularExpression('/<button[^>]*\bdisabled\b[^>]*>Revert to automatic<\/button>/', $html);
    }

    public function test_the_actions_are_available_on_an_active_members_page(): void
    {
        [$member] = $this->fixture(active: true);

        $html = $this->actingAs($this->admin())->get(route('members.show', $member))->assertOk()
            ->assertDontSee(self::NOTICE)
            ->getContent();

        $this->assertStringContainsString('onclick="openFileReimbursement()"', $html);
        $this->assertStringContainsString('onclick="openAddDependent()"', $html);
        $this->assertStringContainsString('onclick="editMemberModal.showModal()"', $html);
        $this->assertStringContainsString('id="fileReimbursementModal"', $html);
        $this->assertStringContainsString('id="editMemberModal"', $html);
    }

    // ---- Scenario 5: historical records stay viewable ----

    public function test_existing_records_remain_viewable_printable_and_downloadable(): void
    {
        [$member, , , , $period] = $this->fixture();
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('members.show', $member))->assertOk()
            ->assertSee('Maria Cruz')       // dependent
            ->assertSee('OR-ACTIVE')        // reimbursement history
            ->assertSee('OR-VOIDED');

        $this->actingAs($admin)->get(route('members.benefit-periods.reimbursements', [$member, $period]))->assertOk()
            ->assertSee('OR-ACTIVE');

        $this->actingAs($admin)->get(route('members.benefit-periods.reimbursements.pdf', [$member, $period]))
            ->assertOk()->assertHeader('content-type', 'application/pdf');

        $this->actingAs($admin)->get(route('members.mdr', $member))
            ->assertOk()->assertHeader('content-type', 'application/pdf');

        $this->actingAs($admin)->get(route('reports.reimbursements.csv', ['from' => '2026-04-01', 'to' => '2027-03-31']))
            ->assertOk();

        $this->actingAs($admin)->get(route('members.index', ['status' => 'inactive']))->assertOk()
            ->assertSee($member->code);
    }

    // ---- Scenario 6: reactivation ----

    public function test_reactivating_restores_the_actions_without_processing_anything_that_was_blocked(): void
    {
        [$member] = $this->fixture();
        $admin = $this->admin();

        // Blocked while deactivated.
        $this->actingAs($admin)->post(route('members.reimbursements.store', $member), ['or_no' => 'OR-LATE', 'or_date' => '2026-08-10', 'or_amount' => 100]);
        $this->assertSame(0, Reimbursement::where('or_no', 'OR-LATE')->count());

        // Reactivate (the status route is deliberately NOT behind the lock).
        $this->actingAs($admin)->patch(route('members.update-status', $member))
            ->assertSessionHasNoErrors();

        $member->refresh();
        $this->assertTrue($member->is_active);
        $this->assertNull($member->resignation_date);

        // Reactivation does NOT replay the blocked request.
        $this->assertSame(0, Reimbursement::where('or_no', 'OR-LATE')->count());

        // ...but the same request works now.
        $this->actingAs($admin)->post(route('members.reimbursements.store', $member), ['or_no' => 'OR-LATE', 'or_date' => '2026-08-10', 'or_amount' => 100])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, Reimbursement::where('or_no', 'OR-LATE')->count());

        $this->actingAs($admin)->get(route('members.show', $member))->assertOk()
            ->assertDontSee('data-member-locked', false)
            ->assertSee('id="fileReimbursementModal"', false);
    }

    public function test_an_admin_sees_a_reactivate_button_on_the_deactivated_members_page(): void
    {
        [$member] = $this->fixture();

        $this->actingAs($this->admin())->get(route('members.show', $member))->assertOk()
            ->assertSee('Reactivate member')
            ->assertSee(route('members.update-status', $member), false);
    }

    public function test_the_bulk_activate_route_still_reactivates(): void
    {
        [$member] = $this->fixture();

        $this->actingAs($this->admin())->post(route('members.bulk-action'), [
            'member_ids' => [$member->id],
            'bulk_action' => 'activate',
        ]);

        $this->assertTrue($member->fresh()->is_active);
    }

    // ---- Scenario 7: deactivation itself does not alter records ----

    public function test_deactivating_a_member_does_not_change_benefits_balances_dependents_or_reimbursements(): void
    {
        [$member] = $this->fixture(active: true);
        $before = $this->snapshot($member);

        $this->actingAs($this->admin())->patch(route('members.update-status', $member), ['resignation_date' => '2027-01-31'])
            ->assertSessionHasNoErrors();

        $this->assertFalse($member->fresh()->is_active);
        $this->assertEquals($before, $this->snapshot($member));
    }

    // ---- Other entry points ----

    public function test_the_bulk_generate_action_still_skips_deactivated_members(): void
    {
        $member = Member::factory()->create([
            'member_type' => Member::MEMBER_TYPE_EMPLOYEE,
            'is_active' => false,
            'deduction_start_date' => '2026-04-01',
        ]);

        $this->actingAs($this->admin())->post(route('members.bulk-action'), [
            'member_ids' => [$member->id],
            'bulk_action' => 'generate_benefit_period',
        ]);

        $this->assertSame(0, $member->benefitPeriods()->count());
    }

    public function test_the_data_quality_correct_button_is_disabled_for_a_deactivated_members_period(): void
    {
        [$member, , , , $period] = $this->fixture();
        // A mis-dated period, so the Data Quality report lists it.
        $period->update(['from_date' => '2026-05-03']);

        $html = $this->actingAs($this->admin())->get(route('data-quality.index'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<button[^>]*\bdisabled\b[^>]*onclick="openCorrectPeriod\(/', $html);
    }

    public function test_a_non_admin_still_cannot_use_these_endpoints_at_all(): void
    {
        [$member] = $this->fixture(active: true);
        $staff = User::factory()->create(['role' => 'user', 'is_active' => true]);

        $this->actingAs($staff)->post(route('members.reimbursements.store', $member), ['or_date' => '2026-08-10', 'or_amount' => 100])
            ->assertForbidden();
    }
}
