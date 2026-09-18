<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MemberDeactivationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'is_active' => true]);
    }

    public function test_deactivating_without_a_date_is_rejected_and_the_member_stays_active(): void
    {
        $member = Member::factory()->create(['is_active' => true]);

        $this->actingAs($this->admin())
            ->patch(route('members.update-status', $member))
            ->assertSessionHasErrors('resignation_date');

        $member->refresh();
        $this->assertTrue($member->is_active);
        $this->assertNull($member->resignation_date);
    }

    public function test_deactivating_with_a_valid_date_saves_it_and_marks_the_member_inactive(): void
    {
        $member = Member::factory()->create(['is_active' => true]);

        $this->actingAs($this->admin())
            ->patch(route('members.update-status', $member), ['resignation_date' => '2026-09-18'])
            ->assertRedirect();

        $member->refresh();
        $this->assertFalse($member->is_active);
        $this->assertSame('2026-09-18', $member->resignation_date->toDateString());
    }

    public function test_an_invalid_date_is_rejected(): void
    {
        $member = Member::factory()->create(['is_active' => true]);

        $this->actingAs($this->admin())
            ->patch(route('members.update-status', $member), ['resignation_date' => 'not-a-real-date'])
            ->assertSessionHasErrors('resignation_date');

        $this->assertTrue($member->fresh()->is_active);
    }

    public function test_reactivating_does_not_require_a_date_and_clears_any_existing_one(): void
    {
        $member = Member::factory()->create([
            'is_active' => false,
            'resignation_date' => '2026-01-15',
        ]);

        $this->actingAs($this->admin())
            ->patch(route('members.update-status', $member)) // no resignation_date sent at all
            ->assertRedirect();

        $member->refresh();
        $this->assertTrue($member->is_active);
        $this->assertNull($member->resignation_date);
    }

    public function test_the_resignation_date_does_not_appear_for_an_active_member(): void
    {
        $user = $this->admin();
        $member = Member::factory()->create(['is_active' => true, 'resignation_date' => null]);

        $this->actingAs($user)
            ->get(route('members.show', $member))
            ->assertOk()
            ->assertDontSee('Resignation date');
    }

    public function test_the_resignation_date_appears_for_an_inactive_member(): void
    {
        $user = $this->admin();
        $member = Member::factory()->create(['is_active' => false, 'resignation_date' => '2026-09-18']);

        $this->actingAs($user)
            ->get(route('members.show', $member))
            ->assertOk()
            ->assertSee('Resignation date')
            ->assertSee('September 18, 2026');
    }

    public function test_a_non_admin_cannot_deactivate_a_member(): void
    {
        $staff = User::factory()->create(['role' => 'user', 'is_active' => true]);
        $member = Member::factory()->create(['is_active' => true]);

        $this->actingAs($staff)
            ->patch(route('members.update-status', $member), ['resignation_date' => '2026-09-18'])
            ->assertForbidden();

        $this->assertTrue($member->fresh()->is_active);
    }

    public function test_deactivating_creates_an_activity_log_entry(): void
    {
        $admin = $this->admin();
        $member = Member::factory()->create(['is_active' => true]);

        // Not asserting 0 here: LogsActivity records a "created" entry the
        // moment the factory makes the member, so there's already at least
        // one. What matters is that deactivating adds a *new* one.
        $countBefore = $member->activities()->count();

        $this->actingAs($admin)->patch(route('members.update-status', $member), ['resignation_date' => '2026-09-18']);

        $this->assertGreaterThan($countBefore, $member->activities()->count());
    }
}
