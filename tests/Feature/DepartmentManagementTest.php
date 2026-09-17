<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Division;
use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DepartmentManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_can_add_a_department(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $division = Division::factory()->create();

        $this->actingAs($admin)->post(route('departments.store'), [
            'name' => 'Finance',
            'division_id' => $division->id,
        ])->assertRedirect(route('users.index'));

        $department = Department::where('name', 'Finance')->firstOrFail();
        $this->assertSame($division->id, $department->division_id);
    }

    public function test_a_department_can_be_added_without_a_division(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $this->actingAs($admin)->post(route('departments.store'), [
            'name' => 'Unassigned Dept',
        ])->assertRedirect(route('users.index'));

        $department = Department::where('name', 'Unassigned Dept')->firstOrFail();
        $this->assertNull($department->division_id);
    }

    public function test_duplicate_department_name_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        Department::factory()->create(['name' => 'Engineering']);

        $this->actingAs($admin)
            ->post(route('departments.store'), ['name' => 'Engineering'])
            ->assertSessionHasErrors('name');
    }

    public function test_a_non_admin_cannot_add_a_department(): void
    {
        $staff = User::factory()->create(['role' => 'user', 'is_active' => true]);

        $this->actingAs($staff)
            ->post(route('departments.store'), ['name' => 'Should Not Work'])
            ->assertForbidden();

        $this->assertDatabaseMissing('departments', ['name' => 'Should Not Work']);
    }

    public function test_an_admin_can_edit_a_department(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $department = Department::factory()->create(['name' => 'Old Name']);
        $division = Division::factory()->create();

        $this->actingAs($admin)->put(route('departments.update', $department), [
            'name' => 'New Name',
            'division_id' => $division->id,
        ])->assertRedirect(route('users.index'));

        $department->refresh();
        $this->assertSame('New Name', $department->name);
        $this->assertSame($division->id, $department->division_id);
    }

    public function test_deleting_a_department_unassigns_its_members_instead_of_deleting_them(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $department = Department::factory()->create();
        $member = Member::factory()->create(['department_id' => $department->id]);

        $this->actingAs($admin)
            ->delete(route('departments.destroy', $department))
            ->assertRedirect(route('users.index'));

        $this->assertDatabaseMissing('departments', ['id' => $department->id]);
        $this->assertNotSoftDeleted('members', ['id' => $member->id]);
        $this->assertNull($member->fresh()->department_id);
    }

    public function test_a_newly_added_department_appears_in_the_add_member_dropdown(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        Department::factory()->create(['name' => 'Brand New Department']);

        $this->actingAs($admin)
            ->get(route('members.index'))
            ->assertOk()
            ->assertSee('Brand New Department');
    }

    public function test_a_newly_added_department_and_its_division_appear_together_on_the_users_page(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $division = Division::factory()->create(['name' => 'Marketing Division']);
        Department::factory()->create(['name' => 'Brand Team', 'division_id' => $division->id]);

        $this->actingAs($admin)
            ->get(route('users.index'))
            ->assertOk()
            ->assertSee('Brand Team')
            ->assertSee('Marketing Division');
    }
}
