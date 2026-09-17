<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Division;
use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class MemberImportTest extends TestCase
{
    use RefreshDatabase;

    private function csvFile(string $content, string $name = 'members.csv'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, 'text/csv', null, true);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'is_active' => true]);
    }

    public function test_a_valid_csv_imports_all_rows(): void
    {
        $csv = "code,email,member_type,last_name,first_name,middle_name,address,birthdate,civil_status,division,department,apply_date,deduction_start_date,ghp_amount,old_code,is_active\n"
            .",juan@example.test,Employee,Dela Cruz,Juan,,,,,,,,,3600,,Active\n"
            .",maria@example.test,Agent,Santos,Maria,,,,,,,,,4200,,Active\n";

        $this->actingAs($this->admin())
            ->post(route('members.import'), ['csv_file' => $this->csvFile($csv)])
            ->assertRedirect(route('members.index'));

        $this->assertSame(2, Member::count());
        $this->assertDatabaseHas('members', ['email' => 'juan@example.test']);
        $this->assertDatabaseHas('members', ['email' => 'maria@example.test']);
    }

    public function test_blank_code_column_auto_generates_a_code(): void
    {
        $csv = "code,email,member_type,last_name,first_name,ghp_amount\n"
            .",juan@example.test,Employee,Dela Cruz,Juan,3600\n";

        $this->actingAs($this->admin())
            ->post(route('members.import'), ['csv_file' => $this->csvFile($csv)]);

        $member = Member::where('email', 'juan@example.test')->firstOrFail();
        $this->assertMatchesRegularExpression('/^ALSC-\d{6}$/', $member->code);
    }

    public function test_a_provided_code_column_is_used_as_is(): void
    {
        $csv = "code,email,member_type,last_name,first_name,ghp_amount\n"
            ."CUSTOM-1,juan@example.test,Employee,Dela Cruz,Juan,3600\n";

        $this->actingAs($this->admin())
            ->post(route('members.import'), ['csv_file' => $this->csvFile($csv)]);

        $member = Member::where('email', 'juan@example.test')->firstOrFail();
        $this->assertSame('CUSTOM-1', $member->code);
    }

    public function test_a_row_missing_a_required_field_is_skipped_but_the_rest_of_the_file_still_imports(): void
    {
        $csv = "email,member_type,last_name,first_name,ghp_amount\n"
            .",Employee,Dela Cruz,Juan,3600\n"          // missing email -> skipped
            ."maria@example.test,Agent,Santos,Maria,4200\n"; // valid -> imported

        $response = $this->actingAs($this->admin())
            ->post(route('members.import'), ['csv_file' => $this->csvFile($csv)]);

        $response->assertSessionHas('import_report', function ($report) {
            return $report['created'] === 1 && count($report['skipped']) === 1;
        });

        $this->assertSame(1, Member::count());
        $this->assertDatabaseHas('members', ['email' => 'maria@example.test']);
    }

    public function test_a_row_with_a_duplicate_email_already_in_the_database_is_skipped(): void
    {
        Member::factory()->create(['email' => 'taken@example.test']);

        $csv = "email,member_type,last_name,first_name,ghp_amount\n"
            ."taken@example.test,Employee,Dela Cruz,Juan,3600\n";

        $response = $this->actingAs($this->admin())
            ->post(route('members.import'), ['csv_file' => $this->csvFile($csv)]);

        $response->assertSessionHas('import_report', fn ($report) => $report['created'] === 0 && count($report['skipped']) === 1);
        $this->assertSame(1, Member::count()); // only the pre-existing one
    }

    public function test_a_duplicate_email_within_the_same_file_is_only_imported_once(): void
    {
        $csv = "email,member_type,last_name,first_name,ghp_amount\n"
            ."dupe@example.test,Employee,Dela Cruz,Juan,3600\n"
            ."dupe@example.test,Employee,Reyes,Jose,3600\n";

        $this->actingAs($this->admin())
            ->post(route('members.import'), ['csv_file' => $this->csvFile($csv)]);

        $this->assertSame(1, Member::where('email', 'dupe@example.test')->count());
    }

    public function test_blank_lines_in_the_csv_are_ignored_without_being_reported_as_errors(): void
    {
        $csv = "email,member_type,last_name,first_name,ghp_amount\n"
            ."juan@example.test,Employee,Dela Cruz,Juan,3600\n"
            ."\n"
            .",,,,\n";

        $response = $this->actingAs($this->admin())
            ->post(route('members.import'), ['csv_file' => $this->csvFile($csv)]);

        $response->assertSessionHas('import_report', fn ($report) => $report['created'] === 1 && count($report['skipped']) === 0);
    }

    public function test_division_and_department_are_matched_by_name(): void
    {
        $division = Division::factory()->create(['name' => 'Sales Division']);
        $department = Department::factory()->create(['name' => 'Sales Department', 'division_id' => $division->id]);

        $csv = "email,member_type,last_name,first_name,ghp_amount,division,department\n"
            ."juan@example.test,Employee,Dela Cruz,Juan,3600,Sales Division,Sales Department\n";

        $this->actingAs($this->admin())
            ->post(route('members.import'), ['csv_file' => $this->csvFile($csv)]);

        $member = Member::where('email', 'juan@example.test')->firstOrFail();
        $this->assertSame($division->id, $member->division_id);
        $this->assertSame($department->id, $member->department_id);
    }

    public function test_an_unmatched_division_name_does_not_reject_the_row(): void
    {
        $csv = "email,member_type,last_name,first_name,ghp_amount,division\n"
            ."juan@example.test,Employee,Dela Cruz,Juan,3600,Nonexistent Division\n";

        $this->actingAs($this->admin())
            ->post(route('members.import'), ['csv_file' => $this->csvFile($csv)]);

        $member = Member::where('email', 'juan@example.test')->firstOrFail();
        $this->assertNull($member->division_id);
    }

    public function test_a_non_admin_cannot_import(): void
    {
        $staff = User::factory()->create(['role' => 'user', 'is_active' => true]);

        $csv = "email,member_type,last_name,first_name,ghp_amount\njuan@example.test,Employee,Dela Cruz,Juan,3600\n";

        $this->actingAs($staff)
            ->post(route('members.import'), ['csv_file' => $this->csvFile($csv)])
            ->assertForbidden();

        $this->assertSame(0, Member::count());
    }

    public function test_a_non_csv_file_is_rejected(): void
    {
        $file = UploadedFile::fake()->create('members.pdf', 10, 'application/pdf');

        $this->actingAs($this->admin())
            ->post(route('members.import'), ['csv_file' => $file])
            ->assertSessionHasErrors('csv_file');

        $this->assertSame(0, Member::count());
    }

    public function test_the_template_download_is_a_csv_with_the_expected_headers(): void
    {
        $response = $this->actingAs($this->admin())->get(route('members.import.template'));

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $content = $response->streamedContent();
        $this->assertStringContainsString('email', $content);
        $this->assertStringContainsString('member_type', $content);
    }
}
