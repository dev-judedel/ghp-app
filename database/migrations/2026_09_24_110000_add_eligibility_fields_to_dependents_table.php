<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Supports the "dependent added mid-cycle doesn't raise the GHP amount
     * until the NEXT benefit period" rule (see BenefitAccrualService::
     * resolveDependentEligibilityDate() / Dependent::isGhpEligibleAsOf()).
     *
     * Both columns are nullable and, deliberately, NOT backfilled for
     * existing rows. A NULL eligibility_date means "not time-gated" —
     * Dependent::isGhpEligibleAsOf() treats that as always-eligible
     * (subject only to the existing age/relation rule), which is exactly
     * today's behavior. That keeps every dependent already on file (and
     * any dependent created directly via Eloquent — factories, tests,
     * ImportLegacyGhpData) behaving exactly as before. Only the real
     * "Add dependent" flow (DependentController::store()) sets
     * eligibility_date going forward, so the new pending-until-next-cycle
     * rule only ever applies to dependents added through the app from now
     * on — never retroactively to what's already on file.
     */
    public function up(): void
    {
        Schema::table('dependents', function (Blueprint $table) {
            $table->date('date_added')->nullable()->after('birthdate');
            $table->date('eligibility_date')->nullable()->after('date_added');
        });
    }

    public function down(): void
    {
        Schema::table('dependents', function (Blueprint $table) {
            $table->dropColumn(['date_added', 'eligibility_date']);
        });
    }
};
