<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Division;
use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Department and Division names are NOT unique (task.md §2.36): two records may
 * share a name and are told apart — in every create / edit / delete / member
 * assignment — by their own id.
 *
 * NOT YET RUN — run `php artisan migrate`, then
 * `php artisan test --filter=DuplicateNamesTest`.
 */
class DuplicateNamesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'is_active' => true]);
    }

    private function memberPayload(array $overrides = []): array
    {
        return array_merge([
            'email' => 'dup'.uniqid().'@example.com',
            'member_type' => '0',
            'last_name' => 'Dela Cruz',
            'first_name' => 'Juan',
            'apply_date' => '2026-04-01',
            'start_date' => '2026-04-01',
            'ghp_amount' => '3600',
            'is_active' => '1',
        ], $overrides);
    }

    // ---- Create ----

    public function test_two_departments_can_share_a_name_and_each_gets_its_own_id(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('departments.store'), ['name' => 'Human Resources'])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('departments.store'), ['name' => 'Human Resources'])->assertSessionHasNoErrors();

        $rows = Department::where('name', 'Human Resources')->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertNotSame($rows[0]->id, $rows[1]->id);
    }

    public function test_two_divisions_can_share_a_name_and_each_gets_its_own_id(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('divisions.store'), ['name' => 'Operations', 'member_type' => '0'])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('divisions.store'), ['name' => 'Operations', 'member_type' => '0'])->assertSessionHasNoErrors();

        $rows = Division::where('name', 'Operations')->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertNotSame($rows[0]->id, $rows[1]->id);
        $this->assertSame(Member::MEMBER_TYPE_EMPLOYEE, $rows[1]->member_type);
    }

    public function test_the_example_lists_from_the_specification_all_save(): void
    {
        $admin = $this->admin();

        foreach (['Human Resources', 'Accounting', 'Human Resources', 'Accounting'] as $name) {
            $this->actingAs($admin)->post(route('departments.store'), ['name' => $name])->assertSessionHasNoErrors();
        }

        foreach (['Operations', 'Administration', 'Operations', 'Administration'] as $name) {
            $this->actingAs($admin)->post(route('divisions.store'), ['name' => $name, 'member_type' => '0'])->assertSessionHasNoErrors();
        }

        $this->assertSame(4, Department::count());
        $this->assertSame(4, Division::count());
    }

    // ---- Edit ----

    public function test_a_department_can_be_renamed_to_a_name_another_department_uses(): void
    {
        $admin = $this->admin();
        $a = Department::factory()->create(['name' => 'Accounting']);
        $b = Department::factory()->create(['name' => 'Finance']);

        $this->actingAs($admin)->put(route('departments.update', $b), ['name' => 'Accounting'])->assertSessionHasNoErrors();

        $this->assertSame('Accounting', $b->fresh()->name);
        $this->assertSame('Accounting', $a->fresh()->name);
    }

    public function test_a_division_can_be_renamed_to_a_name_another_division_uses(): void
    {
        $admin = $this->admin();
        $a = Division::factory()->create(['name' => 'Operations']);
        $b = Division::factory()->create(['name' => 'Administration']);

        $this->actingAs($admin)->put(route('divisions.update', $b), ['name' => 'Operations', 'member_type' => '0'])->assertSessionHasNoErrors();

        $this->assertSame('Operations', $b->fresh()->name);
        $this->assertSame('Operations', $a->fresh()->name);
    }

    public function test_editing_one_duplicate_leaves_the_other_unchanged(): void
    {
        $admin = $this->admin();
        $first = Department::factory()->create(['name' => 'Human Resources']);
        $second = Department::factory()->create(['name' => 'Human Resources']);
        $firstDivision = Division::factory()->create(['name' => 'Operations']);
        $secondDivision = Division::factory()->create(['name' => 'Operations']);

        $this->actingAs($admin)->put(route('departments.update', $second), ['name' => 'People Team']);
        $this->actingAs($admin)->put(route('divisions.update', $secondDivision), ['name' => 'Field Ops', 'member_type' => '1']);

        $this->assertSame('Human Resources', $first->fresh()->name);
        $this->assertSame('People Team', $second->fresh()->name);
        $this->assertSame('Operations', $firstDivision->fresh()->name);
        $this->assertSame(Member::MEMBER_TYPE_EMPLOYEE, $firstDivision->fresh()->member_type);
        $this->assertSame('Field Ops', $secondDivision->fresh()->name);
        $this->assertSame(Member::MEMBER_TYPE_AGENT, $secondDivision->fresh()->member_type);
    }

    // ---- Validation that must remain ----

    public function test_empty_names_and_a_bad_member_type_are_still_rejected(): void
    {
        $admin = $this->admin();
        $division = Division::factory()->create();

        $this->actingAs($admin)->post(route('departments.store'), ['name' => ''])->assertSessionHasErrors('name');
        $this->actingAs($admin)->post(route('divisions.store'), ['name' => '', 'member_type' => '0'])->assertSessionHasErrors('name');
        $this->actingAs($admin)->post(route('divisions.store'), ['name' => 'Valid', 'member_type' => '7'])->assertSessionHasErrors('member_type');
        $this->actingAs($admin)->post(route('divisions.store'), ['name' => 'Valid'])->assertSessionHasErrors('member_type');
        $this->actingAs($admin)->put(route('divisions.update', $division), ['name' => '', 'member_type' => '0'])->assertSessionHasErrors('name');

        $this->assertSame(1, Division::count());
        $this->assertSame(0, Department::count());
    }

    public function test_only_admins_can_manage_divisions(): void
    {
        $staff = User::factory()->create(['role' => 'user', 'is_active' => true]);
        $division = Division::factory()->create(['name' => 'Operations']);

        $this->actingAs($staff)->post(route('divisions.store'), ['name' => 'X', 'member_type' => '0'])->assertForbidden();
        $this->actingAs($staff)->put(route('divisions.update', $division), ['name' => 'Y', 'member_type' => '0'])->assertForbidden();
        $this->actingAs($staff)->delete(route('divisions.destroy', $division))->assertForbidden();

        $this->assertSame('Operations', $division->fresh()->name);
    }

    // ---- Delete ----

    public function test_deleting_one_duplicate_department_removes_only_that_record(): void
    {
        $admin = $this->admin();
        $first = Department::factory()->create(['name' => 'Human Resources']);
        $second = Department::factory()->create(['name' => 'Human Resources']);
        $onFirst = Member::factory()->create(['department_id' => $first->id]);
        $onSecond = Member::factory()->create(['department_id' => $second->id]);

        $this->actingAs($admin)->delete(route('departments.destroy', $second))->assertRedirect(route('users.index'));

        $this->assertDatabaseHas('departments', ['id' => $first->id, 'name' => 'Human Resources']);
        $this->assertDatabaseMissing('departments', ['id' => $second->id]);
        $this->assertSame($first->id, $onFirst->fresh()->department_id);   // untouched
        $this->assertNull($onSecond->fresh()->department_id);              // unassigned, not deleted
        $this->assertNotSoftDeleted('members', ['id' => $onSecond->id]);
    }

    public function test_deleting_one_duplicate_division_removes_only_that_record(): void
    {
        $admin = $this->admin();
        $first = Division::factory()->create(['name' => 'Operations']);
        $second = Division::factory()->create(['name' => 'Operations']);
        $department = Department::factory()->create(['division_id' => $second->id]);
        $onFirst = Member::factory()->create(['division_id' => $first->id]);
        $onSecond = Member::factory()->create(['division_id' => $second->id]);

        $this->actingAs($admin)->delete(route('divisions.destroy', $second))->assertRedirect(route('users.index'));

        $this->assertDatabaseHas('divisions', ['id' => $first->id, 'name' => 'Operations']);
        $this->assertDatabaseMissing('divisions', ['id' => $second->id]);
        $this->assertSame($first->id, $onFirst->fresh()->division_id);
        $this->assertNull($onSecond->fresh()->division_id);
        $this->assertNull($department->fresh()->division_id);
        $this->assertNotSoftDeleted('members', ['id' => $onSecond->id]);
        $this->assertDatabaseHas('departments', ['id' => $department->id]);
    }

    // ---- Department -> Division by id ----

    public function test_a_department_is_assigned_to_the_exact_division_chosen_by_id(): void
    {
        $admin = $this->admin();
        $first = Division::factory()->create(['name' => 'Operations']);
        $second = Division::factory()->create(['name' => 'Operations']);

        $this->actingAs($admin)->post(route('departments.store'), ['name' => 'Dispatch', 'division_id' => $second->id])->assertSessionHasNoErrors();

        $this->assertSame($second->id, Department::where('name', 'Dispatch')->value('division_id'));

        $department = Department::where('name', 'Dispatch')->first();
        $this->actingAs($admin)->put(route('departments.update', $department), ['name' => 'Dispatch', 'division_id' => $first->id]);
        $this->assertSame($first->id, $department->fresh()->division_id);

        // Blank = no division.
        $this->actingAs($admin)->put(route('departments.update', $department), ['name' => 'Dispatch', 'division_id' => '']);
        $this->assertNull($department->fresh()->division_id);
    }

    public function test_a_department_with_an_unknown_division_id_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->post(route('departments.store'), ['name' => 'Ghost', 'division_id' => 99999])
            ->assertSessionHasErrors('division_id');

        $this->assertSame(0, Department::where('name', 'Ghost')->count());
    }

    public function test_the_legacy_division_name_field_picks_the_lowest_id_among_duplicates(): void
    {
        $first = Division::factory()->create(['name' => 'Operations']);
        Division::factory()->create(['name' => 'Operations']);

        $this->actingAs($this->admin())->post(route('departments.store'), ['name' => 'Legacy', 'division' => 'operations']);

        $this->assertSame($first->id, Department::where('name', 'Legacy')->value('division_id'));
        $this->assertSame(2, Division::count());   // reused, none created
    }

    // ---- Member assignment ----

    public function test_a_new_member_is_saved_with_the_department_and_division_ids_chosen(): void
    {
        $admin = $this->admin();
        $deptA = Department::factory()->create(['name' => 'Human Resources']);
        $deptB = Department::factory()->create(['name' => 'Human Resources']);
        $divA = Division::factory()->create(['name' => 'Operations']);
        $divB = Division::factory()->create(['name' => 'Operations']);

        $payload = $this->memberPayload(['department_id' => $deptB->id, 'division_id' => $divB->id]);
        $this->actingAs($admin)->post(route('members.store'), $payload)->assertSessionHasNoErrors();

        $member = Member::where('email', $payload['email'])->firstOrFail();
        $this->assertSame($deptB->id, $member->department_id);
        $this->assertSame($divB->id, $member->division_id);
        $this->assertNotSame($deptA->id, $member->department_id);
        $this->assertNotSame($divA->id, $member->division_id);
    }

    public function test_editing_a_member_stores_the_chosen_duplicate_ids_and_leaves_other_members_alone(): void
    {
        $admin = $this->admin();
        $deptA = Department::factory()->create(['name' => 'Human Resources']);
        $deptB = Department::factory()->create(['name' => 'Human Resources']);
        $divA = Division::factory()->create(['name' => 'Operations']);
        $divB = Division::factory()->create(['name' => 'Operations']);
        $member = Member::factory()->create(['department_id' => $deptA->id, 'division_id' => $divA->id]);
        $bystander = Member::factory()->create(['department_id' => $deptA->id, 'division_id' => $divA->id]);

        $this->actingAs($admin)->put(route('members.update', $member), $this->memberPayload([
            'code' => $member->code,
            'email' => $member->email ?? 'edit'.uniqid().'@example.com',
            'member_type' => (string) $member->member_type,
            'department_id' => $deptB->id,
            'division_id' => $divB->id,
        ]))->assertSessionHasNoErrors();

        $member->refresh();
        $this->assertSame($deptB->id, $member->department_id);
        $this->assertSame($divB->id, $member->division_id);
        $this->assertSame($deptA->id, $bystander->fresh()->department_id);
        $this->assertSame($divA->id, $bystander->fresh()->division_id);
    }

    public function test_adding_a_duplicate_does_not_disturb_existing_assignments(): void
    {
        $admin = $this->admin();
        $dept = Department::factory()->create(['name' => 'Human Resources']);
        $member = Member::factory()->create(['department_id' => $dept->id]);

        $this->actingAs($admin)->post(route('departments.store'), ['name' => 'Human Resources']);

        $this->assertSame($dept->id, $member->fresh()->department_id);
        $this->assertSame('Human Resources', $dept->fresh()->name);
    }

    // ---- Dropdowns and pages ----

    public function test_dropdowns_submit_ids_and_label_only_repeated_names_with_their_id(): void
    {
        $admin = $this->admin();
        $deptA = Department::factory()->create(['name' => 'Human Resources']);
        $deptB = Department::factory()->create(['name' => 'Human Resources']);
        $unique = Department::factory()->create(['name' => 'Legal']);
        $divA = Division::factory()->create(['name' => 'Operations']);
        $divB = Division::factory()->create(['name' => 'Operations']);

        $html = $this->actingAs($admin)->get(route('members.index'))->assertOk()->getContent();

        // Option VALUES are the ids; both identical names are present as separate options.
        $this->assertStringContainsString('<option value="'.$deptA->id.'"', $html);
        $this->assertStringContainsString('<option value="'.$deptB->id.'"', $html);
        $this->assertStringContainsString('Human Resources #'.$deptA->id, $html);
        $this->assertStringContainsString('Human Resources #'.$deptB->id, $html);
        $this->assertStringContainsString('Operations #'.$divA->id, $html);
        $this->assertStringContainsString('Operations #'.$divB->id, $html);
        // A name that is not repeated stays exactly as before.
        $this->assertStringNotContainsString('Legal #', $html);
        $this->assertStringContainsString('Legal', $html);
    }

    public function test_the_edit_member_and_reports_dropdowns_also_distinguish_duplicates(): void
    {
        $admin = $this->admin();
        $deptA = Department::factory()->create(['name' => 'Human Resources']);
        $deptB = Department::factory()->create(['name' => 'Human Resources']);
        $member = Member::factory()->create(['department_id' => $deptB->id]);

        $show = $this->actingAs($admin)->get(route('members.show', $member))->assertOk()->getContent();
        $this->assertStringContainsString('Human Resources #'.$deptA->id, $show);
        $this->assertStringContainsString('Human Resources #'.$deptB->id, $show);
        $this->assertMatchesRegularExpression('/<option value="'.$deptB->id.'"\s+selected/', $show);   // the member's own record is selected

        $reports = $this->actingAs($admin)->get(route('reports.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Human Resources #'.$deptA->id, $reports);
        $this->assertStringContainsString('Human Resources #'.$deptB->id, $reports);
    }

    public function test_the_users_page_lists_duplicate_records_with_their_ids_and_counts(): void
    {
        $admin = $this->admin();
        $divA = Division::factory()->create(['name' => 'Operations']);
        $divB = Division::factory()->create(['name' => 'Operations']);
        Department::factory()->create(['name' => 'Dispatch', 'division_id' => $divB->id]);
        Member::factory()->create(['division_id' => $divB->id]);

        $html = $this->actingAs($admin)->get(route('users.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Division Management', $html);
        $this->assertSame(1, substr_count($html, 'data-division-id="'.$divA->id.'"'));
        $this->assertSame(1, substr_count($html, 'data-division-id="'.$divB->id.'"'));
        $this->assertStringContainsString('Operations #'.$divA->id, $html);
        $this->assertStringContainsString('Operations #'.$divB->id, $html);
        $this->assertSame(2, Division::count());
    }

    // ---- CSV import ----

    public function test_the_csv_import_uses_the_lowest_id_when_a_name_is_shared(): void
    {
        $admin = $this->admin();
        $firstDept = Department::factory()->create(['name' => 'Human Resources']);
        Department::factory()->create(['name' => 'Human Resources']);
        $firstDiv = Division::factory()->create(['name' => 'Operations']);
        Division::factory()->create(['name' => 'Operations']);

        $csv = "email,member_type,last_name,first_name,apply_date,start_date,ghp_amount,division,department\n"
            ."csvdup@example.com,Employee,Reyes,Ana,2026-04-01,2026-04-01,3600,Operations,Human Resources\n";

        $this->actingAs($admin)->post(route('members.import'), [
            'csv_file' => UploadedFile::fake()->createWithContent('members.csv', $csv),
        ]);

        $member = Member::where('email', 'csvdup@example.com')->firstOrFail();
        $this->assertSame($firstDept->id, $member->department_id);
        $this->assertSame($firstDiv->id, $member->division_id);
    }

    // ---- Schema ----

    public function test_the_database_no_longer_has_a_unique_index_on_the_names(): void
    {
        $unique = fn (string $table, array $columns) => collect(\Illuminate\Support\Facades\Schema::getIndexes($table))
            ->contains(fn ($index) => ! empty($index['unique']) && empty($index['primary']) && $index['columns'] === $columns);

        $this->assertFalse($unique('departments', ['name']));
        $this->assertFalse($unique('divisions', ['member_type', 'name']));
    }
}
