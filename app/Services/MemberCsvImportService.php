<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Division;
use App\Models\Member;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;

class MemberCsvImportService
{
    /**
     * Row-processing cap so a huge file can't tie up a request indefinitely.
     * Reported back in the import summary if hit.
     */
    private const MAX_ROWS = 5000;

    /**
     * Columns the downloadable template ships with. Only email, member_type,
     * last_name, first_name, and ghp_amount are actually required per row —
     * everything else here is optional (see validateRow()).
     */
    public const TEMPLATE_HEADERS = [
        'code', 'email', 'member_type', 'last_name', 'first_name', 'middle_name',
        'address', 'birthdate', 'civil_status', 'division', 'department',
        'apply_date', 'deduction_start_date', 'ghp_amount', 'old_code', 'is_active',
    ];

    /**
     * Parses and imports a CSV file, row by row. A row with any required
     * field empty (or otherwise invalid) is skipped — recorded with a
     * reason — and processing continues with the rest of the file, rather
     * than aborting the whole import over one bad row.
     *
     * @return array{created: int, skipped: array<int, array{row: int, reason: string}>, total: int, truncated: bool}
     */
    public function import(UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'r');

        $header = fgetcsv($handle);

        if ($header === false) {
            fclose($handle);

            return ['created' => 0, 'skipped' => [], 'total' => 0, 'truncated' => false];
        }

        $header = array_map(fn ($column) => strtolower(trim((string) $column)), $header);

        $divisionsByName = Division::all()->keyBy(fn ($division) => strtolower($division->name));
        $departmentsByName = Department::all()->keyBy(fn ($department) => strtolower($department->name));

        $created = 0;
        $skipped = [];
        $rowNumber = 1; // the header line itself is row 1
        $dataRowCount = 0;
        $truncated = false;

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

            $data = $this->mapRow($header, $row);

            $reason = $this->validateRow($data);

            if ($reason !== null) {
                $skipped[] = ['row' => $rowNumber, 'reason' => $reason];

                continue;
            }

            $this->createMember($data, $divisionsByName, $departmentsByName);
            $created++;
        }

        fclose($handle);

        return [
            'created' => $created,
            'skipped' => $skipped,
            'total' => $dataRowCount,
            'truncated' => $truncated,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapRow(array $header, array $row): array
    {
        $raw = [];

        foreach ($header as $index => $column) {
            $raw[$column] = trim((string) ($row[$index] ?? ''));
        }

        $get = fn (string $key): string => $raw[$key] ?? '';

        return [
            'code' => $get('code') !== '' ? $get('code') : null,
            'email' => $get('email'),
            // Left as-is (including empty string) when unrecognized so the
            // 'required'/'in:0,1' validator rules below can catch it —
            // silently defaulting an empty cell to "Employee" would defeat
            // the point of requiring this column.
            'member_type' => $this->mapChoice($get('member_type'), ['employee' => '0', 'agent' => '1']),
            'last_name' => $get('last_name'),
            'first_name' => $get('first_name'),
            'middle_name' => $get('middle_name') !== '' ? $get('middle_name') : null,
            'address' => $get('address') !== '' ? $get('address') : null,
            'birthdate' => $get('birthdate') !== '' ? $get('birthdate') : null,
            'civil_status' => $this->mapChoice($get('civil_status'), ['single' => '0', 'married' => '1'], allowEmpty: true),
            'apply_date' => $get('apply_date') !== '' ? $get('apply_date') : null,
            'deduction_start_date' => $get('deduction_start_date') !== '' ? $get('deduction_start_date') : null,
            'ghp_amount' => $get('ghp_amount'),
            'old_code' => $get('old_code') !== '' ? $get('old_code') : null,
            // Optional column: blank/unrecognized defaults to Active, same
            // as the single "Add member" form's default.
            'is_active' => ! in_array(strtolower($get('is_active')), ['0', 'false', 'no', 'inactive'], true),
            'division_name' => $get('division') !== '' ? $get('division') : null,
            'department_name' => $get('department') !== '' ? $get('department') : null,
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

    private function validateRow(array $data): ?string
    {
        $validator = Validator::make($data, [
            'email' => ['required', 'email', 'max:255', 'unique:members,email'],
            'member_type' => ['required', 'in:0,1'],
            'last_name' => ['required', 'string', 'max:255'],
            'first_name' => ['required', 'string', 'max:255'],
            'ghp_amount' => ['required', 'numeric', 'min:0'],
            'code' => ['nullable', 'string', 'max:50', 'unique:members,code'],
        ], [
            'member_type.required' => 'member_type is required (Employee or Agent).',
            'member_type.in' => 'member_type must be "Employee" or "Agent".',
        ]);

        if ($validator->fails()) {
            return $validator->errors()->first();
        }

        return null;
    }

    private function createMember(array $data, $divisionsByName, $departmentsByName): Member
    {
        // An unmatched division/department name doesn't reject the row —
        // it's an optional field, so the member is still imported, just
        // left unassigned (same as leaving it blank in the single-add form).
        $divisionId = $data['division_name']
            ? optional($divisionsByName->get(strtolower($data['division_name'])))->id
            : null;

        $departmentId = $data['department_name']
            ? optional($departmentsByName->get(strtolower($data['department_name'])))->id
            : null;

        return Member::create([
            'code' => $data['code'] ?? Member::generateUniqueCode(),
            'email' => $data['email'],
            'member_type' => (int) $data['member_type'],
            'last_name' => $data['last_name'],
            'first_name' => $data['first_name'],
            'middle_name' => $data['middle_name'],
            'address' => $data['address'],
            'birthdate' => $data['birthdate'],
            'civil_status' => $data['civil_status'] !== null ? (int) $data['civil_status'] : null,
            'apply_date' => $data['apply_date'],
            'deduction_start_date' => $data['deduction_start_date'],
            'ghp_amount' => $data['ghp_amount'],
            'old_code' => $data['old_code'],
            'is_active' => $data['is_active'],
            'division_id' => $divisionId,
            'department_id' => $departmentId,
        ]);
    }
}
