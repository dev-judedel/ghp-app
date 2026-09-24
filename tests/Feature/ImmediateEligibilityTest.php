<?php

namespace Tests\Feature;

use App\Models\BenefitPeriod;
use App\Models\Dependent;
use App\Models\Member;
use App\Models\User;
use App\Services\BenefitAccrualService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Immediate Eligibility (administrator-controlled exception to the normal
 * "wait for the next Benefit Period" dependent rule) and the Edit Member
 * modal's spouse-dependent flow.
 *
 * Time is frozen to mid-June 2026 (Employee cycle Apr 2026 – Mar 2027) so a
 * dependent added "now" is deterministically pending until 2027-04-01.
 *
 * NOT YET RUN — written from reading the controllers/models/requests.
 * Run `php artisan migrate` (new migration 2026_09_24_120000) then
 * `php artisan test --filter=ImmediateEligibilityTest`.
 */
class ImmediateEligibilityTest extends TestCase
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
            'civil_status' => Member::CIVIL_STATUS_SINGLE,
            'ghp_amount' => 3600,
            'ghp_amount_is_manual' => false,
            'start_date' => '2026-05-01',
            'deduction_start_date' => '2026-06-01',
        ]);
    }

    /** A dependent added through the real flow, i.e. pending until 2027-04-01. */
    private function pendingSpouse(Member $member, User $admin): Dependent
    {
        $this->actingAs($admin)->post(route('members.dependents.store', $member), [
            'name' => 'Jane Doe',
            'relation' => 'Spouse',
            'birthdate' => '1995-01-10',
        ]);

        return $member->dependents()->firstOrFail();
    }

    private function editPayload(Member $member, array $overrides = []): array
    {
        return array_merge([
            'code' => $member->code,
            'email' => $member->email,
            'member_type' => (string) $member->member_type,
            'last_name' => $member->last_name,
            'first_name' => $member->first_name,
            'apply_date' => '2026-04-01',
            'start_date' => '2026-05-01',
        ], $overrides);
    }

    // ---- Button visibility ----

    public function test_the_button_shows_only_for_a_dependent_that_is_still_pending(): void
    {
        $admin = $this->admin();
        $member = $this->member();
        $pending = $this->pendingSpouse($member, $admin);

        $this->actingAs($admin)->get(route('members.show', $member))
            ->assertOk()
            ->assertSee("openImmediateEligibility({$pending->id},", false);

        // Already eligible under the normal rule (no eligibility_date) -> no button.
        $eligible = $member->dependents()->create(['name' => 'Old Dependent', 'relation' => 'Spouse']);

        $this->actingAs($admin)->get(route('members.show', $member))
            ->assertDontSee("openImmediateEligibility({$eligible->id},", false);
    }

    public function test_the_button_disappears_after_immediate_eligibility_is_granted(): void
    {
        $admin = $this->admin();
        $member = $this->member();
        $spouse = $this->pendingSpouse($member, $admin);

        $this->actingAs($admin)->post(route('members.dependents.immediate-eligibility', [$member, $spouse]));

        $this->actingAs($admin)->get(route('members.show', $member))
            ->assertOk()
            ->assertDontSee("openImmediateEligibility({$spouse->id},", false)
            ->assertSee('Immediate Eligible');
    }

    // ---- Granting ----

    public function test_granting_makes_the_dependent_immediately_eligible_and_records_who_and_when(): void
    {
        $admin = $this->admin();
        $member = $this->member();
        $spouse = $this->pendingSpouse($member, $admin);

        $this->assertSame('pending', $spouse->eligibility_status);
        $this->assertSame('2027-04-01', $spouse->eligibility_date->toDateString());

        $this->actingAs($admin)
            ->post(route('members.dependents.immediate-eligibility', [$member, $spouse]))
            ->assertRedirect(route('members.show', $member));

        $spouse->refresh();

        $this->assertSame('immediate', $spouse->eligibility_status);
        $this->assertSame('2026-06-15', $spouse->eligibility_date->toDateString());
        $this->assertNotNull($spouse->immediate_eligibility_at);
        $this->assertSame($admin->id, $spouse->immediate_eligibility_by);
    }

    public function test_granting_is_written_to_the_activity_log_once(): void
    {
        $admin = $this->admin();
        $member = $this->member();
        $spouse = $this->pendingSpouse($member, $admin);

        $this->actingAs($admin)->post(route('members.dependents.immediate-eligibility', [$member, $spouse]));

        $entries = Activity::where('log_name', 'dependent')
            ->where('subject_type', Dependent::class)
            ->where('subject_id', $spouse->id)
            ->where('description', 'like', 'Immediate Eligibility granted%')
            ->get();

        $this->assertCount(1, $entries);
        $this->assertSame($admin->id, $entries->first()->causer_id);
        $this->assertSame('Immediate Eligibility', $entries->first()->getExtraProperty('action'));
        $this->assertSame($spouse->id, $entries->first()->getExtraProperty('dependent_id'));
        $this->assertSame($member->id, $entries->first()->getExtraProperty('member_id'));
        $this->assertSame('2026-04-01 to 2027-03-31', $entries->first()->getExtraProperty('benefit_period'));
    }

    public function test_the_existing_ghp_calculation_recognises_the_dependent_immediately(): void
    {
        $admin = $this->admin();
        $member = $this->member();
        $spouse = $this->pendingSpouse($member, $admin);

        $this->assertSame(3600.0, (float) $member->fresh()->ghp_amount);

        $this->actingAs($admin)->post(route('members.dependents.immediate-eligibility', [$member, $spouse]));

        $this->assertSame(4200.0, (float) $member->fresh()->ghp_amount);
        $this->assertSame(
            4200.0,
            app(BenefitAccrualService::class)->resolveGhpAmount($member->fresh(['dependents']))
        );
    }

    public function test_a_manual_ghp_override_is_left_alone(): void
    {
        $admin = $this->admin();
        $member = $this->member(['ghp_amount' => 5000, 'ghp_amount_is_manual' => true]);
        $spouse = $this->pendingSpouse($member, $admin);

        $this->actingAs($admin)->post(route('members.dependents.immediate-eligibility', [$member, $spouse]));

        $this->assertSame(5000.0, (float) $member->fresh()->ghp_amount);
    }

    public function test_the_normal_rule_for_other_dependents_is_unchanged(): void
    {
        $admin = $this->admin();
        $member = $this->member();
        $rush = $this->pendingSpouse($member, $admin);

        $this->actingAs($admin)->post(route('members.dependents.store', $member), [
            'name' => 'Kid One', 'relation' => 'Son', 'birthdate' => '2018-05-20',
        ]);
        $normal = $member->dependents()->where('name', 'Kid One')->firstOrFail();

        $this->actingAs($admin)->post(route('members.dependents.immediate-eligibility', [$member, $rush]));

        $this->assertSame('pending', $normal->fresh()->eligibility_status);
        $this->assertSame('2027-04-01', $normal->fresh()->eligibility_date->toDateString());
    }

    // ---- Benefit period must not change ----

    public function test_granting_does_not_touch_the_benefit_period_or_reopen_generation(): void
    {
        $admin = $this->admin();
        $member = $this->member();
        $member->load('dependents');
        $period = app(BenefitAccrualService::class)->accrue($member);
        $before = $period->fresh()->only(['from_date', 'to_date', 'ghp_amount', 'ghp_available', 'ghp_used', 'updated_at']);

        $spouse = $this->pendingSpouse($member, $admin);
        $this->actingAs($admin)->post(route('members.dependents.immediate-eligibility', [$member, $spouse]));

        $this->assertSame(1, BenefitPeriod::where('member_id', $member->id)->count());
        $this->assertEquals($before, BenefitPeriod::findOrFail($period->id)->only(['from_date', 'to_date', 'ghp_amount', 'ghp_available', 'ghp_used', 'updated_at']));

        // One period per cycle still holds: Generate is still rejected.
        $this->actingAs($admin)->post(route('members.generate-benefit-period', $member));
        $this->assertSame(1, BenefitPeriod::where('member_id', $member->id)->count());
    }

    // ---- Backend validation ----

    public function test_an_already_eligible_dependent_is_rejected_and_unchanged(): void
    {
        $admin = $this->admin();
        $member = $this->member();
        // No eligibility_date => not time-gated => already eligible.
        $dependent = $member->dependents()->create(['name' => 'Old Dependent', 'relation' => 'Spouse']);

        $this->actingAs($admin)
            ->post(route('members.dependents.immediate-eligibility', [$member, $dependent]))
            ->assertRedirect(route('members.show', $member));

        $this->assertNull($dependent->fresh()->immediate_eligibility_at);
        $this->assertSame(0, Activity::where('description', 'like', 'Immediate Eligibility granted%')->count());
    }

    public function test_a_dependent_cannot_be_granted_twice(): void
    {
        $admin = $this->admin();
        $member = $this->member();
        $spouse = $this->pendingSpouse($member, $admin);

        $this->actingAs($admin)->post(route('members.dependents.immediate-eligibility', [$member, $spouse]));
        $this->actingAs($admin)->post(route('members.dependents.immediate-eligibility', [$member, $spouse]));

        $this->assertSame(1, Activity::where('description', 'like', 'Immediate Eligibility granted%')->count());
    }

    public function test_a_child_who_fails_the_age_rule_cannot_be_made_eligible(): void
    {
        $admin = $this->admin();
        $member = $this->member();

        $this->actingAs($admin)->post(route('members.dependents.store', $member), [
            'name' => 'Grown Son', 'relation' => 'Son', 'birthdate' => '1990-01-01',
        ]);
        $son = $member->dependents()->firstOrFail();

        $this->assertSame('not_eligible', $son->eligibility_status);

        $this->actingAs($admin)->post(route('members.dependents.immediate-eligibility', [$member, $son]));

        $this->assertNull($son->fresh()->immediate_eligibility_at);
        $this->assertSame('2027-04-01', $son->fresh()->eligibility_date->toDateString());
    }

    public function test_a_dependent_belonging_to_another_member_returns_404(): void
    {
        $admin = $this->admin();
        $member = $this->member();
        $other = $this->member();
        $spouse = $this->pendingSpouse($other, $admin);

        $this->actingAs($admin)
            ->post(route('members.dependents.immediate-eligibility', [$member, $spouse]))
            ->assertNotFound();

        $this->assertNull($spouse->fresh()->immediate_eligibility_at);
    }

    public function test_a_non_admin_cannot_grant_immediate_eligibility(): void
    {
        $admin = $this->admin();
        $member = $this->member();
        $spouse = $this->pendingSpouse($member, $admin);
        $staff = User::factory()->create(['role' => 'user', 'is_active' => true]);

        $this->actingAs($staff)
            ->post(route('members.dependents.immediate-eligibility', [$member, $spouse]))
            ->assertForbidden();

        $this->assertNull($spouse->fresh()->immediate_eligibility_at);
    }

    // ---- Edit Member: spouse dependent ----

    public function test_changing_to_married_requires_the_spouse_name_and_birthday(): void
    {
        $member = $this->member();

        $this->actingAs($this->admin())
            ->put(route('members.update', $member), $this->editPayload($member, ['civil_status' => '1']))
            ->assertSessionHasErrors(['spouse_name' => 'Spouse name is required.', 'spouse_birthdate' => 'Spouse birthday is required.']);

        $this->assertSame(Member::CIVIL_STATUS_SINGLE, $member->fresh()->civil_status);
        $this->assertSame(0, $member->dependents()->count());
    }

    public function test_changing_to_married_creates_a_normal_pending_spouse_dependent(): void
    {
        $member = $this->member();

        $this->actingAs($this->admin())->put(route('members.update', $member), $this->editPayload($member, [
            'civil_status' => '1',
            'spouse_name' => 'Jane Doe',
            'spouse_birthdate' => '1995-01-10',
        ]))->assertSessionHasNoErrors();

        $this->assertSame(Member::CIVIL_STATUS_MARRIED, $member->fresh()->civil_status);

        $spouse = $member->dependents()->firstOrFail();
        $this->assertSame('Jane Doe', $spouse->name);
        $this->assertSame('Spouse', $spouse->relation);
        $this->assertSame('pending', $spouse->eligibility_status);
        $this->assertSame('2027-04-01', $spouse->eligibility_date->toDateString());
        $this->assertNull($spouse->immediate_eligibility_at);
        // Married alone must not raise the GHP amount.
        $this->assertSame(3600.0, (float) $member->fresh()->ghp_amount);
    }

    public function test_the_admin_may_explicitly_pick_immediate_eligibility_for_the_spouse(): void
    {
        $member = $this->member();

        $this->actingAs($this->admin())->put(route('members.update', $member), $this->editPayload($member, [
            'civil_status' => '1',
            'spouse_name' => 'Jane Doe',
            'spouse_birthdate' => '1995-01-10',
            'spouse_eligibility' => 'immediate',
        ]))->assertSessionHasNoErrors();

        $this->assertSame('immediate', $member->dependents()->firstOrFail()->eligibility_status);
        $this->assertSame(4200.0, (float) $member->fresh()->ghp_amount);
    }

    public function test_an_existing_spouse_is_not_duplicated(): void
    {
        $admin = $this->admin();
        $member = $this->member();
        $this->pendingSpouse($member, $admin);

        $response = $this->actingAs($admin)->put(route('members.update', $member), $this->editPayload($member, [
            'civil_status' => '1',
            'spouse_name' => 'Somebody Else',
            'spouse_birthdate' => '1990-02-02',
        ]));

        $response->assertSessionHasNoErrors();
        $this->assertSame(1, $member->dependents()->count());
        $this->assertStringContainsString(
            'This member already has a spouse dependent. Please review the existing dependent record.',
            session('status')
        );
    }

    public function test_changing_away_from_married_keeps_the_spouse_dependent(): void
    {
        $admin = $this->admin();
        $member = $this->member(['civil_status' => Member::CIVIL_STATUS_MARRIED]);
        $this->pendingSpouse($member, $admin);

        $this->actingAs($admin)->put(route('members.update', $member), $this->editPayload($member, ['civil_status' => '0']))
            ->assertSessionHasNoErrors();

        $this->assertSame(Member::CIVIL_STATUS_SINGLE, $member->fresh()->civil_status);
        $this->assertSame(1, $member->dependents()->count());
    }

    public function test_spouse_fields_are_ignored_when_not_married(): void
    {
        $member = $this->member();

        $this->actingAs($this->admin())->put(route('members.update', $member), $this->editPayload($member, [
            'civil_status' => '0',
            'spouse_name' => 'Should Not Be Saved',
            'spouse_birthdate' => '1995-01-10',
        ]))->assertSessionHasNoErrors();

        $this->assertSame(0, $member->dependents()->count());
    }

    public function test_an_edit_that_does_not_send_civil_status_still_works(): void
    {
        $member = $this->member();

        $this->actingAs($this->admin())
            ->put(route('members.update', $member), $this->editPayload($member))
            ->assertSessionHasNoErrors();

        $this->assertSame("Member {$member->code} updated.", session('status'));
    }
}
