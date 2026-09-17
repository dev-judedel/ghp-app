<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    // No special setUp() needed for email validation — StoreUserRequest and
    // UpdateUserRequest skip the DNS/MX lookup in the testing environment
    // (see their emailRule() methods), so these tests don't depend on real
    // network access and can use plain fake domains freely.

    public function test_creating_a_user_auto_generates_a_unique_alsc_code(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('users.store'), [
            'name' => 'Juan Dela Cruz',
            'email' => 'juan@example.test',
            'password' => 'password123',
            'role' => 'user',
        ])->assertRedirect(route('users.index'));

        $user = User::where('email', 'juan@example.test')->firstOrFail();

        $this->assertMatchesRegularExpression('/^ALSC-\d{6}$/', $user->user_code);
    }

    public function test_two_created_users_get_different_user_codes(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('users.store'), [
            'name' => 'User One', 'email' => 'one@example.test', 'password' => 'password123', 'role' => 'user',
        ]);
        $this->actingAs($admin)->post(route('users.store'), [
            'name' => 'User Two', 'email' => 'two@example.test', 'password' => 'password123', 'role' => 'user',
        ]);

        $codes = User::whereIn('email', ['one@example.test', 'two@example.test'])->pluck('user_code');

        $this->assertNotEquals($codes[0], $codes[1]);
    }

    public function test_the_administrator_cannot_manually_set_a_user_code(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('users.store'), [
            'name' => 'Juan Dela Cruz',
            'email' => 'juan2@example.test',
            'password' => 'password123',
            'role' => 'user',
            'user_code' => 'ALSC-000001', // not a validated field — must be ignored
        ]);

        $user = User::where('email', 'juan2@example.test')->firstOrFail();

        $this->assertNotSame('ALSC-000001', $user->user_code);
    }

    public function test_duplicate_email_is_rejected_when_creating_a_user(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        User::factory()->create(['email' => 'taken@example.test']);

        $this->actingAs($admin)->post(route('users.store'), [
            'name' => 'Someone', 'email' => 'taken@example.test', 'password' => 'password123', 'role' => 'user',
        ])->assertSessionHasErrors('email');
    }

    public function test_new_users_default_to_active(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('users.store'), [
            'name' => 'Juan Dela Cruz', 'email' => 'juan3@example.test', 'password' => 'password123', 'role' => 'user',
        ]);

        $this->assertTrue(User::where('email', 'juan3@example.test')->firstOrFail()->is_active);
    }

    public function test_an_admin_can_deactivate_an_active_user(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create(['role' => 'user', 'is_active' => true]);

        $this->actingAs($admin)
            ->patch(route('users.update-status', $target))
            ->assertRedirect(route('users.index'));

        $this->assertFalse($target->fresh()->is_active);
    }

    public function test_an_admin_can_reactivate_an_inactive_user(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create(['role' => 'user', 'is_active' => false]);

        $this->actingAs($admin)->patch(route('users.update-status', $target));

        $this->assertTrue($target->fresh()->is_active);
    }

    public function test_an_admin_cannot_deactivate_their_own_account(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $this->actingAs($admin)->patch(route('users.update-status', $admin));

        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_deactivating_another_admin_is_allowed_when_more_than_one_active_admin_exists(): void
    {
        $adminA = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $adminB = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $this->actingAs($adminA)->patch(route('users.update-status', $adminB));

        $this->assertFalse($adminB->fresh()->is_active);
    }

    /**
     * The system can never end up with zero active admins: the self-
     * deactivation guard (tested above) means an admin can only ever
     * deactivate *someone else*, and whoever is making that request is
     * necessarily still an active admin themselves (the 'active' middleware
     * would have already signed them out otherwise). So "at least one
     * active admin remains" holds as an invariant through this route alone.
     * wouldRemoveLastActiveAdmin() exists as defense-in-depth for that same
     * invariant, matching the existing wouldRemoveLastAdmin() pattern used
     * for role changes/deletion elsewhere in this controller.
     */
    public function test_an_admin_can_never_deactivate_the_only_other_active_admin_out_of_existence(): void
    {
        $adminA = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $adminB = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $this->actingAs($adminA)->patch(route('users.update-status', $adminB));

        $this->assertSame(1, User::where('role', 'admin')->where('is_active', true)->count());
    }

    public function test_an_inactive_account_cannot_log_in(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('password123'),
            'is_active' => false,
        ]);

        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'password123',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_an_active_account_can_log_in(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('password123'),
            'is_active' => true,
        ]);

        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'password123',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_logged_in_user_is_signed_out_the_moment_their_account_is_deactivated(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $this->actingAs($user)->get(route('dashboard'))->assertOk();

        $user->update(['is_active' => false]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }
}
