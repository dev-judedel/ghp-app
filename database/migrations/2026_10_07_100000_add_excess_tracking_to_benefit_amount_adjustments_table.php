<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lets a GHP amount adjustment cover outstanding Excess GHP the exact
     * same way a dependent-driven increase already does (see
     * BenefitAccrualService::applyFundIncreaseToExcess()), and lets that
     * specific adjustment's effect be reverted later without guessing —
     * see the excess_coverages table (next migration) for the per-claim
     * ledger this is built on top of.
     *
     * All nullable and left NULL for every existing row: nothing here is
     * backfilled, so no adjustment that already happened is reinterpreted
     * as having covered anything it didn't actually touch at the time.
     *
     *   kind                      'adjustment' (an override was set) or
     *                             'revert' (the override was undone) — lets
     *                             the existing history table tell the two
     *                             apart without a second table.
     *   increase_amount           the actual fund INCREASE this adjustment
     *                             caused (BenefitAccrualService::
     *                             fundIncrease() — same-months-rendered,
     *                             new rate minus old rate; NOT new_amount -
     *                             old_amount). NULL when no benefit period
     *                             was generated yet to measure an increase
     *                             against (adjusting ghp_amount before
     *                             "Generate" is clicked still works; there's
     *                             just nothing to refresh/cover yet).
     *   outstanding_excess_before the member's total outstanding Excess GHP
     *                             (sum of reimbursements.excess_amount for
     *                             the current coverage period) at the
     *                             moment this adjustment was applied —
     *                             captured before anything changes, so a
     *                             later revert knows exactly what to
     *                             restore even if other things happen
     *                             in between.
     *   excess_covered_amount     how much of increase_amount went to
     *                             outstanding excess (mirrors the SUM of
     *                             this row's excess_coverages, kept
     *                             denormalized here for a cheap read on
     *                             the history table).
     *   excess_remaining_after    outstanding_excess_before - excess_covered_amount.
     *   available_increase_amount increase_amount - excess_covered_amount
     *                             — the part that went to Available GHP
     *                             instead.
     *   reverted_at / reverted_by who undid this adjustment and when. An
     *                             adjustment row (kind='adjustment') with
     *                             reverted_at still NULL is the one
     *                             "Revert to automatic" acts on.
     */
    public function up(): void
    {
        Schema::table('benefit_amount_adjustments', function (Blueprint $table) {
            $table->string('kind')->default('adjustment')->after('member_id');
            $table->decimal('increase_amount', 12, 2)->nullable()->after('new_amount');
            $table->decimal('outstanding_excess_before', 12, 2)->nullable()->after('increase_amount');
            $table->decimal('excess_covered_amount', 12, 2)->nullable()->after('outstanding_excess_before');
            $table->decimal('excess_remaining_after', 12, 2)->nullable()->after('excess_covered_amount');
            $table->decimal('available_increase_amount', 12, 2)->nullable()->after('excess_remaining_after');
            $table->timestamp('reverted_at')->nullable()->after('recorded_by');
            $table->foreignId('reverted_by')->nullable()->after('reverted_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('benefit_amount_adjustments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reverted_by');
            $table->dropColumn([
                'kind', 'increase_amount', 'outstanding_excess_before',
                'excess_covered_amount', 'excess_remaining_after',
                'available_increase_amount', 'reverted_at',
            ]);
        });
    }
};
