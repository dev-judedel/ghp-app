<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records that a Coverage Year History benefit period's claim was REVERTED.
 *
 * Why a migration is needed at all: the old Cancel action simply blanked
 * claimed_at / claimed_by, which left no trace on the period itself of who
 * undid the claim or when (only the dirty-field activity log line). Revert is
 * a recorded action — the period must be able to show "Reverted", and a
 * second Revert must be refusable ("No further revert is available") — and
 * neither can be derived from claimed_at being NULL, because a period that
 * was never claimed looks identical. So exactly two nullable columns:
 *
 *   reverted_at  when the claim was reverted (NULL = not reverted / claimed
 *                again since)
 *   reverted_by  which user did it (FK users, nullOnDelete)
 *
 * No status column is added: the lifecycle is still Unclaimed / Claimed
 * (claimed_at), with "reverted" as a recorded fact about an Unclaimed period.
 * Nothing is backfilled and no existing row changes: every existing period
 * keeps reverted_at = NULL. Nothing financial is stored here or read from
 * here — it is never part of any GHP calculation.
 *
 * Idempotent (hasColumn guards), like the other benefit_periods migrations.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('benefit_periods', 'reverted_at')) {
            Schema::table('benefit_periods', function (Blueprint $table) {
                $table->timestamp('reverted_at')->nullable()->after('claimed_by');
            });
        }

        if (! Schema::hasColumn('benefit_periods', 'reverted_by')) {
            Schema::table('benefit_periods', function (Blueprint $table) {
                $table->foreignId('reverted_by')->nullable()->after('reverted_at')
                    ->constrained('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('benefit_periods', 'reverted_by')) {
            Schema::table('benefit_periods', function (Blueprint $table) {
                $table->dropConstrainedForeignId('reverted_by');
            });
        }

        if (Schema::hasColumn('benefit_periods', 'reverted_at')) {
            Schema::table('benefit_periods', function (Blueprint $table) {
                $table->dropColumn('reverted_at');
            });
        }
    }
};
