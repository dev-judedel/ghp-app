<?php

namespace App\Services;

use App\Http\Requests\StoreMemberRequest;
use App\Models\Department;
use App\Models\Division;
use App\Models\Member;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;

/**
 * CSV bulk importer for Members. The Add Member modal (StoreMemberRequest)
 * is the source of truth for both the template's columns and every row's
 * validation — see task.md §2.26. This service does NOT maintain its own
 * parallel copy of "what's required": it builds a real StoreMemberRequest
 * per row and runs the exact same rules (including the cross-field Benefit
 * Setup checks in ValidatesCoverageSetup), so the importer can never again
 * quietly drift out of sync with the manual form the way it previously did.
 *
 * On top of that shared validation, this service adds what a CSV uniquely
 * needs: duplicate detection within the uploaded file itself, and a
 * friendlier duplicate message (with the conflicting row/member identified)
 * than Laravel's generic "has already been taken" for the database check.
 */
class MemberCsvImportService
{
    /**
     * Row-processing cap so a huge file can't tie up a request indefinitely.
     * Reported back in the import summary if hit.
     */
    private const MAX_ROWS = 5000;

    /**
     * Every column the downloadable template ships with, in the same
     * section order as the Add Member modal: Identity -> Assignment ->
     * Benefit Setup -> Remarks. See REQUIRED_HEADERS for which of these
     * are actually mandatory (mirrors StoreMemberRequest exactly); the
     * rest are optional, same as in the modal.
     */
    public const TEMPLATE_HEADERS = [
        'code', 'email', 'old_code', 'member_type', 'last_name', 'first_name', 'middle_name',
        'address', 'birthdate', 'civil_status', 'division', 'department', 'is_active',
        'coverage_year', 'apply_date', 'coverage_end_date', 'start_date', 'deduction_start_date',
        'ghp_amount', 'remarks',
    ];

    /**
     * Columns that must be PRESENT in the uploaded file's header row at all
     * (not just filled in on every row — that's checked per row by
     * StoreMemberRequest's own 'required' rules). Mirrors exactly which
     * fields StoreMemberRequest marks 'required': email, member_type,
     * last_name, first_name, apply_date, start_date, ghp_amount. If the
     * admin's file is missing one of these columns entirely, every single
     * row would fail for the same reason — so that's reported once, up
     * front, instead of once per row.
     */
    private const REQUIRED_HEADERS = [
        'email', 'member_type', 'last_name', 'first_name', 'apply_date', 'start_date', 'ghp_amount',
    ];

    public function __construct(private readonly MemberCreationService $creator)
    {
    }

    /**
     * Parses and imports a CSV file, row by row. A row that fails
     * validation, or is a duplicate (within the file or against the
     * database), is skipped — recorded with a specific reason — and
     * processing continues with the rest of the file, rather than aborting
     * the whole import over one bad row.
     *
     * @return array{
     *     created: int,
     *     skipped: array<int, array{row: int, reason: string}>,
     *     total: int,
     *     truncated: bool,
     *     unknown_columns: array<int, string>,
     *     error: string|null,
     * }
     */
    public function import(UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'r');

        $header = fgetcsv($handle);

        if ($header === false) {
            fclose($handle);

            return $this->emptyReport();
        }

        // Strip a UTF-8 BOM from the first header cell if present. fgetcsv()
        // doesn't do this itself, and the template THIS FEATURE downloads
        // always has one (StreamsCsv writes it so Excel doesn't mangle
        // special characters) — without this, re-uploading that same
        // template unmodified would silently fail to map its first column
        // ("code" would never match, so every explicit code in the file
        // would be dropped in favor of auto-generation).
        if (isset($header[0])) {
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
        }

        $header = array_map(fn ($column) => strtolower(trim((string) $column)), $header);

        if (($missing = array_values(array_diff(self::REQUIRED_HEADERS, $header))) !== []) {
            fclose($handle);

            return $this->emptyReport(
                error: 'Missing required column(s): '.implode(', ', $missing)
                    .'. Please re-download the current sample template and fill in all required columns.'
            );
        }

        $unknownColumns = array_values(array_diff($header, self::TEMPLATE_HEADERS));

        $divisionsByName = Division::all()->keyBy(fn ($division) => strtolower($division->name));
        $departmentsByName = Department::all()->keyBy(fn ($department) => strtolower($department->name));

        $created = 0;
        $skipped = [];
        $rowNumber = 1; // the header line itself is row 1
        $dataRowCount = 0;
        $truncated = false;

        // CSV-internal duplicate tracking: normalized value -> the FIRST row
        // number it was seen on. Recorded the moment a value is read, before
        // that row's own validity is even known, so a later row claiming the
        // same email/code is always caught as a CSV duplicate (see
        // task.md §2.26, point 9 of the spec this was built from).
        $seenEmails = [];
        $seenCodes = [];

        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;

            // Rows made entirely of empty cells (trailing blank lines from
            // spreadsheet software, etc.) are just noise, not an error.
            if (count(array_filter($row, fn ($value) => trim((string) $value) !== '')) === 0) {
                continue;
            }

            $dataRowCount++;

            if ($dataRowCount > self::MAX_ROWS) {
                $truncated = true;
                break;
            }

            $data = $this->mapRow($header, $row, $divisionsByName, $departmentsByName);

            $reason = $this->checkCsvInternalDuplicate($data, $rowNumber, $seenEmails, $seenCodes);

            if ($reason === null) {
                $reason = $this->checkDatabaseDuplicate($data);
            }

            if ($reason === null) {
                $reason = $this->validateRow($data);
            }

            if ($reason !== null) {
                $skipped[] = ['row' => $rowNumber, 'reason' => $reason];

                continue;
            }

            $this->creator->create($data);
            $created++;
        }

        fclose($handle);

        return [
            'created' => $created,
            'skipped' => $skipped,
            'total' => $dataRowCount,
            'truncated' => $truncated,
            'unknown_columns' => $unknownColumns,
            'error' => null,
        ];
    }

    /**
     * @return array{created: int, skipped: array<int, mixed>, total: int, truncated: bool, unknown_columns: array<int, string>, error: string|null}
     */
    private function emptyReport(?string $error = null): array
    {
        return ['created' => 0, 'skipped' => [], 'total' => 0, 'truncated' => false, 'unknown_columns' => [], 'error' => $error];
    }

    /**
     * Maps one CSV row into data shaped exactly like StoreMemberRequest's
     * fields — including resolving division/department NAMES to ids here,
     * before validation, so 'division_id'/'department_id' can be checked by
     * the real 'exists:divisions,id' rule like any other submission. An
     * unmatched name doesn't reject the row — it's an optional field, same
     * as leaving it blank in the single-add form — it's just left null.
     *
     * @return array<string, mixed>
     */
    private function mapRow(array $header, array $row, $divisionsByName, $departmentsByName): array
    {
        $raw = [];

        foreach ($header as $index => $column) {
            $raw[$column] = trim((string) ($row[$index] ?? ''));
        }

        $get = fn (string $key): string => $raw[$key] ?? '';

        $divisionName = $get('division') !== '' ? $get('division') : null;
        $departmentName = $get('department') !== '' ? $get('department') : null;

        return [
            'code' => $get('code') !== '' ? $get('code') : null,
            'email' => $get('email'),
            'old_code' => $get('old_code') !== '' ? $get('old_code') : null,
            // Left as-is (including empty string) when unrecognized so the
            // 'required'/'in:0,1' validator rules can catch it — silently
            // defaulting an empty cell to "Employee" would defeat the point
            // of requiring this column.
            'member_type' => $this->mapChoice($get('member_type'), ['employee' => '0', 'agent' => '1']),
            'last_name' => $get('last_name'),
            'first_name' => $get('first_name'),
            'middle_name' => $get('middle_name') !== '' ? $get('middle_name') : null,
            'address' => $get('address') !== '' ? $get('address') : null,
            'birthdate' => $get('birthdate') !== '' ? $get('birthdate') : null,
            'civil_status' => $this->mapChoice($get('civil_status'), ['single' => '0', 'married' => '1'], allowEmpty: true),
            // Optional column: blank/unrecognized defaults to Active, same
            // as the single "Add member" form's default.
            'is_active' => ! in_array(strtolower($get('is_active')), ['0', 'false', 'no', 'inactive'], true),
            'division_id' => $divisionName ? optional($divisionsByName->get(strtolower($divisionName)))->id : null,
            'department_id' => $departmentName ? optional($departmentsByName->get(strtolower($departmentName)))->id : null,
            'coverage_year' => $get('coverage_year') !== '' ? $get('coverage_year') : null,
            'apply_date' => $get('apply_date') !== '' ? $get('apply_date') : null,
            'coverage_end_date' => $get('coverage_end_date') !== '' ? $get('coverage_end_date') : null,
            'start_date' => $get('start_date') !== '' ? $get('start_date') : null,
            'deduction_start_date' => $get('deduction_start_date') !== '' ? $get('deduction_start_date') : null,
            'ghp_amount' => $get('ghp_amount'),
            'remarks' => $get('remarks') !== '' ? $get('remarks') : null,
        ];
    }

    /**
     * Accepts either the friendly CSV text (e.g. "Employee") or the raw
     * stored value ("0") for a choice column. Anything else — including an
     * empty cell — passes through unchanged so validation can flag it
     * accurately rather than silently guessing.
     */
    private function mapChoice(string $value, array $wordsToValues, bool $allowEmpty = false): ?string
    {
        $normalized = strtolower($value);

        if ($normalized === '' && $allowEmpty) {
            return null;
        }

        if (isset($wordsToValues[$normalized])) {
            return $wordsToValues[$normalized];
        }

        if (in_array($value, $wordsToValues, true)) {
            return $value; // already "0"/"1"
        }

        return $allowEmpty ? null : $value; // invalid junk stays as-is so 'in:0,1' reports it
    }

    /**
     * Detects a row whose email or code was already seen earlier in THIS
     * same CSV upload. Checked before the database, and records every
     * email/code the moment it's read (regardless of whether this row ends
     * up valid) so a later duplicate is always caught against the FIRST
     * row it appeared on.
     */
    private function checkCsvInternalDuplicate(array $data, int $rowNumber, array &$seenEmails, array &$seenCodes): ?string
    {
        $email = $data['email'] !== '' ? strtolower($data['email']) : null;
        $code = $data['code'] !== null ? strtolower($data['code']) : null;

        if ($email !== null && isset($seenEmails[$email])) {
            return "Duplicate entry in uploaded CSV. This member is already listed in Row {$seenEmails[$email]} (email \"{$data['email']}\").";
        }

        if ($code !== null && isset($seenCodes[$code])) {
            return "Duplicate entry in uploaded CSV. This member is already listed in Row {$seenCodes[$code]} (code \"{$data['code']}\").";
        }

        if ($email !== null) {
            $seenEmails[$email] = $rowNumber;
        }

        if ($code !== null) {
            $seenCodes[$code] = $rowNumber;
        }

        return null;
    }

    /**
     * Checks the database for a member that already has this email or code
     * — case-insensitively for email, same as how a human would judge two
     * addresses "the same". Includes soft-deleted rows (Member uses
     * SoftDeletes) so a row can't resurrect a deleted member's identity
     * either, though in practice members are never deleted through the UI
     * (there is no destroy route) — this is a defensive extra, not the
     * common case. Reports the existing member's code and Active/Inactive
     * status so the admin immediately knows why (an inactive member is
     * still "already exists", not available to re-import — see
     * task.md §2.26, spec point 17).
     */
    private function checkDatabaseDuplicate(array $data): ?string
    {
        if ($data['email'] !== '') {
            $existing = Member::withTrashed()->whereRaw('LOWER(email) = ?', [strtolower($data['email'])])->first();

            if ($existing) {
                return "Duplicate member. Email \"{$data['email']}\" already exists in the database "
                    .'(member '.$existing->code.', status: '.$this->describeStatus($existing).').';
            }
        }

        if ($data['code'] !== null) {
            $existing = Member::withTrashed()->where('code', $data['code'])->first();

            if ($existing) {
                return "Member already exists. Code \"{$data['code']}\" is already registered "
                    .'(status: '.$this->describeStatus($existing).').';
            }
        }

        return null;
    }

    private function describeStatus(Member $member): string
    {
        if ($member->trashed()) {
            return 'Deleted';
        }

        return $member->is_active ? 'Active' : 'Inactive';
    }

    /**
     * Runs the row through a REAL StoreMemberRequest — the exact rules
     * (required fields, formats, the Benefit Setup cross-field checks in
     * ValidatesCoverageSetup) the manual Add Member form uses, built fresh
     * from this row's data rather than a separate hand-maintained copy.
     * ->route('member') inside ValidatesCoverageSetup correctly resolves to
     * null here (no route is bound to this manually-built request), which
     * is exactly what that trait treats as "Add Member" mode.
     *
     * The 'unique:members,email'/'unique:members,code' pieces of
     * StoreMemberRequest's own rules are left in as a defense-in-depth
     * backstop, but this method is only reached after
     * checkDatabaseDuplicate() already found nothing — so in the normal
     * case they never fire; the friendlier, row/status-aware message above
     * is what the admin actually sees for a real duplicate.
     */
    private function validateRow(array $data): ?string
    {
        $formRequest = StoreMemberRequest::create('/members', 'POST', $data);

        $validator = Validator::make($formRequest->all(), $formRequest->rules(), $formRequest->messages());
        $formRequest->withValidator($validator);

        if ($validator->fails()) {
            return $validator->errors()->first();
        }

        return null;
    }
}
