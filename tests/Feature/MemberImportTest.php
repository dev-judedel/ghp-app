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
 * The Add Member modal (StoreMemberRequest) is the source of truth for the
 * CSV bulk importer's template and validation — see task.md §2.26. These
 * tests build CSVs with the CURRENT required columns (email, member_type,
 * last_name, first_name, apply_date, start_date, ghp_amount), not the older
 * minimal set the importer used to accept.
 *
 * NOT YET RUN — written from reading the code.
 * Run: php artisan test --filter=MemberImportTest
 */
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

    /** A minimal, currently-valid header row covering only the required columns. */
    private function minimalHeader(): string
    {
        return "email,member_type,last_name,first_name,apply_date,start_date,ghp_amount\n";
    }

    private function row(string $email, string $firstName = 'Juan', string $lastName = 'Dela Cruz', string $type = 'Employee', string $amount = '3600'): string
    {
        return "{$email},{$type},{$lastName},{$firstName},2026-04-01,2026-04-01,{$amount}\n";
    }

    // ---- Test 1: the template matches the current Add Member requirements ----

    public function test_the_template_download_has_every_current_add_member_column_in_order(): void
    {
        $response = $this->actingAs($this->admin())->get(route('members.import.template'));

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $content = $response->streamedContent();
        // Strip the UTF-8 BOM (StreamsCsv writes one so Excel doesn't mangle
        // special characters) before parsing, or it folds into the first
        // header cell ("code" becomes "\xEF\xBB\xBFcode").
        $content = ltrim($content, "\xEF\xBB\xBF");
        $lines = explode("\n", trim($content));
        $header = str_getcsv($lines[0]);

        $this->assertSame([
            'code', 'email', 'old_code', 'member_type', 'last_name', 'first_name', 'middle_name',
            'address', 'birthdate', 'civil_status', 'division', 'department', 'is_active',
            'coverage_year', 'apply_date', 'coverage_end_date', 'start_date', 'deduction_start_date',
            'ghp_amount', 'remarks',
        ], $header);

        // The dummy row is itself a valid, importable row against the current rules.
        $sample = str_getcsv($lines[1]);
        $this->assertCount(count($header), $sample);
    }

    public function test_the_templates_own_sample_row_imports_successfully(): void
    {
        $response = $this->actingAs($this->admin())->get(route('members.import.template'));
        $csv = $this->csvFile($response->streamedContent(), 'sample.csv');

        $this->actingAs($this->admin())
            ->post(route('members.import'), ['csv_file' => $csv])
            ->assertSessionHas('import_report', fn ($report) => $report['created'] === 1 && count($report['skipped']) === 0);
    }

    /**
     * Regression test: fgetcsv() does not strip a UTF-8 BOM, and the
     * downloaded template always has one. Without stripping it on read,
     * the header's first cell becomes "\xEF\xBB\xBFcode" instead of
     * "code", so an explicit code in row 1 of a re-uploaded template would
     * silently be ignored (dropped to auto-generation) instead of honored.
     */
    public function test_an_explicit_code_in_a_bom_prefixed_csv_the_templates_own_format_is_honored(): void
    {
        $csv = "\xEF\xBB\xBF"."code,email,member_type,last_name,first_name,apply_date,start_date,ghp_amount\n"
            ."CUSTOM-BOM,juan@example.test,Employee,Dela Cruz,Juan,2026-04-01,2026-04-01,3600\n";

        $this->actingAs($this->admin())->post(route('members.import'), ['csv_file' => $this->csvFile($csv)]);

        $member = Member::where('email', 'juan@example.test')->firstOrFail();
        $this->assertSame('CUSTOM-BOM', $member->code);
    }

    // ---- Test 2: a completely new member ----

    public function test_a_valid_csv_imports_all_rows(): void
    {
        $csv = $this->minimalHeader()
            .$this->row('juan@example.test', 'Juan', 'Dela Cruz', 'Employee', '3600')
            .$this->row('maria@example.test', 'Maria', 'Santos', 'Agent', '4200');

        $this->actingAs($this->admin())
            ->post(route('members.import'), ['csv_file' => $this->csvFile($csv)])
            ->assertRedirect(route('members.index'));

        $this->assertSame(2, Member::count());
        $this->assertDatabaseHas('members', ['email' => 'juan@example.test', 'apply_date' => '2026-04-01', 'start_date' => '2026-04-01']);
        $this->assertDatabaseHas('members', ['email' => 'maria@example.test']);
    }

    public function test_blank_code_column_auto_generates_a_code(): void
    {
        $csv = $this->minimalHeader().$this->row('juan@example.test');

        $this->actingAs($this->admin())->post(route('members.import'), ['csv_file' => $this->csvFile($csv)]);

        $member = Member::where('email', 'juan@example.test')->firstOrFail();
        $this->assertMatchesRegularExpression('/^ALSC-\d{6}$/', $member->code);
    }

    public function test_a_provided_code_column_is_used_as_is(): void
    {
        $csv = "code,email,member_type,last_name,first_name,apply_date,start_date,ghp_amount\n"
            ."CUSTOM-1,juan@example.test,Employee,Dela Cruz,Juan,2026-04-01,2026-04-01,3600\n";

        $this->actingAs($this->admin())->post(route('members.import'), ['csv_file' => $this->csvFile($csv)]);

        $member = Member::where('email', 'juan@example.test')->firstOrFail();
        $this->assertSame('CUSTOM-1', $member->code);
    }

    /** The deduction start date, when left blank, is resolved the SAME way as the manual form: 1st of the month after start_date. */
    public function test_deduction_start_date_left_blank_is_resolved_the_same_way_as_add_member(): void
    {
        $csv = $this->minimalHeader().$this->row('juan@example.test');

        $this->actingAs($this->admin())->post(route('members.import'), ['csv_file' => $this->csvFile($csv)]);

        $member = Member::where('email', 'juan@example.test')->firstOrFail();
        $this->assertSame('2026-05-01', $member->deduction_start_date->toDateString());
    }

    /** A non-standard GHP amount is flagged manual, exactly like Add Member / Adjust GHP amount. */
    public function test_a_non_standard_ghp_amount_is_flagged_manual(): void
    {
        $csv = $this->minimalHeader().$this->row('juan@example.test', amount: '5000');

        $this->actingAs($this->admin())->post(route('members.import'), ['csv_file' => $this->csvFile($csv)]);

        $member = Member::where('email', 'juan@example.test')->firstOrFail();
        $this->assertTrue($member->ghp_amount_is_manual);
    }

    public function test_coverage_year_and_end_date_can_be_set_together(): void
    {
        $csv = "email,member_type,last_name,first_name,apply_date,start_date,ghp_amount,coverage_year,coverage_end_date\n"
            ."juan@example.test,Employee,Dela Cruz,Juan,2026-04-01,2026-04-01,3600,2026,2027-03-31\n";

        $this->actingAs($this->admin())
            ->post(route('members.import'), ['csv_file' => $this->csvFile($csv)])
            ->assertSessionHas('import_report', fn ($r) => $r['created'] === 1);

        $member = Member::where('email', 'juan@example.test')->firstOrFail();
        $this->assertSame(2026, $member->coverage_year);
        $this->assertSame('2027-03-31', $member->coverage_end_date->toDateString());
    }

    // ---- Test 9: a required column missing entirely stops the whole import with one clear error ----

    public function test_a_missing_required_column_stops_the_import_with_one_clear_error_not_per_row(): void
    {
        // apply_date and start_date columns are both entirely absent.
        $csv = "email,member_type,last_name,first_name,ghp_amount\n"
            ."juan@example.test,Employee,Dela Cruz,Juan,3600\n";

        $response = $this->actingAs($this->admin())
            ->post(route('members.import'), ['csv_file' => $this->csvFile($csv)]);

        $response->assertSessionHas('import_report', function ($report) {
            return ($report['error'] ?? null) !== null
                && str_contains($report['error'], 'apply_date')
                && str_contains($report['error'], 'start_date')
                && $report['created'] === 0
                && $report['skipped'] === [];
        });

        $this->assertSame(0, Member::count());
    }

    public function test_an_unrecognized_column_is_reported_but_does_not_block_the_import(): void
    {
        $csv = "email,member_type,last_name,first_name,apply_date,start_date,ghp_amount,employee_id\n"
            ."juan@example.test,Employee,Dela Cruz,Juan,2026-04-01,2026-04-01,3600,EMP-001\n";

        $response = $this->actingAs($this->admin())
            ->post(route('members.import'), ['csv_file' => $this->csvFile($csv)]);

        $response->assertSessionHas('import_report', fn ($r) => $r['created'] === 1 && in_array('employee_id', $r['unknown_columns'], true));
    }

    // ---- A row missing a required FIELD (as opposed to a missing column) is skipped, the rest of the file still imports ----

    public function test_a_row_missing_a_required_field_is_skipped_but_the_rest_of_the_file_still_imports(): void
    {
        $csv = $this->minimalHeader()
            .",Employee,Dela Cruz,Juan,2026-04-01,2026-04-01,3600\n"   // missing email -> skipped
            .$this->row('maria@example.test', 'Maria', 'Santos', 'Agent', '4200'); // valid -> imported

        $response = $this->actingAs($this->admin())
            ->post(route('members.import'), ['csv_file' => $this->csvFile($csv)]);

        $response->assertSessionHas('import_report', fn ($report) => $report['created'] === 1 && count($report['skipped']) === 1);

        $this->assertSame(1, Member::count());
        $this->assertDatabaseHas('members', ['email' => 'maria@example.test']);
    }

    // ---- Test 8: invalid email ----

    public function test_an_invalid_email_is_rejected(): void
    {
        $csv = $this->minimalHeader().$this->row('not-an-email');

        $response = $this->actingAs($this->admin())
            ->post(route('members.import'), ['csv_file' => $this->csvFile($csv)]);

        $response->assertSessionHas('import_report', fn ($r) => $r['created'] === 0 && count($r['skipped']) === 1);
        $this->assertSame(0, Member::count());
    }

    // ---- Test 10: invalid Benefit Setup, same rules as Add Member ----

    public function test_an_end_date_before_the_apply_date_is_rejected_same_as_add_member(): void
    {
        $csv = "email,member_type,last_name,first_name,apply_date,start_date,ghp_amount,coverage_year,coverage_end_date\n"
            ."juan@example.test,Employee,Dela Cruz,Juan,2026-04-01,2026-04-01,3600,2026,2026-01-01\n"; // end before apply

        $response = $this->actingAs($this->admin())
            ->post(route('members.import'), ['csv_file' => $this->csvFile($csv)]);

        $response->assertSessionHas('import_report', fn ($r) => $r['created'] === 0 && count($r['skipped']) === 1);
        $this->assertSame(0, Member::count());
    }

    public function test_a_coverage_year_without_a_matching_end_date_is_rejected(): void
    {
        $csv = "email,member_type,last_name,first_name,apply_date,start_date,ghp_amount,coverage_year\n"
            ."juan@example.test,Employee,Dela Cruz,Juan,2026-04-01,2026-04-01,3600,2026\n"; // end date missing

        $response = $this->actingAs($this->admin())
            ->post(route('members.import'), ['csv_file' => $this->csvFile($csv)]);

        $response->assertSessionHas('import_report', fn ($r) => $r['created'] === 0 && count($r['skipped']) === 1);
    }

    public function test_a_negative_ghp_amount_is_rejected(): void
    {
        $csv = $this->minimalHeader().$this->row('juan@example.test', amount: '-100');

        $response = $this->actingAs($this->admin())
            ->post(route('members.import'), ['csv_file' => $this->csvFile($csv)]);

        $response->assertSessionHas('import_report', fn ($r) => $r['created'] === 0 && count($r['skipped']) === 1);
    }

    // ---- Tests 3, 4, 5: database duplicate detection ----

    public function test_a_row_with_a_duplicate_email_already_in_the_database_is_skipped_with_a_clear_reason(): void
    {
        Member::factory()->create(['email' => 'Taken@Example.test']);

        $csv = $this->minimalHeader().$this->row('taken@example.test'); // same email, different case

        $response = $this->actingAs($this->admin())
            ->post(route('members.import'), ['csv_file' => $this->csvFile($csv)]);

        $response->assertSessionHas('import_report', function ($report) {
            return $report['created'] === 0
                && count($report['skipped']) === 1
                && str_contains($report['skipped'][0]['reason'], 'Duplicate member')
                && str_contains($report['skipped'][0]['reason'], 'taken@example.test')
                && str_contains($report['skipped'][0]['reason'], 'already exists in the database');
        });

        $this->assertSame(1, Member::count()); // only the pre-existing one
    }

    public function test_a_row_with_a_code_that_already_exists_is_skipped_with_a_clear_reason(): void
    {
        Member::factory()->create(['code' => 'EMP-00125']);

        $csv = "code,email,member_type,last_name,first_name,apply_date,start_date,ghp_amount\n"
            ."EMP-00125,new@example.test,Employee,Dela Cruz,Juan,2026-04-01,2026-04-01,3600\n";

        $response = $this->actingAs($this->admin())
            ->post(route('members.import'), ['csv_file' => $this->csvFile($csv)]);

        $response->assertSessionHas('import_report', function ($report) {
            return $report['created'] === 0
                && count($report['skipped']) === 1
                && str_contains($report['skipped'][0]['reason'], 'Member already exists')
                && str_contains($report['skipped'][0]['reason'], 'EMP-00125');
        });

        $this->assertSame(1, Member::count());
    }

    public function test_a_row_matching_an_inactive_members_email_is_skipped_and_says_so(): void
    {
        Member::factory()->create(['email' => 'juan@example.test', 'is_active' => false]);

        $csv = $this->minimalHeader().$this->row('juan@example.test');

        $response = $this->actingAs($this->admin())
            ->post(route('members.import'), ['csv_file' => $this->csvFile($csv)]);

        $response->assertSessionHas('import_report', function ($report) {
            return $report['created'] === 0
                && count($report['skipped']) === 1
                && str_contains($report['skipped'][0]['reason'], 'Inactive');
        });

        $this->assertSame(1, Member::count());
        $this->assertFalse(Member::first()->is_active); // untouched, not reactivated/overwritten
    }

    // ---- Test 6: duplicate inside the CSV itself ----

    public function test_a_duplicate_email_within_the_same_file_is_only_imported_once_and_the_second_row_names_the_first(): void
    {
        $csv = $this->minimalHeader()
            .$this->row('dupe@example.test', 'Juan', 'Dela Cruz')
            .$this->row('dupe@example.test', 'Jose', 'Reyes');

        $response = $this->actingAs($this->admin())
            ->post(route('members.import'), ['csv_file' => $this->csvFile($csv)]);

        $response->assertSessionHas('import_report', function ($report) {
            return $report['created'] === 1
                && count($report['skipped']) === 1
                && $report['skipped'][0]['row'] === 3
                && str_contains($report['skipped'][0]['reason'], 'Duplicate entry in uploaded CSV')
                && str_contains($report['skipped'][0]['reason'], 'Row 2');
        });

        $this->assertSame(1, Member::where('email', 'dupe@example.test')->count());
        // The FIRST occurrence is the one kept.
        $this->assertSame('Dela Cruz', Member::where('email', 'dupe@example.test')->first()->last_name);
    }

    public function test_a_duplicate_code_within_the_same_file_is_caught_too(): void
    {
        $csv = "code,email,member_type,last_name,first_name,apply_date,start_date,ghp_amount\n"
            ."DUP-1,one@example.test,Employee,Dela Cruz,Juan,2026-04-01,2026-04-01,3600\n"
            ."DUP-1,two@example.test,Employee,Santos,Maria,2026-04-01,2026-04-01,3600\n";

        $response = $this->actingAs($this->admin())
            ->post(route('members.import'), ['csv_file' => $this->csvFile($csv)]);

        $response->assertSessionHas('import_report', fn ($r) => $r['created'] === 1 && count($r['skipped']) === 1);
        $this->assertSame(1, Member::count());
    }

    // ---- Test 7 / 11: the same CSV uploaded twice never creates duplicates ----

    public function test_uploading_the_same_csv_twice_creates_no_duplicates_the_second_time(): void
    {
        $csv = $this->minimalHeader()
            .$this->row('juan@example.test', 'Juan', 'Dela Cruz')
            .$this->row('maria@example.test', 'Maria', 'Santos');

        $this->actingAs($this->admin())
            ->post(route('members.import'), ['csv_file' => $this->csvFile($csv)])
            ->assertSessionHas('import_report', fn ($r) => $r['created'] === 2 && count($r['skipped']) === 0);

        $this->assertSame(2, Member::count());

        // Re-upload the identical file.
        $response = $this->actingAs($this->admin())
            ->post(route('members.import'), ['csv_file' => $this->csvFile($csv)]);

        $response->assertSessionHas('import_report', fn ($r) => $r['created'] === 0 && count($r['skipped']) === 2);
        $this->assertSame(2, Member::count()); // still exactly 2 — no duplicates created
    }

    // ---- Existing members are never modified by import, only created fresh ----

    public function test_a_duplicate_row_never_modifies_the_existing_member(): void
    {
        $existing = Member::factory()->create([
            'email' => 'juan@example.test',
            'last_name' => 'Original',
            'ghp_amount' => 3600,
        ]);

        $csv = $this->minimalHeader().$this->row('juan@example.test', 'Juan', 'Changed', amount: '9999');

        $this->actingAs($this->admin())->post(route('members.import'), ['csv_file' => $this->csvFile($csv)]);

        $existing->refresh();
        $this->assertSame('Original', $existing->last_name);
        $this->assertSame('3600.00', (string) $existing->ghp_amount);
        $this->assertSame(1, Member::count());
    }

    // ---- Other existing behavior, unchanged ----

    public function test_blank_lines_in_the_csv_are_ignored_without_being_reported_as_errors(): void
    {
        $csv = $this->minimalHeader()
            .$this->row('juan@example.test')
            ."\n"
            .",,,,,,\n";

        $response = $this->actingAs($this->admin())
            ->post(route('members.import'), ['csv_file' => $this->csvFile($csv)]);

        $response->assertSessionHas('import_report', fn ($report) => $report['created'] === 1 && count($report['skipped']) === 0);
    }

    public function test_division_and_department_are_matched_by_name(): void
    {
        $division = Division::factory()->create(['name' => 'Sales Division']);
        $department = Department::factory()->create(['name' => 'Sales Department', 'division_id' => $division->id]);

        $csv = "email,member_type,last_name,first_name,apply_date,start_date,ghp_amount,division,department\n"
            ."juan@example.test,Employee,Dela Cruz,Juan,2026-04-01,2026-04-01,3600,Sales Division,Sales Department\n";

        $this->actingAs($this->admin())->post(route('members.import'), ['csv_file' => $this->csvFile($csv)]);

        $member = Member::where('email', 'juan@example.test')->firstOrFail();
        $this->assertSame($division->id, $member->division_id);
        $this->assertSame($department->id, $member->department_id);
    }

    public function test_an_unmatched_division_name_does_not_reject_the_row(): void
    {
        $csv = "email,member_type,last_name,first_name,apply_date,start_date,ghp_amount,division\n"
            ."juan@example.test,Employee,Dela Cruz,Juan,2026-04-01,2026-04-01,3600,Nonexistent Division\n";

        $this->actingAs($this->admin())->post(route('members.import'), ['csv_file' => $this->csvFile($csv)]);

        $member = Member::where('email', 'juan@example.test')->firstOrFail();
        $this->assertNull($member->division_id);
    }

    public function test_is_active_column_defaults_to_active_and_can_be_set_to_inactive(): void
    {
        $csv = "email,member_type,last_name,first_name,apply_date,start_date,ghp_amount,is_active\n"
            ."juan@example.test,Employee,Dela Cruz,Juan,2026-04-01,2026-04-01,3600,\n"
            ."maria@example.test,Employee,Santos,Maria,2026-04-01,2026-04-01,3600,Inactive\n";

        $this->actingAs($this->admin())->post(route('members.import'), ['csv_file' => $this->csvFile($csv)]);

        $this->assertTrue(Member::where('email', 'juan@example.test')->first()->is_active);
        $this->assertFalse(Member::where('email', 'maria@example.test')->first()->is_active);
    }

    public function test_a_non_admin_cannot_import(): void
    {
        $staff = User::factory()->create(['role' => 'user', 'is_active' => true]);

        $csv = $this->minimalHeader().$this->row('juan@example.test');

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

    // ---- Test 12: manual Add Member is unaffected by any of this ----

    public function test_manual_add_member_still_works_and_matches_import_in_how_a_member_is_built(): void
    {
        $this->actingAs($this->admin())->post(route('members.store'), [
            'email' => 'manual@example.test',
            'member_type' => '0',
            'last_name' => 'Dela Cruz',
            'first_name' => 'Juan',
            'apply_date' => '2026-04-01',
            'start_date' => '2026-04-01',
            'ghp_amount' => '3600',
        ])->assertSessionHasNoErrors();

        $member = Member::where('email', 'manual@example.test')->firstOrFail();
        $this->assertMatchesRegularExpression('/^ALSC-\d{6}$/', $member->code);
        $this->assertSame('2026-05-01', $member->deduction_start_date->toDateString()); // same fallback rule as import
        $this->assertFalse($member->ghp_amount_is_manual);
        $this->assertTrue($member->is_active);
    }
}
