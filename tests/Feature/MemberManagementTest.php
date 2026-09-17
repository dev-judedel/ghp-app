<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MemberManagementTest extends TestCase
{
    use RefreshDatabase;

    // No special setUp() needed for email validation — StoreMemberRequest/
    // UpdateMemberRequest skip the DNS/MX lookup in the testing environment
    // (see HasEmailRule), so these tests don't depend on real network access.

    private function validMemberPayload(array $overrides = []): array
    {
        return array_merge([
            'email' => 'juan@example.test',
            'member_type' => '0',
            'last_name' => 'Dela Cruz',
            'first_name' => 'Juan',
            'ghp_amount' => 3600,
        ], $overrides);
    }

    public function test_creating_a_member_auto_generates_an_alsc_code(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $this->actingAs($admin)->post(route('members.store'), $this->validMemberPayload());

        $member = Member::where('email', 'juan@example.test')->firstOrFail();

        $this->assertMatchesRegularExpression('/^ALSC-\d{6}$/', $member->code);
    }

    public function test_leaving_the_code_field_blank_auto_generates_one(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $this->actingAs($admin)->post(route('members.store'), $this->validMemberPayload([
            'email' => 'juan2@example.test',
            // no 'code' key at all — matches leaving the field blank in the form
        ]));

        $member = Member::where('email', 'juan2@example.test')->firstOrFail();

        $this->assertMatchesRegularExpression('/^ALSC-\d{6}$/', $member->code);
    }

    public function test_the_admin_can_type_their_own_member_code(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $this->actingAs($admin)->post(route('members.store'), $this->validMemberPayload([
            'code' => 'CUSTOM-001',
            'email' => 'juan3@example.test',
        ]));

        $member = Member::where('email', 'juan3@example.test')->firstOrFail();

        $this->assertSame('CUSTOM-001', $member->code);
    }

    public function test_a_manually_entered_duplicate_code_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        Member::factory()->create(['code' => 'DUPLICATE-1']);

        $this->actingAs($admin)
            ->post(route('members.store'), $this->validMemberPayload([
                'code' => 'DUPLICATE-1',
                'email' => 'juan4@example.test',
            ]))
            ->assertSessionHasErrors('code');

        $this->assertDatabaseMissing('members', ['email' => 'juan4@example.test']);
    }

    public function test_email_is_required_when_creating_a_member(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $payload = $this->validMemberPayload();
        unset($payload['email']);

        $this->actingAs($admin)
            ->post(route('members.store'), $payload)
            ->assertSessionHasErrors('email');
    }

    public function test_duplicate_member_email_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        Member::factory()->create(['email' => 'taken@example.test']);

        $this->actingAs($admin)
            ->post(route('members.store'), $this->validMemberPayload(['email' => 'taken@example.test']))
            ->assertSessionHasErrors('email');
    }

    public function test_an_admin_can_deactivate_an_active_member(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $member = Member::factory()->create(['is_active' => true]);

        $this->actingAs($admin)
            ->patch(route('members.update-status', $member))
            ->assertRedirect();

        $this->assertFalse($member->fresh()->is_active);
    }

    public function test_an_admin_can_reactivate_an_inactive_member(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $member = Member::factory()->create(['is_active' => false]);

        $this->actingAs($admin)->patch(route('members.update-status', $member));

        $this->assertTrue($member->fresh()->is_active);
    }

    public function test_bulk_deactivate_deactivates_all_selected_members(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $memberA = Member::factory()->create(['is_active' => true]);
        $memberB = Member::factory()->create(['is_active' => true]);

        $this->actingAs($admin)->post(route('members.bulk-action'), [
            'member_ids' => [$memberA->id, $memberB->id],
            'bulk_action' => 'deactivate',
        ])->assertRedirect();

        $this->assertFalse($memberA->fresh()->is_active);
        $this->assertFalse($memberB->fresh()->is_active);
    }

    public function test_bulk_activate_activates_all_selected_members(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $memberA = Member::factory()->create(['is_active' => false]);
        $memberB = Member::factory()->create(['is_active' => false]);

        $this->actingAs($admin)->post(route('members.bulk-action'), [
            'member_ids' => [$memberA->id, $memberB->id],
            'bulk_action' => 'activate',
        ])->assertRedirect();

        $this->assertTrue($memberA->fresh()->is_active);
        $this->assertTrue($memberB->fresh()->is_active);
    }

    public function test_individual_row_action_only_affects_the_one_member(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $target = Member::factory()->create(['is_active' => true]);
        $other = Member::factory()->create(['is_active' => true]);

        $this->actingAs($admin)->patch(route('members.update-status', $target));

        $this->assertFalse($target->fresh()->is_active);
        $this->assertTrue($other->fresh()->is_active); // untouched by the individual action
    }

    public function test_bulk_generate_benefit_period_still_works(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $member = Member::factory()->create([
            'is_active' => true,
            'deduction_start_date' => now()->subYear(),
        ]);

        $this->actingAs($admin)->post(route('members.bulk-action'), [
            'member_ids' => [$member->id],
            'bulk_action' => 'generate_benefit_period',
        ])->assertRedirect();

        $this->assertTrue($member->benefitPeriods()->exists());
    }
}
