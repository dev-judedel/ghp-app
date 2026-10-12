<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data-only follow-up to 2026_10_07_100000_add_excess_tracking_to_benefit_amount_adjustments_table.
 *
 * That migration adds benefit_amount_adjustments.kind with DEFAULT 'adjustment',
 * so every row written BEFORE it exists would be labelled an adjustment —
 * including the old "Reverted to automatic (...)" history rows, which would then
 * show as still-Active adjustments in the history table. This relabels those
 * rows as 'revert' and marks the member's earlier, never-reverted adjustments
 * as reverted (that is exactly what the revert did to the amount back then).
 *
 * Nothing is deleted and no amount/excess figure is touched; legacy rows keep
 * NULL increase/excess columns, i.e. they never claim to have covered anything.
 * Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('benefit_amount_adjustments')
            ->where('reason', 'like', 'Reverted to automatic%')
            ->where('kind', '!=', 'revert')
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
        // Intentionally empty: the relabelling is not worth undoing, and the
        // columns themselves are dropped by the migration that added them.
    }
};
