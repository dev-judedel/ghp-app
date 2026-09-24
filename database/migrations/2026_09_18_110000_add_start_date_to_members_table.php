<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Distinct from both apply_date (when the GHP application/cycle applies
     * from, defaults to the cycle's own start, admin-editable) and
     * deduction_start_date (now always auto-computed as the first day of
     * the month AFTER start_date — see BenefitAccrualService::
     * resolveDeductionStartDate()). start_date is simply "when the member
     * started/was added" — nullable here since existing members predate
     * this field, but required going forward via StoreMemberRequest.
     */
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->date('start_date')->nullable()->after('apply_date');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn('start_date');
        });
    }
};
