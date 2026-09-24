<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Supports the administrator-controlled "Immediate Eligibility"
     * exception to the normal dependent waiting rule (see
     * App\Services\DependentEligibilityService::grantImmediate()).
     *
     * Granting immediate eligibility does NOT introduce a second
     * eligibility mechanism: it simply pulls the dependent's existing
     * eligibility_date forward to today, so Dependent::isGhpEligibleAsOf()
     * and BenefitAccrualService::resolveGhpAmount() pick it up through the
     * exact same code path as every other dependent. These two columns only
     * record THAT and BY WHOM the normal waiting rule was bypassed, which
     * is also what lets the UI show "Immediate Eligible" instead of plain
     * "Eligible".
     *
     * Both nullable and deliberately not backfilled — every existing
     * dependent was never granted an exception.
     */
    public function up(): void
    {
        Schema::table('dependents', function (Blueprint $table) {
            if (! Schema::hasColumn('dependents', 'immediate_eligibility_at')) {
                $table->timestamp('immediate_eligibility_at')->nullable()->after('eligibility_date');
            }

            if (! Schema::hasColumn('dependents', 'immediate_eligibility_by')) {
                $table->foreignId('immediate_eligibility_by')
                    ->nullable()
                    ->after('immediate_eligibility_at')
                    ->constrained('users')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('dependents', function (Blueprint $table) {
            if (Schema::hasColumn('dependents', 'immediate_eligibility_by')) {
                $table->dropConstrainedForeignId('immediate_eligibility_by');
            }

            if (Schema::hasColumn('dependents', 'immediate_eligibility_at')) {
                $table->dropColumn('immediate_eligibility_at');
            }
        });
    }
};
