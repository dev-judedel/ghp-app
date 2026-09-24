<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers DataQualityController: access control and the three live-DB
 * detectors it reports on (corrupted benefit-period dates, active members
 * with a ₱0 GHP amount, active members missing a benefit period entirely).
 * The fourth section (legacy orphan counts) is intentionally not covered
 * here — it degrades to null whenever the legacy staging connection isn't
 * configured (see tryLegacyOrphanCounts()), which is always true in the
 * test environment, and asserting on that null path would just be
 * asserting the absence of a legacy DB rather than testing report logic.
 * No coverage existed for this controller before this file — flagged as a
 * risk in task.md given it surfaces financial-data integrity issues.
 */
class DataQualityReportTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'is_active' => true]);
    }

    public function test_an_admin_can_view_the_data_quality_report(): void
    {
        $this->actingAs($this->admin())->get(route('data-quality.index'))->assertOk();
    }

    public function test_a_non_admin_cannot_view_the_data_quality_report(): void
    {
        $staff = User::factory()->create(['role' => 'staff', 'is_active' => true]);

        $this->actingAs($staff)->get(route('data-quality.index'))->assertForbidden();
    }

    public function test_a_guest_is_redirected_to_login(): void
    {
        $this->get(route('data-quality.index'))->assertRedirect(route('login'));
    }

    // ---- Corrupted benefit-period dates ----

    public function test_a_benefit_period_with_a_wrong_start_month_is_flagged(): void
    {
        $member = Member::factory()->create(['member_type' => Member::MEMBER_TYPE_EMPLOYEE]);
        // Employees should start Apr 1 — this one starts May 1, wrong month.
        $member->benefitPeriods()->create([
            'from_date' => '2026-05-01',
            'to_date' => '2027-04-30',
            'member_type' => Member::MEMBER_TYPE_EMPLOYEE,
        ]);

        $response = $this->actingAs($this->admin())->get(route('data-quality.index'));

        $response->assertOk();
        $response->assertViewHas('badDatePeriods', function ($periods) use ($member) {
            return $periods->contains(fn ($p) => $p->member_id === $member->id);
        });
    }

    public function test_a_benefit_period_with_an_implausible_year_is_flagged(): void
    {
        $member = Member::factory()->create(['member_type' => Member::MEMBER_TYPE_EMPLOYEE]);
        $member->benefitPeriods()->create([
            'from_date' => '1943-04-01', // before the 2005 floor
            'to_date' => '1944-03-31',
            'member_type' => Member::MEMBER_TYPE_EMPLOYEE,
        ]);

        $response = $this->actingAs($this->admin())->get(route('data-quality.index'));

        $response->assertViewHas('badDatePeriods', function ($periods) use ($member) {
            return $periods->contains(fn ($p) => $p->member_id === $member->id);
        });
    }

    public function test_a_correctly_dated_benefit_period_is_not_flagged(): void
    {
        $member = Member::factory()->create(['member_type' => Member::MEMBER_TYPE_EMPLOYEE]);
        $member->benefitPeriods()->create([
            'from_date' => '2026-04-01', // correct: Apr 1 for an Employee
            'to_date' => '2027-03-31',
            'member_type' => Member::MEMBER_TYPE_EMPLOYEE,
        ]);

        $response = $this->actingAs($this->admin())->get(route('data-quality.index'));

        $response->assertViewHas('badDatePeriods', function ($periods) use ($member) {
            return ! $periods->contains(fn ($p) => $p->member_id === $member->id);
        });
    }

    public function test_the_expected_start_month_differs_for_agents(): void
    {
        $member = Member::factory()->create(['member_type' => Member::MEMBER_TYPE_AGENT]);
        $member->benefitPeriods()->create([
            'from_date' => '2026-06-01', // correct: Jun 1 for an Agent
            'to_date' => '2027-05-31',
            'member_type' => Member::MEMBER_TYPE_AGENT,
        ]);

        $response = $this->actingAs($this->admin())->get(route('data-quality.index'));

        $response->assertViewHas('badDatePeriods', function ($periods) use ($member) {
            return ! $periods->contains(fn ($p) => $p->member_id === $member->id);
        });
    }

    // ---- Zero-amount active members ----

    public function test_an_active_member_with_a_zero_ghp_amount_is_flagged(): void
    {
        $member = Member::factory()->create(['is_active' => true, 'ghp_amount' => 0]);

        $response = $this->actingAs($this->admin())->get(route('data-quality.index'));

        $response->assertViewHas('zeroAmountActive', function ($members) use ($member) {
            return $members->contains(fn ($m) => $m->id === $member->id);
        });
    }

    public function test_an_active_member_with_a_nonzero_amount_is_not_flagged(): void
    {
        $member = Member::factory()->create(['is_active' => true, 'ghp_amount' => 3600]);

        $response = $this->actingAs($this->admin())->get(route('data-quality.index'));

        $response->assertViewHas('zeroAmountActive', function ($members) use ($member) {
            return ! $members->contains(fn ($m) => $m->id === $member->id);
        });
    }

    public function test_an_inactive_member_with_a_zero_amount_is_not_flagged(): void
    {
        $member = Member::factory()->create(['is_active' => false, 'ghp_amount' => 0]);

        $response = $this->actingAs($this->admin())->get(route('data-quality.index'));

        $response->assertViewHas('zeroAmountActive', function ($members) use ($member) {
            return ! $members->contains(fn ($m) => $m->id === $member->id);
        });
    }

    // ---- Active members missing a benefit period ----

    public function test_an_active_member_with_a_deduction_date_and_no_benefit_period_is_flagged(): void
    {
        $member = Member::factory()->create([
            'is_active' => true,
            'deduction_start_date' => '2026-04-01',
        ]);

        $response = $this->actingAs($this->admin())->get(route('data-quality.index'));

        $response->assertViewHas('missingPeriods', function ($members) use ($member) {
            return $members->contains(fn ($m) => $m->id === $member->id);
        });
    }

    public function test_an_active_member_with_a_benefit_period_already_on_record_is_not_flagged(): void
    {
        $member = Member::factory()->create([
            'is_active' => true,
            'deduction_start_date' => '2026-04-01',
        ]);
        $member->benefitPeriods()->create([
            'from_date' => '2026-04-01',
            'to_date' => '2027-03-31',
            'member_type' => $member->member_type,
        ]);

        $response = $this->actingAs($this->admin())->get(route('data-quality.index'));

        $response->assertViewHas('missingPeriods', function ($members) use ($member) {
            return ! $members->contains(fn ($m) => $m->id === $member->id);
        });
    }

    public function test_an_active_member_with_no_deduction_start_date_is_not_flagged_as_missing(): void
    {
        $member = Member::factory()->create([
            'is_active' => true,
            'deduction_start_date' => null,
        ]);

        $response = $this->actingAs($this->admin())->get(route('data-quality.index'));

        $response->assertViewHas('missingPeriods', function ($members) use ($member) {
            return ! $members->contains(fn ($m) => $m->id === $member->id);
        });
    }
}
