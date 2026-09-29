<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Makes the GHP coverage cycle configurable per member instead of being
     * hardcoded by member type (Employees Apr-Mar / Agents Jun-May).
     *
     * members.coverage_year      the year the member's GHP cycle begins (e.g. 2026)
     * members.coverage_end_date  the last day of a configured cycle. Together with
     *                            the 12-month cycle length it fixes where every
     *                            cycle starts: start = end + 1 day - 1 year, and
     *                            later/earlier cycles are simply +/- whole years.
     *
     * Both NULLABLE and left NULL for every existing member, which keeps the
     * old member-type cycle for them (see BenefitAccrualService::
     * coveragePeriod()) — no existing member or benefit period changes meaning.
     *
     * apply_date, deduction_start_date and ghp_amount already exist on
     * members and are reused as-is (no duplicate columns).
     *
     * benefit_periods.coverage_year labels each generated record with the
     * coverage year it belongs to. Backfilled from from_date for existing
     * rows (their cycles all start in the coverage year's own year), in
     * chunks so it is portable across database drivers. Nothing else in
     * benefit_periods is touched and no row is deleted.
     */
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->unsignedSmallInteger('coverage_year')->nullable()->after('apply_date');
            $table->date('coverage_end_date')->nullable()->after('coverage_year');
        });

        Schema::table('benefit_periods', function (Blueprint $table) {
            $table->unsignedSmallInteger('coverage_year')->nullable()->after('to_date');
        });

        DB::table('benefit_periods')->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $row) {
                DB::table('benefit_periods')
                    ->where('id', $row->id)
                    ->update(['coverage_year' => (int) substr((string) $row->from_date, 0, 4)]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('benefit_periods', function (Blueprint $table) {
            $table->dropColumn('coverage_year');
        });

        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn(['coverage_year', 'coverage_end_date']);
        });
    }
};
