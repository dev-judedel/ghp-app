<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers AmountAdjustmentController: manual GHP-amount overrides, the
 * "sticks until reverted" behavior via ghp_amount_is_manual, and reverting
 * back to the automatic (dependent-driven) amount. Untested before this
 * file — flagged as a risk in task.md given it directly changes the GHP
 * fund amount a member is owed.
 */
class AmountAdjustmentManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'is_active' => true]);
    }

    private function nonAdmin(): User
    {
        return User::factory()->create(['role' => 'staff', 'is_active' => true]);
    }

    // ---- store (manual override) ----

    public function test_an_admin_can_set_a_manual_ghp_amount(): void
    {
        $member = Member::factory()->create([
            'ghp_amount' => 3600,
            'ghp_amount_is_manual' => false,
        ]);

        $this->actingAs($this->admin())->post(route('members.amount-adjustments.store', $member), [
            'new_amount' => 5000,
            'reason' => 'Special board approval',
        ])->assertRedirect(route('members.show', $member));

        $member->refresh();
        $this->assertSame(5000.0, (float) $member->ghp_amount);
        $this->assertTrue($member->ghp_amount_is_manual);
    }

    public function test_a_non_admin_cannot_set_a_manual_ghp_amount(): void
    {
        $member = Member::factory()->create(['ghp_amount' => 3600]);

        $this->actingAs($this->nonAdmin())->post(route('members.amount-adjustments.store', $member), [
            'new_amount' => 5000,
            'reason' => 'Special board approval',
        ])->assertForbidden();

        $this->assertSame(3600.0, (float) $member->fresh()->ghp_amount);
    }

    public function test_new_amount_and_reason_are_required(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($this->admin())->post(route('members.amount-adjustments.store', $member), [])
            ->assertSessionHasErrors(['new_amount', 'reason']);
    }

    public function test_setting_a_manual_amount_records_an_adjustment_history_row(): void
    {
        $member = Member::factory()->create(['ghp_amount' => 3600]);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('members.amount-adjustments.store', $member), [
            'new_amount' => 5000,
            'reason' => 'Special board approval',
            'request_reference' => 'REQ-99',
        ]);

        $this->assertDatabaseHas('benefit_amount_adjustments', [
            'member_id' => $member->id,
            'old_amount' => 3600,
            'new_amount' => 5000,
            'reason' => 'Special board approval',
            'request_reference' => 'REQ-99',
            'recorded_by' => $admin->name,
        ]);
    }

    public function test_a_manual_override_refreshes_the_current_benefit_period(): void
    {
        $member = Member::factory()->create([
            'is_active' => true,
            'deduction_start_date' => '2026-04-01',
            'ghp_amount' => 3600,
            'ghp_amount_is_manual' => false,
        ]);

        $this->actingAs($this->admin())->post(route('members.amount-adjustments.store', $member), [
            'new_amount' => 4800,
            'reason' => 'Special board approval',
        ]);

        $period = $member->benefitPeriods()->first();
        $this->assertNotNull($period);
        $this->assertSame(4800.0, (float) $period->ghp_amount);
    }

    // ---- revertToAutomatic ----

    public function test_an_admin_can_revert_a_manual_amount_to_automatic(): void
    {
        $member = Member::factory()->create([
            'ghp_amount' => 5000,
            'ghp_amount_is_manual' => true,
        ]);

        $this->actingAs($this->admin())->post(route('members.amount-adjustments.revert-to-automatic', $member))
            ->assertRedirect(route('members.show', $member));

        $member->refresh();
        $this->assertFalse($member->ghp_amount_is_manual);
        $this->assertSame(3600.0, (float) $member->ghp_amount); // no dependents -> base rate
    }

    public function test_reverting_to_automatic_uses_4200_when_an_eligible_dependent_exists(): void
    {
        $member = Member::factory()->create([
            'ghp_amount' => 5000,
            'ghp_amount_is_manual' => true,
        ]);
        $member->dependents()->create(['name' => 'Maria', 'relation' => 'Spouse']);

        $this->actingAs($this->admin())->post(route('members.amount-adjustments.revert-to-automatic', $member));

        $this->assertSame(4200.0, (float) $member->fresh()->ghp_amount);
    }

    public function test_reverting_an_already_automatic_member_is_a_no_op(): void
    {
        $member = Member::factory()->create([
            'ghp_amount' => 3600,
            'ghp_amount_is_manual' => false,
        ]);

        $this->actingAs($this->admin())->post(route('members.amount-adjustments.revert-to-automatic', $member))
            ->assertRedirect(route('members.show', $member))
            ->assertSessionHas('status', 'This member is already on the automatic GHP amount.');

        $this->assertSame(3600.0, (float) $member->fresh()->ghp_amount);
    }

    public function test_a_non_admin_cannot_revert_to_automatic(): void
    {
        $member = Member::factory()->create([
            'ghp_amount' => 5000,
            'ghp_amount_is_manual' => true,
        ]);

        $this->actingAs($this->nonAdmin())->post(route('members.amount-adjustments.revert-to-automatic', $member))
            ->assertForbidden();

        $this->assertTrue($member->fresh()->ghp_amount_is_manual);
    }

    public function test_reverting_to_automatic_records_an_adjustment_history_row(): void
    {
        $member = Member::factory()->create([
            'ghp_amount' => 5000,
            'ghp_amount_is_manual' => true,
        ]);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('members.amount-adjustments.revert-to-automatic', $member));

        $this->assertDatabaseHas('benefit_amount_adjustments', [
            'member_id' => $member->id,
            'old_amount' => 5000,
            'new_amount' => 3600,
            'reason' => 'Reverted to automatic (based on current dependents)',
            'recorded_by' => $admin->name,
        ]);
    }
}
