<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GHP amount adjustments cover outstanding excess GHP, and can be reversed.
 *
 * 1) benefit_amount_adjustments gains what each adjustment actually did, so it
 *    can be shown and reversed on its own:
 *      kind                       'adjustment' | 'revert' (the "reverted" history rows)
 *      increase_amount            growth of the benefit fund it caused (months rendered x rate change)
 *      outstanding_excess_before  excess GHP outstanding when it was applied
 *      excess_covered_amount      part of that the increase covered
 *      excess_remaining_after     outstanding excess afterwards
 *      available_increase_amount  part of the increase NOT needed for excess (went to Available GHP)
 *      reverted_at / reverted_by  set when this adjustment has been reversed
 *
 * 2) excess_coverages is the ledger: one row per (adjustment, reimbursement) for
 *    the exact amount that adjustment covered. A revert restores precisely those
 *    rows — it never guesses "original = current + adjustment" from balances,
 *    so later claims, other adjustments or edits can't corrupt it.
 *
 * Existing rows keep their meaning: default kind = 'adjustment', all new amounts 0.
 * Legacy "Reverted to automatic" rows are re-labelled 'revert' and every older
 * adjustment of that member is marked reverted, so only genuinely active
 * adjustments can be reverted later. Nothing is deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('benefit_amount_adjustments', function (Blueprint $table) {
            if (! Schema::hasColumn('benefit_amount_adjustments', 'kind')) {
                $table->string('kind', 20)->default('adjustment');
            }
            foreach (['increase_amount', 'outstanding_excess_before', 'excess_covered_amount', 'excess_remaining_after', 'available_increase_amount'] as $column) {
                if (! Schema::hasColumn('benefit_amount_adjustments', $column)) {
                    $table->decimal($column, 12, 2)->default(0);
                }
            }
            if (! Schema::hasColumn('benefit_amount_adjustments', 'reverted_at')) {
                $table->timestamp('reverted_at')->nullable();
            }
        });

        if (! Schema::hasColumn('benefit_amount_adjustments', 'reverted_by')) {
            Schema::table('benefit_amount_adjustments', function (Blueprint $table) {
                $table->foreignId('reverted_by')->nullable()->constrained('users')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('excess_coverages')) {
            Schema::create('excess_coverages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('member_id')->constrained('members')->restrictOnDelete();
                $table->foreignId('reimbursement_id')->constrained('reimbursements')->restrictOnDelete();
                $table->foreignId('benefit_amount_adjustment_id')->constrained('benefit_amount_adjustments')->restrictOnDelete();
                $table->decimal('amount', 12, 2);
                $table->timestamp('reversed_at')->nullable();
                $table->timestamps();

                $table->index(['benefit_amount_adjustment_id', 'reversed_at'], 'excess_coverages_adjustment_reversed_idx');
            });
        }

        // Legacy backfill (no data is removed).
        DB::table('benefit_amount_adjustments')
            ->where('reason', 'like', 'Reverted to automatic%')
            ->update(['kind' => 'revert']);

        $reverts = DB::table('benefit_amount_adjustments')->where('kind', 'revert')->get(['id', 'member_id']);

        foreach ($reverts as $revert) {
            DB::table('benefit_amount_adjustments')
                ->where('member_id', $revert->member_id)
                ->where('kind', 'adjustment')
                ->where('id', '<', $revert->id)
                ->whereNull('reverted_at')
                ->update(['reverted_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('excess_coverages');

        if (Schema::hasColumn('benefit_amount_adjustments', 'reverted_by')) {
            Schema::table('benefit_amount_adjustments', function (Blueprint $table) {
                $table->dropConstrainedForeignId('reverted_by');
            });
        }

        Schema::table('benefit_amount_adjustments', function (Blueprint $table) {
            foreach (['reverted_at', 'available_increase_amount', 'excess_remaining_after', 'excess_covered_amount', 'outstanding_excess_before', 'increase_amount', 'kind'] as $column) {
                if (Schema::hasColumn('benefit_amount_adjustments', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
