<?php

namespace App\Console\Commands;

use App\Models\AgentPosition;
use App\Models\BenefitAmountAdjustment;
use App\Models\BenefitLedger;
use App\Models\BenefitPeriod;
use App\Models\Dependent;
use App\Models\Department;
use App\Models\Division;
use App\Models\Member;
use App\Models\Reimbursement;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Imports data from the restored legacy GHP database (see the "legacy"
 * connection in config/database.php) into the new normalized schema.
 *
 * Prerequisite:
 *   createdb -U postgres ghp_legacy
 *   psql -U postgres -d ghp_legacy -f GHPDB_20260723.sql
 *
 * Usage:
 *   php artisan ghp:import-legacy
 *   php artisan ghp:import-legacy --fresh   (truncates target tables first)
 */
class ImportLegacyGhpData extends Command
{
    protected $signature = 'ghp:import-legacy {--fresh : Truncate target tables before importing}';

    protected $description = 'Import data from the restored legacy GHP Postgres database into the new schema';

    /** @var array<string, int> legacy member code => new members.id */
    private array $memberIdByCode = [];

    /** @var array<string, int> "memberType|divisionName" => divisions.id */
    private array $divisionIdByKey = [];

    /** @var array<string, int> department name => departments.id */
    private array $departmentIdByName = [];

    public function handle(): int
    {
        if (! $this->confirmLegacyConnection()) {
            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            $this->truncateTargetTables();
        }

        DB::transaction(function () {
            $this->importDivisions();
            $this->importDepartments();
            $this->importMembers();
            $this->importDependents();
            $this->importBenefitPeriods();
            $this->importBenefitLedger();
            $this->importReimbursements();
            $this->importAgentPositions();
            $this->extractAmountAdjustmentsFromRemarks();
        });

        $this->newLine();
        $this->info('Import complete.');
        $this->warn('Review the "remarks" fields on members and the auto-extracted '
            .'benefit_amount_adjustments rows manually — the extraction is best-effort '
            .'text parsing, not authoritative. See migration analysis doc §2.3.');

        return self::SUCCESS;
    }

    private function confirmLegacyConnection(): bool
    {
        try {
            DB::connection('legacy')->getPdo();
        } catch (\Throwable $e) {
            $this->error('Could not connect to the "legacy" database connection.');
            $this->line('Make sure you restored the dump first:');
            $this->line('  createdb -U postgres ghp_legacy');
            $this->line('  psql -U postgres -d ghp_legacy -f GHPDB_20260723.sql');
            $this->line('And that LEGACY_DB_DATABASE is set correctly in .env.');
            $this->line('Underlying error: '.$e->getMessage());

            return false;
        }

        return true;
    }

    private function truncateTargetTables(): void
    {
        $this->warn('--fresh: truncating target tables...');

        DB::statement('TRUNCATE TABLE benefit_amount_adjustments, reimbursements, benefit_ledger, benefit_periods, dependents, agent_positions, members, departments, divisions RESTART IDENTITY CASCADE');
    }

    private function importDivisions(): void
    {
        $this->info('Importing divisions...');

        $rows = DB::connection('legacy')->table('t_division')->get();

        foreach ($rows as $row) {
            $memberType = $this->mapMemberTypeText($row->c_mtype);

            $division = Division::firstOrCreate([
                'member_type' => $memberType,
                'name' => trim($row->c_division),
            ]);

            $this->divisionIdByKey[$memberType.'|'.trim($row->c_division)] = $division->id;
        }

        $this->line('  '.count($this->divisionIdByKey).' divisions imported.');
    }

    private function importDepartments(): void
    {
        $this->info('Importing departments...');

        $names = DB::connection('legacy')->table('t_member')
            ->whereNotNull('c_department')
            ->where('c_department', '!=', '')
            ->distinct()
            ->pluck('c_department');

        foreach ($names as $name) {
            $department = Department::firstOrCreate(['name' => trim($name)]);
            $this->departmentIdByName[trim($name)] = $department->id;
        }

        $this->line('  '.count($this->departmentIdByName).' departments imported.');
    }

    private function importMembers(): void
    {
        $this->info('Importing members...');

        $rows = DB::connection('legacy')->table('t_member')->orderBy('c_code')->get();
        $bar = $this->output->createProgressBar($rows->count());

        foreach ($rows as $row) {
            $divisionId = $this->divisionIdByKey[$row->c_mem_type.'|'.trim((string) $row->c_division)] ?? null;
            $departmentId = $this->departmentIdByName[trim((string) $row->c_department)] ?? null;

            $member = Member::create([
                'code' => $row->c_code,
                'member_type' => $row->c_mem_type,
                'last_name' => $row->c_last_name,
                'first_name' => $row->c_first_name,
                'middle_name' => $row->c_middle_name,
                'address' => $row->c_address,
                'birthdate' => $row->c_birthdate,
                'civil_status' => $row->c_civil_stat,
                'apply_date' => $row->c_apply_date,
                'deduction_start_date' => $row->c_ded_start,
                'ghp_amount' => $row->c_ghp_amt,
                'remarks' => $row->c_remarks,
                'division_id' => $divisionId,
                'department_id' => $departmentId,
                'old_code' => $row->c_old_code,
            ]);

            $this->memberIdByCode[$row->c_code] = $member->id;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->line('  '.count($this->memberIdByCode).' members imported.');
    }

    private function importDependents(): void
    {
        $this->info('Importing dependents...');

        $rows = DB::connection('legacy')->table('t_dependents')->get();
        $imported = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $memberId = $this->memberIdByCode[$row->c_code] ?? null;

            if ($memberId === null) {
                $skipped++;

                continue;
            }

            Dependent::create([
                'member_id' => $memberId,
                'name' => $row->c_name,
                'relation' => $row->c_relation,
                'birthdate' => $row->c_birthdate,
            ]);

            $imported++;
        }

        $this->line("  {$imported} dependents imported".($skipped ? ", {$skipped} skipped (no matching member code)" : '').'.');
    }

    private function importBenefitPeriods(): void
    {
        $this->info('Importing benefit periods (was t_process)...');

        $rows = DB::connection('legacy')->table('t_process')->get();
        $imported = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $memberId = $this->memberIdByCode[$row->c_code] ?? null;

            if ($memberId === null) {
                $skipped++;

                continue;
            }

            $divisionId = $this->divisionIdByKey[$row->c_mem_type.'|'.trim((string) $row->c_division)] ?? null;
            $departmentId = $this->departmentIdByName[trim((string) $row->c_department)] ?? null;

            BenefitPeriod::firstOrCreate([
                'member_id' => $memberId,
                'from_date' => $row->c_from_date,
                'to_date' => $row->c_to_date,
            ], [
                'ghp_amount' => $row->c_ghp_amt,
                'ghp_available' => $row->c_ghp_avail,
                'ghp_used' => $row->c_ghp_used,
                'member_type' => $row->c_mem_type,
                'remarks' => $row->c_remarks,
                'division_id' => $divisionId,
                'department_id' => $departmentId,
            ]);

            $imported++;
        }

        $this->line("  {$imported} benefit periods imported".($skipped ? ", {$skipped} skipped (no matching member code)" : '').'.');
    }

    private function importBenefitLedger(): void
    {
        $this->info('Importing benefit ledger (was t_avail_used_ghp)...');

        $rows = DB::connection('legacy')->table('t_avail_used_ghp')->get();
        $imported = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $memberId = $this->memberIdByCode[$row->c_code] ?? null;

            if ($memberId === null) {
                $skipped++;

                continue;
            }

            BenefitLedger::firstOrCreate([
                'member_id' => $memberId,
                'from_date' => $row->c_from_date,
                'to_date' => $row->c_to_date,
            ], [
                'ghp_amount' => $row->c_ghp_amt,
                'available_amount' => $row->c_avail_ghp,
                'used_amount' => $row->c_used_ghp,
            ]);

            $imported++;
        }

        $this->line("  {$imported} benefit ledger rows imported".($skipped ? ", {$skipped} skipped (no matching member code)" : '').'.');
    }

    private function importReimbursements(): void
    {
        $this->info('Importing reimbursements...');

        $rows = DB::connection('legacy')->table('t_reimbursement')->get();
        $imported = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $memberId = $this->memberIdByCode[$row->c_code] ?? null;

            if ($memberId === null) {
                $skipped++;

                continue;
            }

            Reimbursement::create([
                'member_id' => $memberId,
                'or_no' => $row->c_or_no,
                'or_date' => $row->c_or_date,
                'or_amount' => $row->c_or_amt,
                'hospital_name' => $row->c_hospital_name,
                'description' => $row->c_description,
                'remarks' => $row->c_remarks,
            ]);

            $imported++;
        }

        $this->line("  {$imported} reimbursements imported".($skipped ? ", {$skipped} skipped (no matching member code)" : '').'.');
    }

    private function importAgentPositions(): void
    {
        $this->info('Importing agent positions (was t_avp_vp)...');

        $rows = DB::connection('legacy')->table('t_avp_vp')->get();

        foreach ($rows as $row) {
            AgentPosition::create([
                'member_code' => $row->c_code,
                'name' => $row->c_name,
                'position' => $row->c_position,
            ]);
        }

        $this->line('  '.$rows->count().' agent positions imported.');
    }

    /**
     * Best-effort extraction of structured amount-change history from the
     * free-text member remarks, e.g.:
     *   "GHP amount changed from 4200 to 26775 with IT request form dtd 2019-05-21"
     * This is NOT authoritative — flag for manual review, per migration
     * analysis doc §2.3. Rows that don't match the pattern are simply skipped
     * (the original text remains intact on members.remarks either way).
     */
    private function extractAmountAdjustmentsFromRemarks(): void
    {
        $this->info('Best-effort extraction of amount-change history from remarks...');

        $pattern = '/changed\s+from\s+([\d,]+(?:\.\d+)?)\s+to\s+([\d,]+(?:\.\d+)?)'
            .'(?:.*?(?:dtd|dated)\s+(\d{4}-\d{2}-\d{2}))?'
            .'(?:.*?(?:from|via)\s+(IT (?:request form|email)))?/i';

        $extracted = 0;

        foreach (Member::whereNotNull('remarks')->where('remarks', '!=', '')->get() as $member) {
            if (preg_match_all($pattern, $member->remarks, $matches, PREG_SET_ORDER) === false) {
                continue;
            }

            foreach ($matches as $match) {
                BenefitAmountAdjustment::create([
                    'member_id' => $member->id,
                    'old_amount' => str_replace(',', '', $match[1]),
                    'new_amount' => str_replace(',', '', $match[2]),
                    'reason' => 'Auto-extracted from legacy remarks — needs manual review',
                    'request_reference' => $match[4] ?? null,
                    'requested_at' => $match[3] ?? null,
                    'recorded_by' => null,
                ]);

                $extracted++;
            }
        }

        $this->line("  {$extracted} amount-adjustment rows extracted (needs manual review).");
    }

    private function mapMemberTypeText(string $text): int
    {
        return strtolower(trim($text)) === 'agent' ? Member::MEMBER_TYPE_AGENT : Member::MEMBER_TYPE_EMPLOYEE;
    }
}
