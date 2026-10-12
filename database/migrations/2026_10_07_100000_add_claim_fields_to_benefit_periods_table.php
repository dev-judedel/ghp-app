<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Claimed / Unclaimed status for a Coverage Year History benefit period.
 *
 * "Claimed" is simply "claimed_at is not NULL" — there is no separate status
 * column that could disagree with it. Every existing row starts NULL, i.e.
 * Unclaimed; nothing is backfilled and no GHP figure is touched.
 * claimed_by records which administrator pressed Claim.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('benefit_periods', 'claimed_at')) {
            Schema::table('benefit_periods', function (Blueprint $table) {
                $table->timestamp('claimed_at')->nullable()->after('ghp_available');
            });
        }

        if (! Schema::hasColumn('benefit_periods', 'claimed_by')) {
            Schema::table('benefit_periods', function (Blueprint $table) {
                $table->foreignId('claimed_by')->nullable()->after('claimed_at')
                    ->constrained('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('benefit_periods', 'claimed_by')) {
            Schema::table('benefit_periods', function (Blueprint $table) {
                $table->dropConstrainedForeignId('claimed_by');
            });
        }

        if (Schema::hasColumn('benefit_periods', 'claimed_at')) {
            Schema::table('benefit_periods', function (Blueprint $table) {
                $table->dropColumn('claimed_at');
            });
        }
    }
};
