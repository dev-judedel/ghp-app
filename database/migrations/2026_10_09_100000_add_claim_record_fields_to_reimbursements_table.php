<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reimbursement-level RECORDS (task.md §2.32).
 *
 * A coverage cycle keeps exactly ONE benefit_periods row (the GHP fund: amount,
 * used, available — unique per member + dates, unchanged). What becomes
 * individually claimable is each REIMBURSEMENT: one "Record #n" per filing under
 * its coverage year, each with its own Filed / Claimed / Reverted state. So the
 * claim state moves from the period (benefit_periods.claimed_at / reverted_at,
 * §2.28/§2.30 — columns left in place as history, no longer read or written) to
 * the reimbursement:
 *
 *   claimed_at / claimed_by    Claimed = claimed_at is set. A claimed record is
 *                              LOCKED: later GHP increases (Adjust GHP amount,
 *                              dependent becoming eligible) never cover its
 *                              excess, and it can't be edited or voided.
 *   reverted_at / reverted_by  the claim was reverted (record is Unclaimed again
 *                              and eligible for future covers; NULL once it is
 *                              claimed again).
 *   submission_token           a one-time id the File reimbursement form
 *                              generates per opening; UNIQUE, so a double click
 *                              or a re-sent request can never create two
 *                              records (the server also checks it under the
 *                              member row lock; the index is the backstop).
 *                              NULL for every existing reimbursement and for
 *                              requests that send none.
 *
 * BACKFILL — existing data keeps its meaning: every active reimbursement linked
 * to a period that was Claimed becomes Claimed (same time / user); every
 * active reimbursement linked to a period whose claim was Reverted becomes
 * Reverted. Voided reimbursements are not records you can claim and are left
 * alone. Reimbursements with no benefit_period_id can't be matched and stay
 * Filed. Nothing is deleted or recreated; no historical amount changes.
 *
 * Idempotent (hasColumn guards).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reimbursements', function (Blueprint $table) {
            if (! Schema::hasColumn('reimbursements', 'claimed_at')) {
                $table->timestamp('claimed_at')->nullable();
            }

            if (! Schema::hasColumn('reimbursements', 'claimed_by')) {
                $table->foreignId('claimed_by')->nullable()->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('reimbursements', 'reverted_at')) {
                $table->timestamp('reverted_at')->nullable();
            }

            if (! Schema::hasColumn('reimbursements', 'reverted_by')) {
                $table->foreignId('reverted_by')->nullable()->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('reimbursements', 'submission_token')) {
                $table->string('submission_token', 64)->nullable()->unique();
            }
        });

        if (Schema::hasColumn('benefit_periods', 'claimed_at')) {
            DB::table('benefit_periods')->whereNotNull('claimed_at')->orderBy('id')->each(function ($period) {
                DB::table('reimbursements')
                    ->where('benefit_period_id', $period->id)
                    ->where('is_voided', false)
                    ->whereNull('claimed_at')
                    ->update(['claimed_at' => $period->claimed_at, 'claimed_by' => $period->claimed_by]);
            });
        }

        if (Schema::hasColumn('benefit_periods', 'reverted_at')) {
            DB::table('benefit_periods')->whereNull('claimed_at')->whereNotNull('reverted_at')->orderBy('id')->each(function ($period) {
                DB::table('reimbursements')
                    ->where('benefit_period_id', $period->id)
                    ->where('is_voided', false)
                    ->whereNull('claimed_at')
                    ->whereNull('reverted_at')
                    ->update(['reverted_at' => $period->reverted_at, 'reverted_by' => $period->reverted_by]);
            });
        }
    }

    public function down(): void
    {
        Schema::table('reimbursements', function (Blueprint $table) {
            if (Schema::hasColumn('reimbursements', 'submission_token')) {
                $table->dropUnique(['submission_token']);
                $table->dropColumn('submission_token');
            }

            foreach (['claimed_by', 'reverted_by'] as $column) {
                if (Schema::hasColumn('reimbursements', $column)) {
                    $table->dropConstrainedForeignId($column);
                }
            }

            foreach (['claimed_at', 'reverted_at'] as $column) {
                if (Schema::hasColumn('reimbursements', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
