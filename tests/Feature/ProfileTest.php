<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('profile.edit'))->assertRedirect(route('login'));
    }

    public function test_a_staff_non_admin_user_can_view_their_own_profile(): void
    {
        $user = User::factory()->create(['role' => 'user']);

        $this->actingAs($user)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertSee($user->name)
            ->assertSee($user->email);
    }

    public function test_a_staff_user_cannot_reach_the_admin_only_users_screen(): void
    {
        $user = User::factory()->create(['role' => 'user']);

        $this->actingAs($user)
            ->get(route('users.index'))
            ->assertForbidden();
    }

    public function test_user_can_update_their_name_and_email(): void
    {
        $user = User::factory()->create(['name' => 'Old Name', 'email' => 'old@example.test']);

        $this->actingAs($user)
            ->put(route('profile.update'), [
                'name' => 'New Name',
                'email' => 'new@example.test',
            ])
            ->assertRedirect(route('profile.edit'));

        $this->assertSame('New Name', $user->fresh()->name);
        $this->assertSame('new@example.test', $user->fresh()->email);
    }

    public function test_user_cannot_take_an_email_already_used_by_someone_else(): void
    {
        User::factory()->create(['email' => 'taken@example.test']);
        $user = User::factory()->create(['email' => 'me@example.test']);

        $this->actingAs($user)
            ->put(route('profile.update'), [
                'name' => $user->name,
                'email' => 'taken@example.test',
            ])
            ->assertSessionHasErrors('email');

        $this->assertSame('me@example.test', $user->fresh()->email);
    }

    public function test_role_cannot_be_changed_from_the_profile_form(): void
    {
        $user = User::factory()->create(['role' => 'user']);

        $this->actingAs($user)->put(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'role' => 'admin', // not a validated/fillable field on this form
        ]);

        $this->assertSame('user', $user->fresh()->role);
    }

    public function test_user_can_change_their_password_with_the_correct_current_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('old-password')]);

        $this->actingAs($user)
            ->put(route('profile.update-password'), [
                'current_password' => 'old-password',
                'password' => 'new-password-123',
                'password_confirmation' => 'new-password-123',
            ])
            ->assertRedirect(route('profile.edit'));

        $this->assertTrue(Hash::check('new-password-123', $user->fresh()->password));
    }

    public function test_password_change_is_rejected_with_the_wrong_current_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('old-password')]);

        $this->actingAs($user)
            ->put(route('profile.update-password'), [
                'current_password' => 'wrong-password',
                'password' => 'new-password-123',
                'password_confirmation' => 'new-password-123',
            ])
            ->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check('old-password', $user->fresh()->password));
    }

    public function test_password_confirmation_must_match(): void
    {
        $user = User::factory()->create(['password' => Hash::make('old-password')]);

        $this->actingAs($user)
            ->put(route('profile.update-password'), [
                'current_password' => 'old-password',
                'password' => 'new-password-123',
                'password_confirmation' => 'does-not-match',
            ])
            ->assertSessionHasErrors('password');
    }
}
